<x-documents::layout :title="'Review '.$row->case_number">
    <div class="mb-4 flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-semibold">{{ $row->batch }} / {{ $row->case_number }}</h1>
        <span class="text-sm">
            <span class="mr-3 rounded-full bg-emerald-50 px-2.5 py-1 font-medium text-emerald-800">{{ $progress['reviewed'] }} of {{ $progress['done'] }} reviewed</span>
            <a href="{{ route('reviews.summary') }}" class="mr-3 underline">Summary</a>
            <a href="{{ route('reviews.index') }}" class="underline">All documents</a>
        </span>
    </div>

    @if (session('saved'))
        <p class="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('saved') }}</p>
    @endif

    <section class="mb-6 grid gap-3 sm:grid-cols-2">
        @foreach (array_filter(['front' => $row->front_path, 'back' => $row->back_path]) as $side => $path)
            <figure class="rounded-xl border border-stone-200 bg-white p-2">
                <div class="mb-1 flex items-center justify-between text-xs text-stone-500">
                    <span>{{ ucfirst($side) }}</span>
                    <button type="button" class="rounded border border-stone-300 px-2 py-0.5" onclick="rotate(this)">Rotate</button>
                </div>
                <a href="{{ route('reviews.image', [$row, $side]) }}" target="_blank" class="block overflow-hidden">
                    <img src="{{ route('reviews.image', [$row, $side]) }}" alt="{{ $side }}" data-angle="0"
                         class="mx-auto max-h-[28rem] w-auto transition-transform">
                </a>
            </figure>
        @endforeach
    </section>

    @if ($row->status !== 'done')
        <p class="rounded-xl border border-stone-200 bg-white p-4 text-sm">Status: {{ $row->status }} {{ $row->error }}</p>
    @else
        <form method="POST" action="{{ route('reviews.update', $row) }}" class="space-y-4">
            @csrf
            @if ($errors->any())
                <p class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm font-medium text-red-800">Not saved: {{ $errors->first() }} Rows still missing an answer are highlighted.</p>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-stone-600">
                <span>
                    <span class="font-medium text-emerald-700">verified</span> = MRZ / fiscal code agree ·
                    <span class="font-medium text-sky-700">confident</span> = JEV ≥ {{ number_format($threshold * 100) }}% ·
                    <span class="font-medium text-amber-700">flagged</span> = a check disagrees or JEV doubts it
                </span>
                <button type="button" onclick="markRemainingCorrect()" class="rounded border border-stone-300 bg-white px-2 py-1">Mark unanswered as correct</button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="border-b border-stone-200 text-left text-xs uppercase tracking-wide text-stone-500">
                        <tr><th class="px-3 py-2">Field</th><th class="px-3 py-2">Extracted</th><th class="px-3 py-2">Checks</th><th class="px-3 py-2">Your verdict</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($definition['fields'] as $key => $field)
                            @php($value = $row->fields[$key] ?? null)
                            @php($status = $statuses[$key] ?? 'unchecked')
                            @php($p = $row->judge['fields'][$key]['p_true'] ?? null)
                            @php($by = $row->checks[$key]['by'] ?? [])
                            @php($verdict = old("review.$key.verdict", $row->review[$key]['verdict'] ?? null))
                            <tr @class(['border-b border-stone-100 align-top last:border-0', 'bg-red-50' => $errors->any() && $verdict === null])>
                                <th class="px-3 py-2 text-left font-medium text-stone-600">{{ str_replace('_', ' ', $key) }}</th>
                                <td class="px-3 py-2 font-mono text-xs">{{ $value ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs">
                                    <span @class(['rounded px-1.5 py-0.5 font-medium',
                                        'bg-emerald-50 text-emerald-800' => $status === 'verified',
                                        'bg-sky-50 text-sky-800' => $status === 'confident',
                                        'bg-amber-50 text-amber-800' => $status === 'flagged',
                                        'bg-stone-100 text-stone-600' => $status === 'unchecked'])>{{ $status }}</span>
                                    @foreach ($by as $source => $ok)
                                        <span class="ml-1 {{ $ok ? 'text-emerald-700' : 'text-red-700' }}">{{ str_replace('_', ' ', $source) }} {{ $ok ? '✓' : '✗' }}</span>
                                    @endforeach
                                    @if ($p !== null)
                                        <span class="ml-1 text-stone-500">JEV {{ number_format($p * 100) }}%</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <label class="mr-2 whitespace-nowrap"><input type="radio" name="review[{{ $key }}][verdict]" value="correct" @checked($verdict === 'correct') required> Correct</label>
                                    <label class="whitespace-nowrap"><input type="radio" name="review[{{ $key }}][verdict]" value="wrong" @checked($verdict === 'wrong')> Wrong</label>
                                    <input type="text" name="review[{{ $key }}][corrected]" placeholder="Correct value (if wrong)"
                                           value="{{ old("review.$key.corrected", $row->review[$key]['corrected'] ?? '') }}"
                                           class="mt-1 block w-full rounded border border-stone-300 px-2 py-1">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <label class="block text-sm">
                <span class="mb-1 block font-medium">Note (optional)</span>
                <textarea name="note" rows="2" class="w-full rounded-md border border-stone-300 px-3 py-2">{{ old('note', $row->review['_note'] ?? '') }}</textarea>
            </label>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white hover:bg-stone-700">Save and next</button>
                <span class="text-xs text-stone-500">Every field needs Correct or Wrong. Tip: mark the wrong ones, then “Mark unanswered as correct”.</span>
            </div>
        </form>

        <details class="mt-8 text-xs text-stone-600">
            <summary class="cursor-pointer font-medium">OCR text, MRZ and timings</summary>
            @foreach ($row->ocr ?? [] as $side => $page)
                <h3 class="mt-3 font-semibold">{{ $side }} · confidence {{ $page['confidence'] }} {{ ($page['orientation_retried'] ?? false) ? '· rotated' : '' }}</h3>
                <pre class="mt-1 whitespace-pre-wrap rounded bg-stone-100 p-3">{{ $page['text'] }}</pre>
            @endforeach
            <h3 class="mt-3 font-semibold">MRZ</h3>
            <pre class="mt-1 whitespace-pre-wrap rounded bg-stone-100 p-3">{{ json_encode($row->checks['_mrz'] ?? null, JSON_PRETTY_PRINT) }}</pre>
            <p class="mt-3">OCR {{ round(($row->timings['ocr_ms'] ?? 0) / 1000, 1) }}s · Qwen {{ round(($row->timings['vlm_ms'] ?? 0) / 1000, 1) }}s · JEV {{ round(($row->timings['judge_ms'] ?? 0) / 1000, 1) }}s · {{ $row->model }} {{ isset($row->judge['error']) ? '· judge error: '.$row->judge['error'] : '' }}</p>
        </details>
    @endif

    <script>
        function rotate(button) {
            const img = button.closest('figure').querySelector('img');
            const angle = (parseInt(img.dataset.angle) + 90) % 360;
            img.dataset.angle = angle;
            img.style.transform = `rotate(${angle}deg)`;
        }
        function markRemainingCorrect() {
            const names = new Set([...document.querySelectorAll('input[type=radio][name$="[verdict]"]')].map(r => r.name));
            names.forEach(name => {
                if (!document.querySelector(`input[name="${name}"]:checked`)) {
                    document.querySelector(`input[name="${name}"][value=correct]`).checked = true;
                }
            });
        }
    </script>
</x-documents::layout>
