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
