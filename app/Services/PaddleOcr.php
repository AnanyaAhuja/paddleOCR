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
     * @param  bool  $card  Cut the ID card out of the photo first and read the flat, upright card
     * @param  list<array{name: string, box: array{float, float, float, float}}>  $zones  Card areas to read again, enlarged
     * @return array{text: string, lines: list<array{text: string, confidence: float, box: list<int>}>, card?: array<string, mixed>, zones?: array<string, array{text: string, confidence: float}>}
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function recognize(string $path, bool $card = false, array $zones = []): array
    {
        $config = config('services.paddle_ocr');
        $fields = array_filter([
            'card' => $card ? '1' : null,
            'zones' => $card && $zones ? json_encode($zones) : null,
        ]);

        return Http::timeout($config['timeout'])
            ->attach('file', file_get_contents($path), basename($path))
            ->post(rtrim($config['url'], '/').'/ocr', $fields)
            ->throw()
            ->json();
    }
}
