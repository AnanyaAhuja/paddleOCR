<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Asks the JEV "System One" model whether each extracted value is really on
 * the document, and reads back a calibrated P(true).
 *
 * JEV answers multiple choice with one token. A yes/no question is a two-option
 * choice "A. false / B. true"; P(true) is the softmax of the two option-letter
 * log-probabilities (see the model card for sidman007/jev-vlm-4b-lite-gguf).
 *
 * Each question is a separate request with the image(s) attached. JEV's hybrid
 * architecture stops llama.cpp from reusing the processed image between
 * requests, so every question pays for reading the image again (~9 s on an
 * M-series GPU); callers should ask only about fields nothing else verified.
 *
 * Stateless: configuration is read on every call, so it is safe under Octane.
 */
class JevJudge
{
    /**
     * @param  list<string>  $imagePaths
     * @param  array<string, string>  $questions  field => question text
     * @return array{fields: array<string, array{p_true: float|null, question: string}>, ms: int, model: string}
     */
    public function judge(array $imagePaths, array $questions, ?string $model = null, ?string $url = null): array
    {
        $config = config('documents.judge');
        $model ??= $config['model'];
        $url ??= $config['url'];

        $images = array_map(fn ($path) => [
            'type' => 'image_url',
            // JEV re-reads the image for every question (its hybrid layers stop
            // llama.cpp reusing it), so a smaller image saves time on each one.
            'image_url' => ['url' => ImageEncoder::dataUrl($path, (int) $config['max_image_side'])],
        ], $imagePaths);

        $started = hrtime(true);
        $results = [];

        foreach ($questions as $field => $question) {
            $response = Http::timeout($config['timeout'])
                ->post(rtrim($url, '/').'/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'max_tokens' => 1,
                    'logprobs' => true,
                    'top_logprobs' => 20,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [...$images, ['type' => 'text', 'text' => $this->prompt($question)]],
                    ]],
                ])
                ->throw()
                ->json();

            $results[$field] = [
                'p_true' => $this->probabilityTrue($response),
                'question' => $question,
            ];
        }

        return [
            'fields' => $results,
            'ms' => (int) round((hrtime(true) - $started) / 1e6),
            'model' => $model,
        ];
    }

    /**
     * One yes/no question per extracted value, phrased the way the card prints it.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, array<string, mixed>>  $definitions
     * @return array<string, string>
     */
    public function questionsFor(array $fields, array $definitions, string $documentLabel): array
    {
        $questions = [];

        foreach ($fields as $field => $value) {
            $type = $definitions[$field]['type'] ?? 'string';

            if ($value === null || $value === '' || $type === 'list') {
                continue;
            }

            if ($type === 'date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
                $value = "{$m[3]}.{$m[2]}.{$m[1]}";
            }

            $label = $definitions[$field]['description'] ?? str_replace('_', ' ', $field);
            $questions[$field] = "Is the {$label} on this {$documentLabel} \"{$value}\"?";
        }

        return $questions;
    }

    public function prompt(string $question): string
    {
        return "Question: {$question}\nOptions:\nA. false\nB. true";
    }

    /** @param  array<string, mixed>  $response */
    public function probabilityTrue(array $response): ?float
    {
        $top = data_get($response, 'choices.0.logprobs.content.0.top_logprobs', []);
        $logprob = ['A' => null, 'B' => null];

        foreach ($top as $candidate) {
            $token = strtoupper(trim((string) ($candidate['token'] ?? '')));
            if (array_key_exists($token, $logprob) && $logprob[$token] === null) {
                $logprob[$token] = (float) $candidate['logprob'];
            }
        }

        if ($logprob['A'] === null && $logprob['B'] === null) {
            // No log-probabilities: fall back to the letter it chose.
            $answer = strtoupper(trim((string) data_get($response, 'choices.0.message.content', '')));

            return match ($answer) {
                'B' => 1.0,
                'A' => 0.0,
                default => null,
            };
        }

        $a = $logprob['A'] === null ? 0.0 : exp($logprob['A']);
        $b = $logprob['B'] === null ? 0.0 : exp($logprob['B']);

        return round($b / ($a + $b), 4);
    }
}
