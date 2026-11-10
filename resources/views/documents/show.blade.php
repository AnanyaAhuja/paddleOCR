<x-documents::layout :title="$definition['label']">
    @if ($document['status'] === 'pending')
        @push('head')
            <meta http-equiv="refresh" content="3">
        @endpush
    @endif

    <h1 class="mb-1 text-2xl font-semibold">{{ $definition['label'] }}</h1>
    <p class="mb-6 text-sm text-stone-600">
        {{ implode(' + ', array_map('ucfirst', $document['sides'])) }} · {{ $id }}
    </p>

    @if ($document['status'] === 'pending')
        <div class="rounded-xl border border-stone-200 bg-white p-6 text-sm">
            Reading the document… this page refreshes every few seconds.
        </div>
    @elseif ($document['status'] === 'failed')
        <div class="rounded-xl border border-red-200 bg-red-50 p-6 text-sm text-red-800">
            Extraction failed: {{ $document['error'] }}
        </div>
    @else
        @php($result = $document['result'])

        <section class="mb-8 overflow-hidden rounded-xl border border-stone-200 bg-white">
            <table class="w-full text-sm">
                @foreach ($definition['fields'] as $key => $field)
                    @php($value = $result['fields'][$key] ?? null)
                    <tr class="border-b border-stone-100 last:border-0">
                        <th class="w-1/3 px-4 py-2 text-left font-medium text-stone-500">{{ str_replace('_', ' ', $key) }}</th>
                        <td class="px-4 py-2">
                            @if ($value === null || $value === [])
                                <span class="text-stone-400">—</span>
                            @elseif (is_array($value))
                                <pre class="text-xs whitespace-pre-wrap">{{ json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            @else
                                {{ $value }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </section>

        <p class="mb-8 text-xs text-stone-500">
            OCR {{ number_format($result['timings']['ocr_ms'] / 1000, 1) }}s ·
            Qwen {{ number_format($result['timings']['vlm_ms'] / 1000, 1) }}s ·
            {{ $result['usage']['prompt_tokens'] }} prompt / {{ $result['usage']['completion_tokens'] }} output tokens ·
            {{ $result['model'] }}
        </p>

        @foreach ($result['ocr'] ?? [] as $side => $text)
            <h2 class="mb-2 text-sm font-semibold">OCR text: {{ $side }}</h2>
            <pre class="mb-6 rounded-xl bg-stone-100 p-4 text-xs whitespace-pre-wrap">{{ $text }}</pre>
        @endforeach
    @endif
</x-documents::layout>
