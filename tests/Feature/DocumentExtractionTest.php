<?php

namespace Tests\Feature;

use App\Documents\DocumentExtractor;
use App\Jobs\ExtractDocument;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentExtractionTest extends TestCase
{
    private function fakeServices(array $fields = ['full_name' => 'Anika Rose Mendes']): void
    {
        Http::fake([
            'ocr:8000/ocr' => Http::sequence()
                ->push(['text' => "Surname\nMENDES", 'lines' => []])
                ->push(['text' => "Address\n12 MAPLE COURT", 'lines' => []]),
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode($fields)]]],
                'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 60],
            ]),
        ]);
    }

    /** Fake uploads are deleted when the object is freed, so keep them here. */
    private array $files = [];

    private function pages(): array
    {
        $this->files = [
            'front' => UploadedFile::fake()->image('front.png', 400, 250),
            'back' => UploadedFile::fake()->image('back.png', 400, 250),
        ];

        return array_map(fn (UploadedFile $file) => $file->getRealPath(), $this->files);
    }

    public function test_ocr_text_from_both_sides_and_both_images_reach_qwen(): void
    {
        $this->fakeServices();

        $result = app(DocumentExtractor::class)->extract('id_card', $this->pages());

        $this->assertSame('Anika Rose Mendes', $result['fields']['full_name']);
        $this->assertArrayHasKey('expiry_date', $result['fields']);
        $this->assertNull($result['fields']['expiry_date']);
        $this->assertSame(['front' => "Surname\nMENDES", 'back' => "Address\n12 MAPLE COURT"], $result['ocr']);

        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/chat/completions')) {
                return false;
            }

            $content = $request['messages'][1]['content'];
            $images = collect($content)->where('type', 'image_url');

            return $images->count() === 2
                && str_contains($content[0]['text'], "[FRONT]\nSurname\nMENDES")
                && str_contains($content[0]['text'], "[BACK]\nAddress\n12 MAPLE COURT")
                && $request['response_format']['json_schema']['schema']['required'] === array_keys(config('documents.types.id_card.fields'));
        });
    }

    public function test_dates_and_times_come_back_as_numbers_and_leave_as_iso_strings(): void
    {
        $this->fakeServices([
            'date_of_birth' => ['day' => 5, 'month' => 11, 'year' => 1994],
            'issue_date' => ['day' => 31, 'month' => 2, 'year' => 2025],
            'expiry_date' => null,
        ]);

        $result = app(DocumentExtractor::class)->extract('id_card', $this->pages());

        $this->assertSame('1994-11-05', $result['fields']['date_of_birth']);
        $this->assertNull($result['fields']['issue_date'], 'A date that does not exist becomes null');
        $this->assertNull($result['fields']['expiry_date']);
    }

    public function test_date_and_time_fields_are_bounded_integer_objects_in_the_schema(): void
    {
        $extractor = app(DocumentExtractor::class);
        $schema = $extractor->jsonSchema(config('documents.types.receipt.fields'));

        [$date] = $schema['properties']['date']['anyOf'];
        $this->assertSame(['day', 'month', 'year'], $date['required']);
        $this->assertSame(12, $date['properties']['month']['maximum']);

        [$time] = $schema['properties']['time']['anyOf'];
        $this->assertSame(['hour', 'minute'], $time['required']);
        $this->assertSame(23, $time['properties']['hour']['maximum']);
    }

    public function test_receipt_time_is_formatted_as_hh_mm(): void
    {
        $this->fakeServices(['time' => ['hour' => 18, 'minute' => 5], 'date' => ['day' => 8, 'month' => 11, 'year' => 2026]]);

        $result = app(DocumentExtractor::class)->extract('receipt', ['front' => $this->pages()['front']]);

        $this->assertSame('18:05', $result['fields']['time']);
        $this->assertSame('2026-11-08', $result['fields']['date']);
    }

    public function test_image_mode_skips_ocr(): void
    {
        $this->fakeServices();

        $result = app(DocumentExtractor::class)->extract('id_card', $this->pages(), 'image');

        $this->assertNull($result['ocr']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'ocr:8000'));
    }

    public function test_ocr_text_mode_sends_no_images(): void
    {
        $this->fakeServices();

        app(DocumentExtractor::class)->extract('id_card', $this->pages(), 'ocr-text');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/chat/completions')
            && collect($request['messages'][1]['content'])->where('type', 'image_url')->isEmpty());
    }

    public function test_upload_queues_extraction_and_shows_pending_page(): void
    {
        Queue::fake();
        Storage::fake('local');

        $response = $this->post('/documents', [
            'type' => 'id_card',
            'front' => UploadedFile::fake()->image('front.jpg'),
            'back' => UploadedFile::fake()->image('back.jpg'),
        ]);

        $job = null;
        Queue::assertPushedOn('documents', ExtractDocument::class, function (ExtractDocument $pushed) use (&$job) {
            $job = $pushed;

            return array_keys($pushed->pages) === ['front', 'back'];
        });

        $response->assertRedirect(route('documents.show', $job->id));
        Storage::disk('local')->assertExists($job->pages['front']);
        $this->get(route('documents.show', $job->id))->assertOk()->assertSee('Reading the document');
    }

    public function test_upload_requires_a_known_type_and_a_front_image(): void
    {
        $this->post('/documents', ['type' => 'passport'])->assertSessionHasErrors(['type', 'front']);
    }

    public function test_job_stores_the_result_for_the_page(): void
    {
        $this->fakeServices();
        Storage::fake('local');
        Storage::disk('local')->put('documents/x/front.png', file_get_contents($this->pages()['front']));
        Cache::put(ExtractDocument::cacheKey('x'), ['status' => 'pending', 'type' => 'id_card', 'sides' => ['front']]);

        (new ExtractDocument('x', 'id_card', ['front' => 'documents/x/front.png']))->handle(app(DocumentExtractor::class));

        $this->assertSame('done', Cache::get(ExtractDocument::cacheKey('x'))['status']);
        $this->get(route('documents.show', 'x'))->assertOk()->assertSee('Anika Rose Mendes');
    }

    public function test_unknown_document_id_is_404(): void
    {
        $this->get(route('documents.show', 'missing'))->assertNotFound();
    }
}
