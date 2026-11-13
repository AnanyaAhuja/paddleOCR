<?php

namespace Tests\Feature;

use App\Documents\Checks\IdCardChecks;
use App\Documents\DocumentExtractor;
use App\Jobs\ProcessDocumentExtraction;
use App\Models\DocumentExtraction;
use App\Services\JevJudge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentReviewTest extends TestCase
{
    use RefreshDatabase;

    private function image(): string
    {
        return UploadedFile::fake()->image('x.jpg', 300, 200)->getContent();
    }

    public function test_import_stores_one_row_per_case_and_pairs_front_and_back(): void
    {
        Storage::fake('local');
        Queue::fake();
        $disk = Storage::disk('local');
        $disk->put('docs/01-01-2026/100/Rossi fronte.jpeg', $this->image());
        $disk->put('docs/01-01-2026/100/Rossi retro .jpeg', $this->image());
        $disk->put('docs/01-01-2026/101/Bianchi.jpeg', $this->image());          // one scanned page
        $disk->put('docs/01-01-2026/102/Verdi retro.jpeg', $this->image());
        $disk->put('docs/01-01-2026/102/Verdi.jpeg', $this->image());            // unlabelled = front

        $this->artisan('documents:import', ['directory' => 'docs'])->assertSuccessful();

        $rows = DocumentExtraction::orderBy('case_number')->get();
        $this->assertSame(['100', '101', '102'], $rows->pluck('case_number')->all());
        $this->assertStringEndsWith('Rossi fronte.jpeg', $rows[0]->front_path);
        $this->assertStringEndsWith('Rossi retro .jpeg', $rows[0]->back_path);
        $this->assertNull($rows[1]->back_path);
        $this->assertStringEndsWith('Verdi.jpeg', $rows[2]->front_path);
        Queue::assertPushed(ProcessDocumentExtraction::class, 3);

        // Running it again re-queues only failed cases: done ones are kept and
        // pending ones are already on the queue.
        $rows[0]->update(['status' => 'done']);
        $rows[1]->update(['status' => 'failed']);
        Queue::fake();
        $this->artisan('documents:import', ['directory' => 'docs'])->assertSuccessful();
        Queue::assertPushed(ProcessDocumentExtraction::class, 1);

        Queue::fake();
        $this->artisan('documents:import', ['directory' => 'docs', '--reprocess' => true])->assertSuccessful();
        Queue::assertPushed(ProcessDocumentExtraction::class, 3);
    }

    public function test_processing_stores_fields_checks_and_judge_scores(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('docs/b/1/front.jpg', $this->image());
        $row = DocumentExtraction::create(['batch' => 'b', 'case_number' => '1', 'document_type' => 'it_id_card', 'front_path' => 'docs/b/1/front.jpg']);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'ocr:8000')) {
                return Http::response(['text' => "CODICE FISCALE\nRSSMRA85T10A562S", 'lines' => [], 'confidence' => 0.98, 'orientation_retried' => false]);
            }
            if ($request['model'] === config('documents.judge.model')) {
                // JEV: very sure about everything except the surname.
                $sure = ! str_contains(json_encode($request['messages']), 'COGNOME');

                return Http::response(['choices' => [['message' => ['content' => $sure ? ' B' : ' A'],
                    'logprobs' => ['content' => [['top_logprobs' => [['token' => ' B', 'logprob' => log($sure ? 0.97 : 0.2)], ['token' => ' A', 'logprob' => log($sure ? 0.03 : 0.8)]]]]]]]]);
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode([
                'surname' => 'ROSSI', 'given_names' => 'MARIO', 'date_of_birth' => ['day' => 10, 'month' => 12, 'year' => 1985],
                'sex' => 'M', 'fiscal_code' => 'RSSMRA85T10A562S', 'height_cm' => 178,
            ])]]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]);
        });

        (new ProcessDocumentExtraction($row->id))->handle(
            app(DocumentExtractor::class), app(IdCardChecks::class), app(JevJudge::class),
        );

        $row->refresh();
        $this->assertSame('done', $row->status);
        $this->assertSame('1985-12-10', $row->fields['date_of_birth']);
        $this->assertTrue($row->checks['fiscal_code']['ok']);
        $this->assertTrue($row->checks['date_of_birth']['ok']);
        // Fields an exact rule verified are not sent to the judge.
        $this->assertContains('surname', $row->judge['skipped']);
        $this->assertArrayNotHasKey('surname', $row->judge['fields']);
        $this->assertEqualsWithDelta(0.97, $row->judge['fields']['height_cm']['p_true'], 0.001);

        // The fiscal code confirms the surname, so the rule wins over the judge's doubt.
        $statuses = $row->fieldStatuses();
        $this->assertSame('verified', $statuses['surname']);
        // Height has no rule check, so the judge's 97% decides.
        $this->assertSame('confident', $statuses['height_cm']);
        // Nothing extracted, nothing to judge.
        $this->assertSame('unchecked', $statuses['place_of_birth']);
    }

    public function test_review_saves_verdicts_moves_on_and_feeds_the_summary(): void
    {
        $fields = array_fill_keys(array_keys(config('documents.types.it_id_card.fields')), 'X');
        $judge = ['fields' => array_map(fn () => ['p_true' => 0.95], $fields)];
        $judge['fields']['surname']['p_true'] = 0.1;
        $first = DocumentExtraction::create(['batch' => 'b', 'case_number' => '1', 'document_type' => 'it_id_card', 'front_path' => 'f', 'status' => 'done', 'fields' => $fields, 'judge' => $judge]);
        $second = DocumentExtraction::create(['batch' => 'b', 'case_number' => '2', 'document_type' => 'it_id_card', 'front_path' => 'f', 'status' => 'done', 'fields' => $fields]);

        $this->get(route('reviews.index'))->assertOk()->assertSee('b / 1');
        $this->get(route('reviews.show', $first))->assertOk()->assertSee('flagged');

        $review = array_map(fn () => ['verdict' => 'correct'], $fields);
        $review['surname'] = ['verdict' => 'wrong', 'corrected' => 'ROSSI'];

        $this->post(route('reviews.update', $first), ['review' => $review])->assertRedirect(route('reviews.show', $second));

        $first->refresh();
        $this->assertNotNull($first->reviewed_at);
        $this->assertSame('ROSSI', $first->review['surname']['corrected']);

        $this->get(route('reviews.summary'))->assertOk()
            ->assertSee('documents reviewed')
            ->assertSee('JEV judge');
    }

    public function test_review_requires_a_verdict_for_every_field(): void
    {
        $row = DocumentExtraction::create(['batch' => 'b', 'case_number' => '1', 'document_type' => 'it_id_card', 'front_path' => 'f', 'status' => 'done', 'fields' => []]);

        $this->post(route('reviews.update', $row), ['review' => ['surname' => ['verdict' => 'correct']]])
            ->assertSessionHasErrors('review');
        $this->assertNull($row->refresh()->reviewed_at);
    }

    public function test_images_are_served_only_for_known_sides(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('docs/f.jpg', $this->image());
        $row = DocumentExtraction::create(['batch' => 'b', 'case_number' => '1', 'document_type' => 'it_id_card', 'front_path' => 'docs/f.jpg']);

        $this->get(route('reviews.image', [$row, 'front']))->assertOk();
        $this->get(route('reviews.image', [$row, 'back']))->assertNotFound();
    }

    /** OCR fake that returns a masked copy, like docker/ocr with redact=1. */
    private function fakeOcrWithMaskedCopy(): void
    {
        $masked = base64_encode($this->image());

        Http::fake(function (Request $request) use ($masked) {
            if (str_contains($request->url(), 'ocr:8000')) {
                return Http::response(['text' => 'COGNOME / SURNAME', 'lines' => [], 'confidence' => 0.97, 'orientation_retried' => false,
                    'redacted' => ['image' => $masked, 'covered' => ['text' => 12, 'kept_labels' => 5, 'faces' => 1, 'barcodes' => 0]]]);
            }
            if ($request['model'] === config('documents.judge.model')) {
                return Http::response(['choices' => [['message' => ['content' => 'B'], 'logprobs' => null]]]);
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode(['surname' => 'ROSSI'])]]], 'usage' => []]);
        });
    }

    public function test_processing_stores_a_privacy_masked_copy(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('docs/b/1/front.jpg', $this->image());
        $row = DocumentExtraction::create(['batch' => 'b', 'case_number' => '1', 'document_type' => 'it_id_card', 'front_path' => 'docs/b/1/front.jpg']);
        $this->fakeOcrWithMaskedCopy();

        (new ProcessDocumentExtraction($row->id))->handle(
            app(DocumentExtractor::class), app(IdCardChecks::class), app(JevJudge::class),
        );

        $redacted = $row->refresh()->ocr['front']['redacted'];
        $this->assertSame("redacted/{$row->id}/front.jpg", $redacted['path']);
        $this->assertSame(1, $redacted['covered']['faces']);
        $this->assertArrayNotHasKey('image', $redacted);
        Storage::disk('local')->assertExists($redacted['path']);

        // The OCR service was asked for a masked copy that may show the card's labels.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'ocr:8000')
            && in_array('COGNOME / SURNAME', json_decode(collect($request->data())->firstWhere('name', 'keep')['contents'] ?? '[]', true), true));
    }

    public function test_redact_command_masks_documents_processed_before(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('docs/f.jpg', $this->image());
        $row = DocumentExtraction::create(['batch' => 'b', 'case_number' => '1', 'document_type' => 'it_id_card', 'front_path' => 'docs/f.jpg', 'status' => 'done',
            'fields' => ['surname' => 'ROSSI'], 'ocr' => ['front' => ['text' => 'old text', 'confidence' => 0.9]]]);
        $this->fakeOcrWithMaskedCopy();

        $this->artisan('documents:redact', ['--all' => true])->assertSuccessful();

        $row->refresh();
        $this->assertSame('old text', $row->ocr['front']['text']);
        $this->assertSame('ROSSI', $row->fields['surname']);
        Storage::disk('local')->assertExists($row->ocr['front']['redacted']['path']);
    }

    public function test_privacy_mode_hides_values_and_serves_only_masked_copies(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('docs/f.jpg', $this->image());
        Storage::disk('local')->put('redacted/1/front.jpg', $this->image());
        $fields = array_fill_keys(array_keys(config('documents.types.it_id_card.fields')), null);
        $row = DocumentExtraction::create(['batch' => 'b', 'case_number' => '1', 'document_type' => 'it_id_card', 'front_path' => 'docs/f.jpg', 'status' => 'done',
            'fields' => ['surname' => 'ROSSI'] + $fields, 'ocr' => ['front' => ['text' => 'ROSSI MARIO', 'confidence' => 0.9, 'redacted' => ['path' => 'redacted/1/front.jpg']]]]);

        $this->get(route('reviews.show', $row))->assertOk()->assertSee('ROSSI')->assertSee('Privacy mode: off');

        $this->post(route('reviews.privacy'))->assertRedirect();

        $this->get(route('reviews.show', $row))->assertOk()
            ->assertSee('Privacy mode: on')
            ->assertDontSee('ROSSI')
            ->assertSee('R••••')
            ->assertDontSee('Save and next');
        $this->get(route('reviews.image', [$row, 'front']))->assertForbidden();
        $this->get(route('reviews.image', [$row, 'front', 'redacted' => 1]))->assertOk();
        $this->post(route('reviews.update', $row), ['review' => ['surname' => ['verdict' => 'correct']]])->assertForbidden();

        $this->post(route('reviews.privacy'));
        $this->get(route('reviews.image', [$row, 'front']))->assertOk();
    }
}
