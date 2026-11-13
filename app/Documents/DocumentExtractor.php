<?php

namespace App\Documents;

use App\Services\PaddleOcr;
use App\Services\QwenVl;
use InvalidArgumentException;

/**
 * PaddleOCR reads each page, then Qwen turns the pages into structured fields.
 *
 * Modes (what Qwen gets to see):
 *  - ocr+image: the page images plus the OCR text (the default pipeline)
 *  - image:     the page images only (no OCR, baseline)
 *  - ocr-text:  the OCR text only (no images, baseline)
 */
class DocumentExtractor
{
    public const MODES = ['ocr+image', 'image', 'ocr-text'];

    public function __construct(
        private readonly PaddleOcr $ocr,
        private readonly QwenVl $vlm,
    ) {}

    /**
     * OCR every page; with $keepLabels, each page also comes back as a
     * privacy-masked copy that shows only those labels (see docker/ocr/redact.py).
     *
     * @param  array<string, string>  $pages  side => image path, e.g. ['front' => ..., 'back' => ...]
     * @param  list<string>|null  $keepLabels  null: no masked copy
     * @return array<string, array{text: string, lines: list<array<string, mixed>>, ms: int, redacted?: array<string, mixed>}>
     */
    public function ocrPages(array $pages, ?array $keepLabels = null): array
    {
        $results = [];

        foreach ($pages as $side => $path) {
            $started = hrtime(true);
            $result = $this->ocr->recognize($path, $keepLabels !== null, $keepLabels ?? []);
            $results[$side] = $result + ['ms' => $this->msSince($started)];
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $pages  side => image path
     * @param  array<string, array<string, mixed>>|null  $ocr  Precomputed ocrPages() output, to reuse across runs
     * @return array<string, mixed>
     */
    public function extract(string $type, array $pages, string $mode = 'ocr+image', ?string $model = null, ?array $ocr = null): array
    {
        $definition = config("documents.types.{$type}");

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown document type: {$type}");
        }

        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown mode: {$mode}");
        }

        $usesOcr = $mode !== 'image';
        $usesImages = $mode !== 'ocr-text';

        if ($usesOcr) {
            $ocr ??= $this->ocrPages($pages);
        }

        $started = hrtime(true);
        $reply = $this->vlm->extractJson(
            $this->systemPrompt(),
            $this->prompt($definition, array_keys($pages), $usesOcr ? $ocr : null, $usesImages),
            $usesImages ? array_values($pages) : [],
            $this->jsonSchema($definition['fields']),
            $model,
        );
        $vlmMs = $this->msSince($started);

        return [
            'type' => $type,
            'mode' => $mode,
            'model' => $reply['model'],
            'fields' => $this->onlyKnownFields($reply['data'], $definition['fields']),
            'ocr' => $usesOcr ? array_map(fn ($page) => $page['text'], $ocr) : null,
            'timings' => [
                'ocr_ms' => $usesOcr ? array_sum(array_column($ocr, 'ms')) : 0,
                'vlm_ms' => $vlmMs,
            ],
            'usage' => $reply['usage'],
        ];
    }

