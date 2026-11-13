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

    public function test_masks_keep_the_cut_out_card_and_read_its_zones(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('docs/b/1/back.jpg', $this->image());
        $row = DocumentExtraction::create(['batch' => 'b', 'case_number' => '1', 'document_type' => 'it_id_card', 'front_path' => 'docs/b/1/back.jpg']);
        $card = UploadedFile::fake()->image('card.jpg', 140, 88)->getContent();
        $prompt = null;

        Http::fake(function (Request $request) use ($card, &$prompt) {
            if (str_contains($request->url(), 'ocr:8000')) {
                return Http::response([
                    'text' => 'CODICE FISCALE', 'lines' => [], 'confidence' => 0.97, 'orientation_retried' => false,
                    'card' => ['found' => true, 'method' => 'edges', 'corners' => [[0, 0], [9, 0], [9, 5], [0, 5]], 'angle' => 180, 'image' => base64_encode($card)],
                    'zones' => ['document_number' => ['text' => 'CA00000AA', 'confidence' => 0.99]],
                ]);
            }
            if ($request['model'] === config('documents.judge.model')) {
                return Http::response(['choices' => [['message' => ['content' => 'B'], 'logprobs' => null]]]);
            }
            $prompt = json_encode($request['messages']);

            return Http::response(['choices' => [['message' => ['content' => json_encode(['document_number' => 'CA00000AA'])]]], 'usage' => []]);
        });

        (new ProcessDocumentExtraction($row->id))->handle(
            app(DocumentExtractor::class), app(IdCardChecks::class), app(JevJudge::class),
        );

        $row->refresh();
        $this->assertSame('done', $row->status);
        $this->assertSame('cards/'.$row->id.'/front.jpg', $row->ocr['front']['card']['path']);
        $this->assertArrayNotHasKey('image', $row->ocr['front']['card']);
        Storage::disk('local')->assertExists($row->ocr['front']['card']['path']);
        $this->assertStringContainsString('close-up: document number', $prompt);

        // The review page shows the card, and the original stays available.
        $this->get(route('reviews.image', [$row, 'front', 'card' => 1]))->assertOk();
        $this->get(route('reviews.image', [$row, 'front']))->assertOk();
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

    public function test_reviews_score_the_current_values_after_reprocessing(): void
    {
        $row = new DocumentExtraction(['fields' => ['surname' => 'ROSI', 'sex' => 'M', 'height_cm' => 170, 'address' => 'VIA X']]);
        $row->review = [
            'surname' => DocumentExtraction::reviewEntry('wrong', 'ROSI', 'Rossi'),
            'sex' => DocumentExtraction::reviewEntry('correct', 'M', null),
            'height_cm' => DocumentExtraction::reviewEntry('correct', 170, null),
            'address' => DocumentExtraction::reviewEntry('wrong', 'VIA X', null),
            '_note' => null,
        ];

        $this->assertSame(['surname' => true, 'sex' => false, 'height_cm' => false, 'address' => true], $row->reviewOutcomes());

        // Processed again: the surname is fixed, sex got worse, the address
        // changed but its right value was never typed in, so it is left out.
        $row->fields = ['surname' => 'ROSSI', 'sex' => 'F', 'height_cm' => '170', 'address' => 'VIA Y'];
        $this->assertSame(['surname' => false, 'sex' => true, 'height_cm' => false], $row->reviewOutcomes());
        $this->assertSame(['surname', 'sex', 'address'], array_keys($row->changedSinceReview()));
    }

    public function test_changed_since_review_lists_documents_whose_values_changed(): void
    {
        $fields = array_fill_keys(array_keys(config('documents.types.it_id_card.fields')), 'X');
        $review = array_map(fn ($value) => DocumentExtraction::reviewEntry('correct', $value, null), $fields);
        $same = DocumentExtraction::create(['batch' => 'b', 'case_number' => '1', 'document_type' => 'it_id_card', 'front_path' => 'f', 'status' => 'done', 'fields' => $fields, 'review' => $review, 'reviewed_at' => now()]);
        $changed = DocumentExtraction::create(['batch' => 'b', 'case_number' => '2', 'document_type' => 'it_id_card', 'front_path' => 'f', 'status' => 'done', 'fields' => ['surname' => 'Y'] + $fields, 'review' => $review, 'reviewed_at' => now()]);

        $this->get(route('reviews.index', ['show' => 'changed']))->assertOk()->assertSee('b / 2')->assertDontSee('b / 1');
        // The old verdict isn't pre-filled for the changed field.
        $this->get(route('reviews.show', $changed))->assertOk()->assertSee('Changed since review');
        $this->get(route('reviews.show', $same))->assertOk()->assertDontSee('Changed since review');
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
}
