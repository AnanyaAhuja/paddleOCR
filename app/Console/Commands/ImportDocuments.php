<?php

namespace App\Console\Commands;

use App\Jobs\ProcessDocumentExtraction;
use App\Models\DocumentExtraction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Imports a folder of documents laid out as <batch>/<case>/<images> on the
 * documents disk, stores one row per case and queues its processing.
 *
 * Within a case, files named "...fronte"/"...front" are the front and
 * "...retro"/"...back" the back. A single unlabelled file is processed as
 * the front (e.g. one scanned page holding both sides).
 */
class ImportDocuments extends Command
{
    protected $signature = 'documents:import
        {directory=real-docs : Folder on the documents disk (storage/app/private)}
        {--type=it_id_card : Document type from config/documents.php}
        {--batch= : Only this batch folder}
        {--limit=0 : Only the first N cases (0 = all)}
        {--reprocess : Queue every case again, including done and already-queued ones}
        {--dry-run : List what would be imported}';

    protected $description = 'Store documents from a folder in the database and queue OCR + Qwen + checks';

    public function handle(): int
    {
        $type = $this->option('type');
        if (config("documents.types.{$type}") === null) {
            $this->error("Unknown document type: {$type}");

            return self::FAILURE;
        }

        $disk = Storage::disk(config('documents.disk'));
        $root = trim($this->argument('directory'), '/');
        $batches = $this->option('batch') ? ["{$root}/{$this->option('batch')}"] : $disk->directories($root);
        $limit = (int) $this->option('limit');
        $queued = $skipped = $problems = 0;

        foreach ($batches as $batchDir) {
            foreach ($disk->directories($batchDir) as $caseDir) {
                if ($limit && $queued + $skipped >= $limit) {
                    break 2;
                }

                [$front, $back] = $this->sides($disk->files($caseDir));
                $batch = basename($batchDir);
                $case = basename($caseDir);

                if ($front === null) {
                    $this->warn("{$batch}/{$case}: could not tell which file is the front, skipped");
                    $problems++;

                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line(sprintf('%s/%s  front=%s  back=%s', $batch, $case, basename($front), $back ? basename($back) : '-'));
                    $queued++;

                    continue;
                }

                $row = DocumentExtraction::firstOrNew(['batch' => $batch, 'case_number' => $case]);

                // Done cases are kept and pending ones are already on the queue, so
                // by default only new and failed cases are queued.
                if ($row->exists && $row->status !== 'failed' && ! $this->option('reprocess')) {
                    $skipped++;

                    continue;
                }

                $row->fill([
                    'document_type' => $type,
                    'front_path' => $front,
                    'back_path' => $back,
                    'status' => 'pending',
                ])->save();

                ProcessDocumentExtraction::dispatch($row->id);
                $queued++;
            }
        }

        $verb = $this->option('dry-run') ? 'would be queued' : 'queued';
        $this->info("{$queued} cases {$verb}, {$skipped} already done or queued, {$problems} skipped.");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $files
     * @return array{string|null, string|null}
     */
    private function sides(array $files): array
    {
        $files = array_values(array_filter($files, fn ($f) => preg_match('/\.(jpe?g|png|webp)$/i', $f)));
        $front = $back = null;
        $unlabelled = [];

        foreach ($files as $file) {
            $name = strtolower(pathinfo($file, PATHINFO_FILENAME));
            match (true) {
                (bool) preg_match('/\b(fronte|front)\s*$/', $name) => $front = $file,
                (bool) preg_match('/\b(retro|back)\s*$/', $name) => $back = $file,
                default => $unlabelled[] = $file,
            };
        }

        // One labelled side plus one unlabelled file: the unlabelled one is the other side.
        if (count($unlabelled) === 1) {
            if ($front === null) {
                $front = $unlabelled[0];
            } elseif ($back === null) {
                $back = $unlabelled[0];
            }
        }

        return [$front, $back];
    }
}
