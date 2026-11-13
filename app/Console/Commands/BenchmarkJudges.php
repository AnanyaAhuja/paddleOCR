<?php

namespace App\Console\Commands;

use App\Services\JevJudge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Compares "judges" (models that answer "is this value on the document?")
 * on the fixture documents, where the truth is known.
 *
 * For every expected value it asks a true question (the real value) and a
 * false one (the value with a realistic OCR-style mistake), then reports
 * accuracy, how many false values were caught, calibration and speed.
 *
 * Each judge is "label=model@url"; url defaults to the configured judge URL.
 */
class BenchmarkJudges extends Command
{
    protected $signature = 'documents:judge-benchmark
        {--judge=* : label=model[@url], repeatable}
        {--only= : Comma-separated fixture names}
        {--fixtures=tests/fixtures/documents : Fixture directory}';

    protected $description = 'Compare JEV (System One) and VLM judges on the fixture documents';

    public function handle(JevJudge $judge): int
    {
        $judges = $this->judges();
        $fixtures = $this->fixtures();
        $threshold = (float) config('documents.judge.threshold');
        $results = [];

        foreach ($judges as $label => [$model, $url]) {
            $this->info("{$label}: {$model}");
            $answers = [];
            $seconds = 0.0;

            foreach ($fixtures as $name => $fixture) {
                $definition = config("documents.types.{$fixture['type']}");
                $truth = array_filter($fixture['expected'], fn ($v) => $v !== null && ! is_array($v));
                $wrong = array_map(fn ($v) => $this->corrupt($v), $truth);

                $questions = [];
                foreach ($judge->questionsFor($truth, $definition['fields'], $definition['label']) as $field => $q) {
                    $questions["{$field}|1"] = $q;
                }
                foreach ($judge->questionsFor($wrong, $definition['fields'], $definition['label']) as $field => $q) {
                    $questions["{$field}|0"] = $q;
                }

                try {
                    $r = $judge->judge(array_values($fixture['pages']), $questions, $model, $url);
                } catch (Throwable $e) {
                    $this->error("  {$name}: {$e->getMessage()}");

                    continue;
                }

                $seconds += $r['ms'] / 1000;
                foreach ($r['fields'] as $key => $answer) {
                    [$field, $isTrue] = explode('|', $key);
                    $answers[] = ['doc' => $name, 'field' => $field, 'truth' => $isTrue === '1', 'p' => $answer['p_true'], 'question' => $answer['question']];
                }
                $this->line(sprintf('  %-22s %3d questions  %5.1fs', $name, count($r['fields']), $r['ms'] / 1000));
            }

            $results[$label] = ['model' => $model, 'seconds' => $seconds] + $this->score($answers, $threshold) + ['answers' => $answers];
        }

        $this->newLine();
        $this->table(
            ['Judge', 'Questions', 'Accuracy', 'False values caught', 'True values flagged', 'Calibration error', 'Unanswered', 's / question'],
            collect($results)->map(fn ($r, $label) => [
                $label, $r['n'], $this->pct($r['accuracy']), $this->pct($r['caught']), $this->pct($r['false_alarms']),
                $this->pct($r['ece']), $r['unanswered'], $r['n'] ? number_format($r['seconds'] / $r['n'], 2) : '—',
            ])->values()->all(),
        );
        $this->line("Flag threshold: P(true) < {$threshold}. Calibration error: gap between confidence and accuracy, averaged over 10 bins (lower is better).");

        $path = storage_path('app/benchmarks/judges-'.now()->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(['threshold' => $threshold, 'judges' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("Results written to {$path}");

        return self::SUCCESS;
    }

    /**
     * A plausible wrong value: the kind of mistake OCR or a model makes.
     */
    public function corrupt(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return $value + ($value >= 10 ? round($value * 0.1, 2) : 1);
        }

        $value = (string) $value;

        // Dates: one day later (a misread digit).
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return date('Y-m-d', strtotime("{$value} +1 day"));
        }

        if (in_array(strtoupper($value), ['M', 'F'], true)) {
            return strtoupper($value) === 'M' ? 'F' : 'M';
        }

        // Change the middle digit if there is one, else drop a middle letter.
        if (preg_match_all('/\d/', $value, $m, PREG_OFFSET_CAPTURE) && count($m[0]) > 0) {
            [$digit, $pos] = $m[0][intdiv(count($m[0]), 2)];

            return substr_replace($value, (string) (((int) $digit + 3) % 10), $pos, 1);
        }

        $pos = intdiv(strlen($value), 2);

        return strlen($value) > 3 ? substr_replace($value, '', $pos, 1) : $value.'X';
    }

    /**
     * @param  list<array{truth: bool, p: float|null}>  $answers
     * @return array<string, int|float|null>
     */
    private function score(array $answers, float $threshold): array
    {
        $answered = array_values(array_filter($answers, fn ($a) => $a['p'] !== null));
        $n = count($answered);
        $correct = count(array_filter($answered, fn ($a) => ($a['p'] >= 0.5) === $a['truth']));
        $false = array_filter($answered, fn ($a) => ! $a['truth']);
        $true = array_filter($answered, fn ($a) => $a['truth']);

        // Expected calibration error over 10 bins of "confidence in the chosen answer".
        $bins = [];
        foreach ($answered as $a) {
            $confidence = max($a['p'], 1 - $a['p']);
            $bin = min(9, (int) floor($confidence * 10));
            $bins[$bin][] = [$confidence, ($a['p'] >= 0.5) === $a['truth'] ? 1 : 0];
        }
        $ece = 0.0;
        foreach ($bins as $items) {
            $ece += count($items) / max($n, 1) * abs(array_sum(array_column($items, 0)) / count($items) - array_sum(array_column($items, 1)) / count($items));
        }

        return [
            'n' => count($answers),
            'unanswered' => count($answers) - $n,
            'accuracy' => $n ? $correct / $n : null,
            'caught' => $false ? count(array_filter($false, fn ($a) => $a['p'] < $threshold)) / count($false) : null,
            'false_alarms' => $true ? count(array_filter($true, fn ($a) => $a['p'] < $threshold)) / count($true) : null,
            'ece' => $n ? $ece : null,
        ];
    }

    /** @return array<string, array{string, string}> */
    private function judges(): array
    {
        $default = config('documents.judge.url');
        $specs = $this->option('judge') ?: ['jev-q4='.config('documents.judge.model')];
        $judges = [];

        foreach ($specs as $spec) {
            [$label, $rest] = array_pad(explode('=', $spec, 2), 2, $spec);
            [$model, $url] = array_pad(explode('@', $rest, 2), 2, $default);
            $judges[$label] = [$model, $url];
        }

        return $judges;
    }

    /** @return array<string, array{type: string, expected: array<string, mixed>, pages: array<string, string>}> */
    private function fixtures(): array
    {
        $only = array_filter(explode(',', (string) $this->option('only')));
        $fixtures = [];

        foreach (File::directories(base_path($this->option('fixtures'))) as $dir) {
            $name = basename($dir);
            if ($only && ! in_array($name, $only, true)) {
                continue;
            }
            $meta = json_decode(File::get("{$dir}/meta.json"), true);
            $pages = [];
            foreach (['front', 'back'] as $side) {
                if ($match = glob("{$dir}/{$side}.{png,jpg,jpeg}", GLOB_BRACE)) {
                    $pages[$side] = $match[0];
                }
            }
            $fixtures[$name] = ['type' => $meta['type'], 'expected' => $meta['expected'], 'pages' => $pages];
        }

        return $fixtures;
    }

    private function pct(?float $v): string
    {
        return $v === null ? '—' : number_format($v * 100, 1).'%';
    }
}