    private function systemPrompt(): string
    {
        return 'You are a document data extraction engine. Read the document and reply with '
            .'one JSON object containing exactly the requested keys. Never invent values.';
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $sides
     * @param  array<string, array<string, mixed>>|null  $ocr
     */
    private function prompt(array $definition, array $sides, ?array $ocr, bool $withImages): string
    {
        $lines = ["Document type: {$definition['label']}."];

        if (isset($definition['hint'])) {
            $lines[] = $definition['hint'];
        }

        if ($withImages) {
            $order = collect($sides)->values()->map(fn ($side, $i) => ($i + 1).') '.strtoupper($side))->implode(', ');
            $lines[] = count($sides) > 1
                ? 'The document has '.count($sides)." sides, attached as images in this order: {$order}. A field can be on either side."
                : 'The document image is attached.';
        } else {
            $lines[] = 'No image is attached. The OCR text below is your only source.';
        }

        $lines[] = '';
        $lines[] = 'Return a JSON object with these keys:';
        foreach ($definition['fields'] as $key => $field) {
            $lines[] = "- {$key} ({$this->typeLabel($field)}): {$field['description']}";
        }

        $lines[] = '';
        $lines[] = 'Rules:';
        $lines[] = '- Use null when a field does not appear on the document. Do not guess.';
        $lines[] = '- Dates as {day, month, year} and times as {hour, minute}, read in the order the document prints them.';
        $lines[] = '- Amounts as plain numbers, no currency symbols or thousands separators.';
        $lines[] = '- Copy ID, licence and invoice numbers exactly as printed, including letters.';

        if ($ocr !== null) {
            $lines[] = '';
            $lines[] = $withImages
                ? 'Text read from each side by OCR (PaddleOCR). It can contain recognition mistakes; check it against the images:'
                : 'Text read from each side by OCR (PaddleOCR). It can contain recognition mistakes:';

            foreach ($ocr as $side => $page) {
                $lines[] = '['.strtoupper($side).']';
                $lines[] = trim($page['text']) !== '' ? $page['text'] : '(no text found)';
            }
        }

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed>  $field */
    private function typeLabel(array $field): string
    {
        if ($field['type'] !== 'list') {
            return match ($field['type']) {
                'date' => 'date as {day, month, year}',
                'time' => 'time as {hour, minute}, 24-hour',
                default => $field['type'],
            };
        }

        return is_array($field['item'])
            ? 'list of {'.implode(', ', array_keys($field['item'])).'}'
            : "list of {$field['item']}";
    }

    /**
     * JSON schema for llama.cpp's constrained decoding: every key required,
     * scalars nullable. Dates and times are asked for as numbers
     * ({day, month, year}) rather than a YYYY-MM-DD string: forcing a string
     * pattern made the 2B model bend "05/11/1994" into "0511-11-19".
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    public function jsonSchema(array $fields): array
    {
        $properties = [];

        foreach ($fields as $key => $field) {
            $properties[$key] = match ($field['type']) {
                'number' => ['type' => ['number', 'null']],
                'date' => $this->nullableObject([
                    'day' => [1, 31], 'month' => [1, 12], 'year' => [1900, 2100],
                ]),
                'time' => $this->nullableObject(['hour' => [0, 23], 'minute' => [0, 59]]),
                'list' => ['type' => 'array', 'items' => is_array($field['item'])
                    ? [
                        'type' => 'object',
                        'properties' => array_map(fn ($t) => ['type' => [$t, 'null']], $field['item']),
                        'required' => array_keys($field['item']),
                    ]
                    : ['type' => $field['item']],
                ],
                default => ['type' => ['string', 'null']],
            };
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($fields),
            'additionalProperties' => false,
        ];
    }

    /**
     * An object of bounded integers, or null.
     *
     * @param  array<string, array{int, int}>  $parts  name => [min, max]
     * @return array<string, mixed>
     */
    private function nullableObject(array $parts): array
    {
        return ['anyOf' => [[
            'type' => 'object',
            'properties' => array_map(fn ($range) => ['type' => 'integer', 'minimum' => $range[0], 'maximum' => $range[1]], $parts),
            'required' => array_keys($parts),
            'additionalProperties' => false,
        ], ['type' => 'null']]];
    }

    /**
     * Keep only the configured fields and turn date/time objects into
     * YYYY-MM-DD / HH:MM strings. A date that does not exist becomes null.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function onlyKnownFields(array $data, array $fields): array
    {
        return collect($fields)->mapWithKeys(function ($field, $key) use ($data) {
            $value = $data[$key] ?? null;

            return [$key => match ($field['type']) {
                'date' => is_array($value) && checkdate((int) ($value['month'] ?? 0), (int) ($value['day'] ?? 0), (int) ($value['year'] ?? 0))
                    ? sprintf('%04d-%02d-%02d', $value['year'], $value['month'], $value['day'])
                    : null,
                'time' => is_array($value) && isset($value['hour'], $value['minute'])
                    ? sprintf('%02d:%02d', $value['hour'], $value['minute'])
                    : null,
                default => $value,
            }];
        })->all();
    }

    private function msSince(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }
}
