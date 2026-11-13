<?php

namespace App\Console\Commands;

use App\Documents\PrivacyMasks;
use App\Models\DocumentExtraction;
use App\Services\PaddleOcr;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Makes privacy-masked copies for documents processed before privacy masks
 * existed (new documents get them while processing). Only OCR runs again;
 * the extracted fields, checks and reviews are left alone.
 */
class RedactDocuments extends Command
{
    protected $signature = 'documents:redact
        {ids?* : Document ids}
        {--all : All processed documents without a masked copy}';

    protected $description = 'Make privacy-masked copies of processed documents';

    public function handle(PaddleOcr $ocr, PrivacyMasks $privacy): int
    {
        $query = DocumentExtraction::query()->where('status', 'done')->orderBy('id');

        if ($this->argument('ids')) {
            $query->whereKey($this->argument('ids'));
        } elseif (! $this->option('all')) {
            $this->error('Pass document ids or --all.');

            return self::FAILURE;
        }

        $disk = Storage::disk(config('documents.disk'));
        $rows = $query->get(['id', 'document_type', 'front_path', 'back_path', 'ocr']);
        $done = $failed = 0;

        foreach ($rows as $row) {
            $sides = array_filter(['front' => $row->front_path, 'back' => $row->back_path]);
            if (! $this->argument('ids') && collect($sides)->keys()->every(fn ($side) => isset($row->ocr[$side]['redacted']['path']))) {
                continue;
            }

            try {
                $keep = $privacy->keepLabels($row->document_type) ?? [];
                $masked = [];
                foreach ($sides as $side => $path) {
                    $masked[$side] = $ocr->recognize($disk->path($path), true, $keep);
                }
                $masked = $privacy->store($row, $masked);

                $pages = $row->ocr ?? [];
                foreach ($masked as $side => $page) {
                    $pages[$side]['redacted'] = $page['redacted'] ?? null;
                }
                $row->update(['ocr' => $pages]);
                $done++;
                $this->line("#{$row->id} masked");
            } catch (Throwable $e) {
                $failed++;
                $this->error("#{$row->id} failed: {$e->getMessage()}");
            }
        }

        $this->info("{$done} documents masked, {$failed} failed.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
