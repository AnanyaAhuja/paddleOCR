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
     * @return array{text: string, lines: list<array{text: string, confidence: float, box: list<int>}>}
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function recognize(string $path): array
    {
        $config = config('services.paddle_ocr');

        return Http::timeout($config['timeout'])
            ->attach('file', file_get_contents($path), basename($path))
            ->post(rtrim($config['url'], '/').'/ocr')
            ->throw()
            ->json();
    }
}
