<?php

namespace App\Console\Commands;

use App\Documents\DocumentExtractor;
use App\Documents\Scorer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Runs every fixture document through each mode and model, scores the output
 * against the fixture's ground truth and writes the results to
 * storage/app/benchmarks/.
 *
 * Fixture layout: tests/fixtures/documents/<name>/{front,back}.(png|jpg) + meta.json
 */
class BenchmarkDocuments extends Command
{
    protected $signature = 'documents:benchmark
        {--only= : Comma-separated fixture names}
        {--modes=ocr+image,image,ocr-text : Comma-separated modes}
        {--models= : Comma-separated model names (default: the configured one)}
        {--fixtures=tests/fixtures/documents : Fixture directory}';

    protected $description = 'Benchmark PaddleOCR + Qwen extraction on the fixture documents';

    public function handle(DocumentExtractor $extractor, Scorer $scorer): int
    {
        $fixtures = $this->fixtures();
        $modes = $this->listOption('modes');
        $models = $this->listOption('models') ?: [config('services.qwen_vl.model')];
        $runs = [];

        // OCR every document once up front; every mode and model reuses it.
        $ocr = [];
        $coverage = [];
        foreach ($fixtures as $name => $fixture) {
            $ocr[$name] = $extractor->ocrPages($fixture['pages']);
            $coverage[$name] = $scorer->ocrCoverage(
                $fixture['meta']['expected'],
                implode("\n", array_column($ocr[$name], 'text')),
            );
            $this->line(sprintf('OCR %-22s %s  %5.1fs  %3d%% of expected values in OCR text',
                $name, implode('+', array_keys($fixture['pages'])),
                array_sum(array_column($ocr[$name], 'ms')) / 1000, $coverage[$name]['coverage'] * 100));
        }

        // One model at a time, so Model Runner loads each model once.
        foreach ($models as $model) {
            $this->newLine();
            $this->info($model);

            foreach ($fixtures as $name => $fixture) {
                $this->line($name);

                foreach ($modes as $mode) {
                    $runs[] = $this->runOne($extractor, $scorer, $name, $fixture, $mode, $model, $ocr[$name], $coverage[$name]);
                }
            }
        }

        $path = $this->write($runs);
        $this->summary($runs);
        $this->info("Results written to {$path}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @param  array<string, mixed>  $ocr
     * @param  array<string, mixed>  $coverage
     * @return array<string, mixed>
     */
    private function runOne(DocumentExtractor $extractor, Scorer $scorer, string $name, array $fixture, string $mode, string $model, array $ocr, array $coverage): array
    {
        $run = [
            'document' => $name,
            'title' => $fixture['meta']['title'],
            'type' => $fixture['meta']['type'],
            'sides' => array_keys($fixture['pages']),
            'tags' => $fixture['meta']['tags'] ?? [],
            'mode' => $mode,
            'model' => $model,
            'ocr_coverage' => $coverage,
            'ocr_text' => array_map(fn ($page) => $page['text'], $ocr),
            'expected' => $fixture['meta']['expected'],
        ];

        try {
            $result = $extractor->extract($fixture['meta']['type'], $fixture['pages'], $mode, $model, $ocr);
            $score = $scorer->score($fixture['meta']['expected'], $result['fields']);

            $run += [
                'ok' => true,
                'fields' => $result['fields'],
                'score' => $score['score'],
                'field_scores' => $score['fields'],
                'timings' => $result['timings'],
                'usage' => $result['usage'],
            ];

            $this->line(sprintf('  %-10s %-28s %5.1f%%  %5.1fs', $mode, $model, $score['score'] * 100, $result['timings']['vlm_ms'] / 1000));
        } catch (Throwable $e) {
            $run += ['ok' => false, 'error' => $e->getMessage(), 'score' => 0.0];
            $this->error("  {$mode} {$model} failed: {$e->getMessage()}");
        }

        return $run;
    }

    /**
     * @return array<string, array{meta: array<string, mixed>, pages: array<string, string>}>
     */
    private function fixtures(): array
    {
        $root = base_path($this->option('fixtures'));
        $only = $this->listOption('only');
        $fixtures = [];

        foreach (File::directories($root) as $directory) {
            $name = basename($directory);

            if ($only !== [] && ! in_array($name, $only, true)) {
                continue;
            }

            $pages = [];
            foreach (['front', 'back'] as $side) {
                $match = glob("{$directory}/{$side}.{png,jpg,jpeg}", GLOB_BRACE);
                if ($match) {
                    $pages[$side] = $match[0];
                }
            }

            $fixtures[$name] = [
                'meta' => json_decode(File::get("{$directory}/meta.json"), true, flags: JSON_THROW_ON_ERROR),
                'pages' => $pages,
            ];
        }

        return $fixtures;
    }

    /** @param  list<array<string, mixed>>  $runs */
    private function write(array $runs): string
    {
        $directory = storage_path('app/benchmarks');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/run-'.now()->format('Ymd-His').'.json';

        File::put($path, json_encode([
            'created_at' => now()->toIso8601String(),
            'runs' => $runs,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /** @param  list<array<string, mixed>>  $runs */
    private function summary(array $runs): void
    {
        $rows = collect($runs)
            ->groupBy(fn ($run) => $run['model'].'|'.$run['mode'])
            ->map(function ($group, $key) {
                [$model, $mode] = explode('|', $key);
                $ok = $group->where('ok', true);

                return [
                    $model,
                    $mode,
                    sprintf('%.1f%%', $group->avg('score') * 100),
                    sprintf('%.1fs', $ok->avg(fn ($run) => $run['timings']['vlm_ms']) / 1000),
                    $group->count() - $ok->count(),
                ];
            })
            ->values()
            ->all();

        $this->newLine();
        $this->table(['Model', 'Mode', 'Field accuracy', 'Avg Qwen time', 'Errors'], $rows);
    }

    /** @return list<string> */
    private function listOption(string $name): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->option($name)))));
    }
}
