<?php

namespace App\Jobs;

use App\Documents\DocumentExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * OCR + Qwen extraction for one uploaded document. Runs on its own queue
 * connection because a run can take minutes (see config/queue.php and
 * config/horizon.php, `documents`).
 */
class ExtractDocument implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    /**
     * @param  array<string, string>  $pages  side => path on the documents disk
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $pages,
    ) {
        $this->onConnection('redis-documents')->onQueue('documents');
    }

    public static function cacheKey(string $id): string
    {
        return "documents:{$id}";
    }

    public function handle(DocumentExtractor $extractor): void
    {
        $disk = Storage::disk(config('documents.disk'));
        $paths = array_map(fn ($path) => $disk->path($path), $this->pages);

        $result = $extractor->extract($this->type, $paths);

        $this->store(['status' => 'done', 'result' => $result]);
    }

    public function failed(?Throwable $e): void
    {
        $this->store(['status' => 'failed', 'error' => $e?->getMessage() ?? 'Unknown error']);
    }

    /** @param  array<string, mixed>  $state */
    private function store(array $state): void
    {
        $key = self::cacheKey($this->id);
        $current = Cache::get($key, []);

        Cache::put($key, array_merge($current, $state), config('documents.result_ttl'));
    }
}
