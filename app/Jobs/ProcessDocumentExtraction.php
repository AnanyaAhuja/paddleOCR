<?php

namespace App\Jobs;

use App\Documents\Checks\IdCardChecks;
use App\Documents\DocumentExtractor;
use App\Models\DocumentExtraction;
use App\Services\JevJudge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * OCR -> Qwen extraction -> rule checks (MRZ, fiscal code) -> JEV judge,
 * for one stored document. Results go on the DocumentExtraction row.
 */
class ProcessDocumentExtraction implements ShouldQueue
{
    use Queueable;

    // OCR runs emulated and the judge asks one question per field.
    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $extractionId)
    {
        $this->onConnection('redis-documents')->onQueue('documents');
    }

    public function handle(DocumentExtractor $extractor, IdCardChecks $checks, JevJudge $judge): void
    {
        $row = DocumentExtraction::findOrFail($this->extractionId);
        $row->update(['status' => 'processing', 'error' => null]);

        $disk = Storage::disk(config('documents.disk'));
        $pages = array_filter([
            'front' => $disk->path($row->front_path),
            'back' => $row->back_path ? $disk->path($row->back_path) : null,
        ]);

        $masks = config('documents.masks_enabled') ? config("documents.types.{$row->document_type}.masks") : null;
        [$ocr, $pages] = $this->keepCards($row, $extractor->ocrPages($pages, $masks), $pages);

        $result = $extractor->extract($row->document_type, $pages, 'ocr+image', null, $ocr);
        $fields = $result['fields'];

        // Close-up reads first: the MRZ check takes the first MRZ it finds.
        $zoneText = collect($ocr)->flatMap(fn ($page) => array_column($page['zones'] ?? [], 'text'))->implode("\n");
        $ocrText = trim($zoneText."\n".implode("\n", array_column($ocr, 'text')));

        $ruleChecks = $row->document_type === 'it_id_card'
            ? $checks->run($fields, $ocrText)
            : ['fields' => [], 'mrz' => null, 'fiscal_code_valid' => null];

        $judged = null;
        if (config('documents.judge.enabled')) {
            $definition = config("documents.types.{$row->document_type}");
            try {
                $judged = $this->judgeUnverified($judge, $definition, $fields, $ruleChecks['fields'], $pages);
            } catch (Throwable $e) {
                // The extraction is still useful without the judge.
                Log::warning('JEV judge failed', ['extraction' => $row->id, 'error' => $e->getMessage()]);
                $judged = ['error' => $e->getMessage()];
            }
        }

        $row->update([
            'status' => 'done',
            'fields' => $fields,
            'ocr' => array_map(fn ($page) => array_filter([
                'text' => $page['text'],
                'confidence' => $page['confidence'] ?? null,
                'orientation_retried' => $page['orientation_retried'] ?? null,
                'card' => $page['card'] ?? null,
                'zones' => $page['zones'] ?? null,
            ], fn ($value) => $value !== null), $ocr),
            'checks' => $ruleChecks['fields'] + ['_mrz' => $ruleChecks['mrz'], '_fiscal_code_valid' => $ruleChecks['fiscal_code_valid']],
            'judge' => $judged,
            'timings' => [
                'ocr_ms' => $result['timings']['ocr_ms'],
                'vlm_ms' => $result['timings']['vlm_ms'],
                'judge_ms' => $judged['ms'] ?? null,
            ],
            'model' => $result['model'],
        ]);
    }

    /**
     * Ask the judge only about fields no exact rule confirmed (MRZ / fiscal
     * code are stronger evidence than any model, and each JEV question costs
     * several seconds), showing it just the side the field is printed on.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $fields
     * @param  array<string, array{ok: bool}>  $ruleChecks
     * @param  array<string, string>  $pages
     * @return array{fields: array<string, mixed>, ms: int, model: string|null, skipped: list<string>}
     */
    private function judgeUnverified(JevJudge $judge, array $definition, array $fields, array $ruleChecks, array $pages): array
    {
        $verified = array_keys(array_filter($ruleChecks, fn ($check) => $check['ok'] === true));
        $toJudge = array_diff_key($fields, array_flip($verified));
        $questions = $judge->questionsFor($toJudge, $definition['fields'], $definition['label']);

        // Group questions by the side their field is printed on. A one-page
        // scan holds both sides, so everything goes to that image.
        $bySide = [];
        foreach ($questions as $field => $question) {
            $side = $definition['fields'][$field]['side'] ?? 'front';
            $bySide[isset($pages[$side]) ? $side : 'front'][$field] = $question;
        }

        $result = ['fields' => [], 'ms' => 0, 'model' => null, 'skipped' => $verified];
        foreach ($bySide as $side => $sideQuestions) {
            $answer = $judge->judge([$pages[$side]], $sideQuestions);
            $result['fields'] += $answer['fields'];
            $result['ms'] += $answer['ms'];
            $result['model'] = $answer['model'];
        }

        return $result;
    }

    /**
     * Save each card the OCR service cut out of a photo, and use it instead of
     * the photo from here on: Qwen and JEV then see a flat, upright card with
     * no background, which is also fewer pixels to read.
     *
     * @param  array<string, array<string, mixed>>  $ocr
     * @param  array<string, string>  $pages  side => absolute image path
     * @return array{array<string, array<string, mixed>>, array<string, string>}
     */
    private function keepCards(DocumentExtraction $row, array $ocr, array $pages): array
    {
        $disk = Storage::disk(config('documents.disk'));

        foreach ($ocr as $side => $page) {
            $image = $page['card']['image'] ?? null;
            unset($ocr[$side]['card']['image']);

            if (! ($page['card']['found'] ?? false) || ! $image) {
                continue;
            }

            $path = "cards/{$row->id}/{$side}.jpg";
            $disk->put($path, base64_decode($image));
            $ocr[$side]['card']['path'] = $path;
            $pages[$side] = $disk->path($path);
        }

        return [$ocr, $pages];
    }

    public function failed(?Throwable $e): void
    {
        DocumentExtraction::whereKey($this->extractionId)
            ->update(['status' => 'failed', 'error' => $e?->getMessage() ?? 'Unknown error']);
    }
}
