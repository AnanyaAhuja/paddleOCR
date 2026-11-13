@php($privacyOn = app(\App\Documents\PrivacyMasks::class)->enabledInSession())
<form method="POST" action="{{ route('reviews.privacy') }}" class="inline">
    @csrf
    <button type="submit" @class(['rounded-full border px-3 py-1 text-xs font-medium',
        'border-stone-900 bg-stone-900 text-white' => $privacyOn,
        'border-stone-300 bg-white text-stone-700' => ! $privacyOn])>
        Privacy mode: {{ $privacyOn ? 'on' : 'off' }}
    </button>
</form>
@if ($privacyOn)
    <p class="mb-4 mt-3 rounded-md bg-stone-900 px-3 py-2 text-sm text-white">
        Privacy mode is on: photos show masked copies (text, faces and barcodes blacked out) and values are hidden. Safe for screen sharing; turn it off to review.
    </p>
@endif
