<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Chat-completions client for the Qwen VLM served by Docker Model Runner.
 *
 * Stateless: configuration is read on every call, so it is safe under Octane.
 */
class QwenVl
{
    /**
     * Send a prompt plus optional images and get a JSON object back.
     *
     * @param  list<string>  $imagePaths
     * @param  array<string, mixed>  $jsonSchema  Constrains the output (llama.cpp grammar)
     * @return array{data: array<string, mixed>, raw: string, model: string, usage: array<string, int>}
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function extractJson(string $system, string $prompt, array $imagePaths, array $jsonSchema, ?string $model = null): array
    {
        $config = config('services.qwen_vl');
        $model ??= $config['model'];

        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($imagePaths as $path) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $this->dataUrl($path, $config['max_image_side'])],
            ];
        }

        $response = Http::timeout($config['timeout'])
            ->post(rtrim($config['url'], '/').'/chat/completions', [
                'model' => $model,
                'temperature' => 0,
                'max_tokens' => 1500,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $content],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'document', 'schema' => $jsonSchema],
                ],
            ])
            ->throw()
            ->json();

        $raw = (string) data_get($response, 'choices.0.message.content', '');

        return [
            'data' => $this->decode($raw),
            'raw' => $raw,
            'model' => $model,
            'usage' => [
                'prompt_tokens' => (int) data_get($response, 'usage.prompt_tokens', 0),
                'completion_tokens' => (int) data_get($response, 'usage.completion_tokens', 0),
            ],
        ];
    }

    /**
     * Pull the JSON object out of the reply, tolerating code fences or a
     * leading <think> block.
     *
     * @return array<string, mixed>
     */
    public function decode(string $raw): array
    {
        $text = preg_replace('/<think>.*?<\/think>/s', '', $raw);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false) {
            throw new RuntimeException('Model reply contained no JSON object.');
        }

        $data = json_decode(substr($text, $start, $end - $start + 1), true);

        if (! is_array($data)) {
            throw new RuntimeException('Model reply was not valid JSON: '.json_last_error_msg());
        }

        return $data;
    }

    /**
     * Base64 data URL, downscaled so the longest side is at most $maxSide.
     */
    private function dataUrl(string $path, int $maxSide): string
    {
        $bytes = file_get_contents($path);
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new RuntimeException("Could not read image: {$path}");
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = $maxSide / max($width, $height);

        if ($scale < 1) {
            $image = imagescale($image, (int) round($width * $scale), (int) round($height * $scale), IMG_BICUBIC);
        }

        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }
}
