<?php

namespace App\Console\Commands;

use App\Jobs\ProcessDocumentExtraction;
use App\Models\DocumentExtraction;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs the pipeline again on chosen documents, right here instead of on the
 * queue. Reviews are kept, so /reviews/summary then scores the new results
 * against them (e.g. after changing masks, prompts or the judge).
 */
class ReprocessDocuments extends Command
{
    protected $signature = 'documents:reprocess
        {ids?* : Document ids}
        {--reviewed : All reviewed documents}';

    protected $description = 'Process documents again in this process; reviews are kept as the answer key';

    public function handle(): int
    {
        $query = DocumentExtraction::query()->orderBy('id');

        if ($this->option('reviewed')) {
            $query->reviewed();
        } elseif ($this->argument('ids')) {
            $query->whereKey($this->argument('ids'));
        } else {
            $this->error('Pass document ids or --reviewed.');

            return self::FAILURE;
        }

        $ids = $query->pluck('id');
        $failed = 0;

        foreach ($ids as $i => $id) {
            $started = microtime(true);

            try {
                ProcessDocumentExtraction::dispatchSync($id);
                $this->line(sprintf('[%d/%d] #%d done in %.0fs', $i + 1, $ids->count(), $id, microtime(true) - $started));
            } catch (Throwable $e) {
                $failed++;
                $this->error(sprintf('[%d/%d] #%d failed: %s', $i + 1, $ids->count(), $id, $e->getMessage()));
            }
        }

        $this->info(($ids->count() - $failed)." processed, {$failed} failed. Scores: /reviews/summary");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
