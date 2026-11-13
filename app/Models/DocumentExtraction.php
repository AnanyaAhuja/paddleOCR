<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A document (front + optional back) run through OCR, Qwen extraction,
 * automatic checks and manual review.
 *
 * @property array<string, mixed>|null $fields
 * @property array<string, mixed>|null $checks
 * @property array<string, mixed>|null $judge
 * @property array<string, mixed>|null $review
 */
class DocumentExtraction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'ocr' => 'array',
            'checks' => 'array',
            'judge' => 'array',
            'timings' => 'array',
            'review' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Each field's automatic verdict: 'verified' when a rule-based check (MRZ,
     * tax code) confirms it, 'confident' when the judge is sure it is right,
     * 'flagged' when a check fails or the judge doubts it, 'unchecked' otherwise.
     *
     * @return array<string, string>
     */
    public function fieldStatuses(): array
    {
        $threshold = (float) config('documents.judge.threshold');
        $statuses = [];

        foreach (array_keys($this->fields ?? []) as $field) {
            $check = $this->checks[$field]['ok'] ?? null;
            $probability = $this->judge['fields'][$field]['p_true'] ?? null;

            $statuses[$field] = match (true) {
                $check === false => 'flagged',
                $check === true => 'verified',
                $probability === null => 'unchecked',
                $probability >= $threshold => 'confident',
                default => 'flagged',
            };
        }

        return $statuses;
    }

    /**
     * One field's review. It keeps the value that was reviewed and, when
     * known, the right value, so a review stays usable as an answer key after
     * the document is processed again.
     *
     * @return array{verdict: string, corrected: mixed, reviewed_value: mixed, value?: mixed}
     */
    public static function reviewEntry(string $verdict, mixed $reviewedValue, mixed $corrected): array
    {
        $corrected = $verdict === 'wrong' && $corrected !== null && $corrected !== '' ? $corrected : null;
        $entry = ['verdict' => $verdict, 'corrected' => $corrected, 'reviewed_value' => $reviewedValue];

        // The right value is known when the reviewed one was correct, or when a correction was typed.
        if ($verdict === 'correct') {
            $entry['value'] = $reviewedValue;
        } elseif ($corrected !== null) {
            $entry['value'] = $corrected;
        }

        return $entry;
    }

    /**
     * For each reviewed field: is its *current* value wrong? When the value
     * changed since the review (the document was processed again), it is
     * compared with the right value; fields whose right value is unknown are
     * left out.
     *
     * @return array<string, bool>
     */
    public function reviewOutcomes(): array
    {
        $outcomes = [];

        foreach ($this->review ?? [] as $field => $review) {
            if (! is_array($review) || ! in_array($review['verdict'] ?? null, ['correct', 'wrong'], true)) {
                continue;
            }

            $current = $this->fields[$field] ?? null;

            if (! array_key_exists('reviewed_value', $review) || self::sameValue($current, $review['reviewed_value'])) {
                $outcomes[$field] = $review['verdict'] === 'wrong';
            } elseif (array_key_exists('value', $review)) {
                $outcomes[$field] = ! self::sameValue($current, $review['value']);
            }
        }

        return $outcomes;
    }

    /**
     * Fields whose value changed since they were reviewed (the document was
     * processed again), with the reviewed value and its verdict.
     *
     * @return array<string, array{reviewed_value: mixed, verdict: string}>
     */
    public function changedSinceReview(): array
    {
        $changed = [];

        foreach ($this->review ?? [] as $field => $review) {
            if (is_array($review) && array_key_exists('reviewed_value', $review)
                && ! self::sameValue($this->fields[$field] ?? null, $review['reviewed_value'])) {
                $changed[$field] = ['reviewed_value' => $review['reviewed_value'], 'verdict' => $review['verdict']];
            }
        }

        return $changed;
    }

    /** Equal ignoring case, accents, spaces and punctuation. */
    public static function sameValue(mixed $a, mixed $b): bool
    {
        $normalize = function (mixed $value): string {
            $value = (string) $value;
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $value) ?: $value;

            return preg_replace('/[^A-Z0-9]/', '', strtoupper($ascii));
        };

        return $normalize($a) === $normalize($b);
    }

    public function needsReview(): bool
    {
        return in_array('flagged', $this->fieldStatuses(), true);
    }

    public function scopeReviewed(Builder $query): void
    {
        $query->whereNotNull('reviewed_at');
    }

    public function scopeUnreviewed(Builder $query): void
    {
        $query->whereNull('reviewed_at');
    }
}
