<?php

namespace App\Documents;

use Carbon\CarbonImmutable;

/**
 * Compares extracted fields against ground truth for the benchmark.
 *
 * Each field scores 0..1. Strings are compared after dropping case, spaces and
 * punctuation; numbers within 0.01; lists by the share of expected entries
 * found (and penalised for extra ones).
 */
class Scorer
{
    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array{score: float, fields: array<string, float>}
     */
    public function score(array $expected, array $actual): array
    {
        $fields = [];

        foreach ($expected as $key => $value) {
            $fields[$key] = $key === 'full_name' && is_string($value) && is_string($actual[$key] ?? null)
                ? $this->sameWords($value, $actual[$key])
                : $this->field($value, $actual[$key] ?? null);
        }

        return [
            'score' => $fields === [] ? 0.0 : array_sum($fields) / count($fields),
            'fields' => $fields,
        ];
    }

    /**
     * Share of expected scalar values that appear somewhere in the OCR text.
     * Tells whether a miss was the OCR's fault or the VLM's.
     *
     * @param  array<string, mixed>  $expected
     * @return array{coverage: float, missing: list<string>}
     */
    public function ocrCoverage(array $expected, string $ocrText): array
    {
        $haystack = $this->normalize($ocrText);
        $checked = 0;
        $missing = [];

        foreach ($expected as $key => $value) {
            if ($value === null || is_array($value)) {
                continue;
            }

            $checked++;
            $found = collect($this->variants($value))
                ->contains(fn ($variant) => $variant !== '' && str_contains($haystack, $this->normalize($variant)));

            if (! $found) {
                $missing[] = $key;
            }
        }

        return [
            'coverage' => $checked === 0 ? 1.0 : ($checked - count($missing)) / $checked,
            'missing' => $missing,
        ];
    }

    private function field(mixed $expected, mixed $actual): float
    {
        if ($expected === null) {
            return $this->isEmpty($actual) ? 1.0 : 0.0;
        }

        if ($this->isEmpty($actual)) {
            return 0.0;
        }

        if (is_array($expected)) {
            return is_array($actual) ? $this->list($expected, $actual) : 0.0;
        }

        if (is_int($expected) || is_float($expected)) {
            return abs((float) $expected - $this->number($actual)) < 0.011 ? 1.0 : 0.0;
        }

        if ($this->isDate($expected)) {
            return $this->date($actual) === $expected ? 1.0 : 0.0;
        }

        return $this->normalize((string) $expected) === $this->normalize($this->stringify($actual)) ? 1.0 : 0.0;
    }

    /**
     * @param  list<mixed>  $expected
     * @param  list<mixed>  $actual
     */
    private function list(array $expected, array $actual): float
    {
        $remaining = $actual;
        $hits = 0;

        foreach ($expected as $item) {
            foreach ($remaining as $i => $candidate) {
                if ($this->listItemMatches($item, $candidate)) {
                    $hits++;
                    unset($remaining[$i]);
                    break;
                }
            }
        }

        // Precision and recall both count, so a list padded with junk loses points.
        $recall = $hits / count($expected);
        $precision = $hits / max(count($actual), 1);

        return $recall + $precision === 0.0 ? 0.0 : 2 * $recall * $precision / ($recall + $precision);
    }

    private function listItemMatches(mixed $expected, mixed $actual): bool
    {
        if (! is_array($expected)) {
            return $this->normalize((string) $expected) === $this->normalize($this->stringify($actual));
        }

        if (! is_array($actual)) {
            return false;
        }

        $amountOk = abs((float) $expected['amount'] - $this->number($actual['amount'] ?? null)) < 0.011;
        similar_text($this->normalize($expected['description']), $this->normalize($this->stringify($actual['description'] ?? '')), $percent);

        return $amountOk && $percent >= 80;
    }

    /** Names match regardless of order: "MENDES ANIKA ROSE" = "Anika Rose Mendes". */
    private function sameWords(string $expected, string $actual): float
    {
        $words = fn (string $s) => collect(preg_split('/\s+/', trim($s)))
            ->map(fn ($w) => $this->normalize($w))->filter()->sort()->values()->all();

        return $words($expected) === $words($actual) ? 1.0 : 0.0;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || (is_string($value) && in_array(strtolower(trim($value)), ['null', 'n/a', 'none'], true));
    }

    private function isDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    private function date(mixed $value): ?string
    {
        try {
            return CarbonImmutable::parse($this->stringify($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : (float) preg_replace('/[^0-9.\-]/', '', $this->stringify($value));
    }

    private function stringify(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : json_encode($value);
    }

    public function normalize(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value));
    }

    /**
     * How a value may be printed on the document: dates in several formats,
     * numbers with and without decimals.
     *
     * @return list<string>
     */
    private function variants(mixed $value): array
    {
        if (is_int($value) || is_float($value)) {
            return [number_format($value, 2, '.', ''), number_format($value, 2, '.', ','), (string) $value];
        }

        if (! $this->isDate($value)) {
            return [$value];
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d', $value);

        return collect(['d/m/Y', 'd.m.Y', 'd-m-Y', 'd M Y', 'd F Y', 'M d, Y', 'F d, Y', 'Y-m-d', 'dMY', 'm/d/Y', 'd/m/y', 'd-M-Y'])
            ->map(fn ($format) => $date->format($format))
            ->all();
    }
}
