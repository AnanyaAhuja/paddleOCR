<x-documents::layout title="Review documents">
    <div class="mb-6 flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-semibold">Review documents</h1>
        <span class="text-sm">
            <a href="{{ route('reviews.summary') }}" class="mr-3 font-medium text-stone-700 underline">Summary</a>
            @include('reviews._privacy')
        </span>
    </div>

    <p class="mb-4 text-sm text-stone-600">
        {{ $counts['total'] }} documents · {{ $counts['done'] }} processed · {{ $counts['pending'] }} in progress ·
        {{ $counts['failed'] }} failed · {{ $counts['reviewed'] }} reviewed
    </p>

    @if (session('saved'))
        <p class="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('saved') }}</p>
    @endif

    <nav class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (\App\Http\Controllers\ReviewController::FILTERS as $key => $label)
            <a href="{{ route('reviews.index', ['show' => $key]) }}"
               @class(['rounded-full border px-3 py-1', 'border-stone-900 bg-stone-900 text-white' => $filter === $key, 'border-stone-300 bg-white' => $filter !== $key])>{{ $label }}</a>
        @endforeach
    </nav>

    <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white">
        <table class="w-full text-sm">
            <thead class="border-b border-stone-200 text-left text-xs uppercase tracking-wide text-stone-500">
                <tr><th class="px-3 py-2">Case</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Checks</th><th class="px-3 py-2">Review</th></tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php($statuses = collect($row->fieldStatuses())->countBy())
                    <tr class="border-b border-stone-100 last:border-0">
                        <td class="px-3 py-2 font-mono text-xs">
                            <a href="{{ route('reviews.show', $row) }}" class="underline">{{ $row->batch }} / {{ $row->case_number }}</a>
                            <span class="text-stone-400">{{ $row->back_path ? 'front + back' : 'one image' }}</span>
                        </td>
                        <td class="px-3 py-2">{{ $row->status }}</td>
                        <td class="px-3 py-2 text-xs">
                            @if ($row->status === 'done')
                                <span class="text-emerald-700">{{ $statuses['verified'] ?? 0 }} verified</span> ·
                                <span class="text-sky-700">{{ $statuses['confident'] ?? 0 }} confident</span> ·
                                <span class="text-amber-700">{{ $statuses['flagged'] ?? 0 }} flagged</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs">{{ $row->reviewed_at ? 'Reviewed' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-3 py-6 text-center text-stone-500">Nothing here yet. Import with <code>make artisan cmd='documents:import'</code>.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-documents::layout>
