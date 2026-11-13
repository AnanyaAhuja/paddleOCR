<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the PaddleOCR service in docker/ocr.
 *
 * Stateless: configuration is read on every call, so it is safe under Octane.
 */
class PaddleOcr
{
    /**
     * @param  bool  $redact  Also return a copy with personal data blacked out
     * @param  list<string>  $keep  Printed labels the redacted copy may show
     * @return array{text: string, lines: list<array{text: string, confidence: float, box: list<int>}>, redacted?: array{image: string, covered: array<string, int>}}
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function recognize(string $path, bool $redact = false, array $keep = []): array
    {
        $config = config('services.paddle_ocr');
        $fields = $redact ? ['redact' => '1', 'keep' => json_encode(array_values($keep))] : [];

        return Http::timeout($config['timeout'])
            ->attach('file', file_get_contents($path), basename($path))
            ->post(rtrim($config['url'], '/').'/ocr', $fields)
            ->throw()
            ->json();
    }
}
