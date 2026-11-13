<x-documents::layout title="Review summary">
    @php($pct = fn ($v) => $v === null ? '—' : number_format($v * 100, 1).'%')

    <div class="mb-6 flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-semibold">How the flow is doing</h1>
        <a href="{{ route('reviews.index') }}" class="text-sm underline">Review documents</a>
    </div>

    @if (session('saved'))
        <p class="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('saved') }}</p>
    @endif

    <section class="mb-8 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-stone-200 bg-white p-4"><div class="text-2xl font-semibold">{{ $stats['documents'] }}</div><div class="text-xs text-stone-500">documents reviewed</div></div>
        <div class="rounded-xl border border-stone-200 bg-white p-4"><div class="text-2xl font-semibold">{{ $pct($stats['overall']) }}</div><div class="text-xs text-stone-500">fields extracted correctly</div></div>
        <div class="rounded-xl border border-stone-200 bg-white p-4">
            <div class="text-2xl font-semibold">{{ $stats['auto']['trusted'] ? $pct($stats['auto']['trusted_wrong'] / $stats['auto']['trusted']) : '—' }}</div>
            <div class="text-xs text-stone-500">of auto-trusted fields were actually wrong ({{ $stats['auto']['trusted_wrong'] }} of {{ $stats['auto']['trusted'] }})</div>
        </div>
    </section>

    <h2 class="mb-2 text-lg font-semibold">Accuracy by field</h2>
    <div class="mb-8 overflow-x-auto rounded-xl border border-stone-200 bg-white">
        <table class="w-full text-sm">
            <thead class="border-b border-stone-200 text-left text-xs uppercase tracking-wide text-stone-500"><tr><th class="px-3 py-2">Field</th><th class="px-3 py-2 text-right">Reviewed</th><th class="px-3 py-2 text-right">Correct</th></tr></thead>
            <tbody>
                @foreach (array_keys($definition['fields']) as $field)
                    @php($s = $stats['fields'][$field] ?? null)
                    <tr class="border-b border-stone-100 last:border-0"><td class="px-3 py-2">{{ str_replace('_', ' ', $field) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $s['n'] ?? 0 }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $pct($s['accuracy'] ?? null) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <h2 class="mb-2 text-lg font-semibold">Did the automatic checks catch the mistakes?</h2>
    <p class="mb-3 text-sm text-stone-600">“Caught” = of the values you marked wrong, how many the check had flagged. “Right when flagging” = of the values it flagged, how many really were wrong.</p>
    <div class="mb-8 overflow-x-auto rounded-xl border border-stone-200 bg-white">
        <table class="w-full text-sm">
            <thead class="border-b border-stone-200 text-left text-xs uppercase tracking-wide text-stone-500">
                <tr><th class="px-3 py-2">Check</th><th class="px-3 py-2 text-right">Fields</th><th class="px-3 py-2 text-right">Wrong values caught</th><th class="px-3 py-2 text-right">Missed</th><th class="px-3 py-2 text-right">False alarms</th><th class="px-3 py-2 text-right">Caught</th><th class="px-3 py-2 text-right">Right when flagging</th></tr>
            </thead>
            <tbody>
                @foreach (['rules' => 'MRZ + fiscal code (rules)', 'judge' => 'JEV judge (threshold '.number_format($stats['threshold'] * 100).'%)'] as $key => $label)
                    @php($c = $stats[$key])
                    <tr class="border-b border-stone-100 last:border-0">
                        <td class="px-3 py-2">{{ $label }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $c['n'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $c['tp'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $c['fn'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $c['fp'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $pct($c['recall']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $pct($c['precision']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <h2 class="mb-2 text-lg font-semibold">Is JEV's confidence honest?</h2>
    <p class="mb-3 text-sm text-stone-600">For each confidence range: how sure JEV said it was, and how often the value was really correct. Well calibrated means the two columns match.</p>
    <div class="mb-8 overflow-x-auto rounded-xl border border-stone-200 bg-white">
        <table class="w-full text-sm">
            <thead class="border-b border-stone-200 text-left text-xs uppercase tracking-wide text-stone-500"><tr><th class="px-3 py-2">JEV said</th><th class="px-3 py-2 text-right">Fields</th><th class="px-3 py-2 text-right">Average confidence</th><th class="px-3 py-2 text-right">Actually correct</th></tr></thead>
            <tbody>
                @forelse ($stats['calibration'] as $b)
                    <tr class="border-b border-stone-100 last:border-0"><td class="px-3 py-2">{{ $b['range'] }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $b['n'] }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $pct($b['mean_p']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $pct($b['actual']) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="px-3 py-4 text-center text-stone-500">No reviewed fields with a JEV score yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="text-xs text-stone-500">
        Processed {{ $timings['n'] }} documents · MRZ found on {{ $mrzFound }} ·
        average OCR {{ round(($timings['ocr'] ?? 0) / 1000, 1) }}s, Qwen {{ round(($timings['vlm'] ?? 0) / 1000, 1) }}s, JEV {{ round(($timings['judge'] ?? 0) / 1000, 1) }}s per document
    </p>
</x-documents::layout>
