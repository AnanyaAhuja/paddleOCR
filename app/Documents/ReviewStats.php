<?php

namespace App\Documents;

use App\Models\DocumentExtraction;
use Illuminate\Support\Collection;

/**
 * How well the pipeline did, measured against manual reviews: accuracy per
 * field, and how well the automatic checks (rules and the JEV judge)
 * separated right values from wrong ones.
 */
class ReviewStats
{
    /**
     * @param  Collection<int, DocumentExtraction>  $reviewed
     * @return array<string, mixed>
     */
    public function compute(Collection $reviewed): array
    {
        $threshold = (float) config('documents.judge.threshold');
        $perField = [];
        $judge = ['tp' => 0, 'fp' => 0, 'fn' => 0, 'tn' => 0];
        $rules = ['tp' => 0, 'fp' => 0, 'fn' => 0, 'tn' => 0];
        $auto = ['trusted' => 0, 'trusted_wrong' => 0, 'flagged' => 0, 'flagged_wrong' => 0];
        $buckets = [];

        foreach ($reviewed as $row) {
            $statuses = $row->fieldStatuses();

            foreach ($row->review ?? [] as $field => $verdict) {
                if (! in_array($verdict['verdict'] ?? null, ['correct', 'wrong'], true)) {
                    continue;
                }

                $wrong = $verdict['verdict'] === 'wrong';
                $perField[$field]['n'] = ($perField[$field]['n'] ?? 0) + 1;
                $perField[$field]['correct'] = ($perField[$field]['correct'] ?? 0) + ($wrong ? 0 : 1);

                // Judge: "flagged" means it said this value is probably not on the card.
                $p = $row->judge['fields'][$field]['p_true'] ?? null;
                if ($p !== null) {
                    $this->tally($judge, $p < $threshold, $wrong);
                    $bucket = min(9, (int) floor($p * 10));
                    $buckets[$bucket]['n'] = ($buckets[$bucket]['n'] ?? 0) + 1;
                    $buckets[$bucket]['correct'] = ($buckets[$bucket]['correct'] ?? 0) + ($wrong ? 0 : 1);
                    $buckets[$bucket]['p_sum'] = ($buckets[$bucket]['p_sum'] ?? 0) + $p;
                }

                // Rules: only fields an MRZ / fiscal-code check covered.
                $ok = $row->checks[$field]['ok'] ?? null;
                if ($ok !== null) {
                    $this->tally($rules, $ok === false, $wrong);
                }

                // What a reviewer would have to look at if we trusted the automatic verdicts.
                $status = $statuses[$field] ?? 'unchecked';
                if (in_array($status, ['verified', 'confident'], true)) {
                    $auto['trusted']++;
                    $auto['trusted_wrong'] += $wrong ? 1 : 0;
                } elseif ($status === 'flagged') {
                    $auto['flagged']++;
                    $auto['flagged_wrong'] += $wrong ? 1 : 0;
                }
            }
        }

        ksort($buckets);
        $fields = collect($perField)->map(fn ($s) => $s + ['accuracy' => $s['n'] ? $s['correct'] / $s['n'] : null]);

        return [
            'documents' => $reviewed->count(),
            'fields' => $fields->all(),
            'overall' => $fields->sum('n') ? $fields->sum('correct') / $fields->sum('n') : null,
            'judge' => $this->rates($judge),
            'rules' => $this->rates($rules),
            'auto' => $auto,
            'calibration' => collect($buckets)->map(fn ($b, $i) => [
                'range' => sprintf('%d–%d%%', $i * 10, $i * 10 + 10),
                'n' => $b['n'],
                'mean_p' => $b['p_sum'] / $b['n'],
                'actual' => $b['correct'] / $b['n'],
            ])->values()->all(),
            'threshold' => $threshold,
        ];
    }

    /** @param  array<string, int>  $counts */
    private function tally(array &$counts, bool $flagged, bool $wrong): void
    {
        $key = match (true) {
            $flagged && $wrong => 'tp',
            $flagged && ! $wrong => 'fp',
            ! $flagged && $wrong => 'fn',
            default => 'tn',
        };
        $counts[$key]++;
    }

    /**
     * @param  array<string, int>  $c
     * @return array<string, int|float|null>
     */
    private function rates(array $c): array
    {
        $flagged = $c['tp'] + $c['fp'];
        $wrong = $c['tp'] + $c['fn'];

        return $c + [
            'n' => array_sum($c),
            // Of the values it flagged, how many were really wrong.
            'precision' => $flagged ? $c['tp'] / $flagged : null,
            // Of the values that were really wrong, how many it caught.
            'recall' => $wrong ? $c['tp'] / $wrong : null,
        ];
    }
}
