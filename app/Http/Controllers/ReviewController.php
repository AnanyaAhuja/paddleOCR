<?php

namespace App\Http\Controllers;

use App\Documents\PrivacyMasks;
use App\Documents\ReviewStats;
use App\Models\DocumentExtraction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Manual verification of stored extractions, and the summary of how the
 * pipeline and its automatic checks did against those reviews.
 */
class ReviewController extends Controller
{
    public const FILTERS = [
        'to-review' => 'To review',
        'flagged' => 'Flagged',
        'reviewed' => 'Reviewed',
        'failed' => 'Failed',
        'all' => 'All',
    ];

    public function index(Request $request): View
    {
        $filter = array_key_exists($request->query('show'), self::FILTERS) ? $request->query('show') : 'to-review';

        // Only what the list shows: the OCR text is large and not needed here.
        $query = DocumentExtraction::query()
            ->select(['id', 'batch', 'case_number', 'status', 'back_path', 'fields', 'checks', 'judge', 'reviewed_at'])
            ->orderBy('batch')->orderBy('case_number');

        match ($filter) {
            'to-review' => $query->where('status', 'done')->unreviewed(),
            'flagged' => $query->where('status', 'done'),
            'reviewed' => $query->reviewed(),
            'failed' => $query->where('status', 'failed'),
            default => $query,
        };

        $rows = $query->get();
        if ($filter === 'flagged') {
            // Field statuses depend on the judge threshold in config, so filter in PHP.
            $rows = $rows->filter->needsReview();
        }

        $byStatus = DocumentExtraction::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('reviews.index', [
            'rows' => $rows,
            'filter' => $filter,
            'counts' => [
                'total' => $byStatus->sum(),
                'done' => $byStatus['done'] ?? 0,
                'pending' => ($byStatus['pending'] ?? 0) + ($byStatus['processing'] ?? 0),
                'failed' => $byStatus['failed'] ?? 0,
                'reviewed' => DocumentExtraction::query()->reviewed()->count(),
            ],
        ]);
    }

    public function show(DocumentExtraction $extraction, PrivacyMasks $privacy): View
    {
        return view('reviews.show', [
            'row' => $extraction,
            'privacy' => $privacy->enabledInSession(),
            'masks' => $privacy,
            'definition' => config("documents.types.{$extraction->document_type}"),
            'statuses' => $extraction->fieldStatuses(),
            'threshold' => (float) config('documents.judge.threshold'),
            'progress' => [
                'reviewed' => DocumentExtraction::query()->reviewed()->count(),
                'done' => DocumentExtraction::query()->where('status', 'done')->count(),
            ],
        ]);
    }

    public function update(Request $request, DocumentExtraction $extraction, PrivacyMasks $privacy): RedirectResponse
    {
        // Values are hidden in privacy mode, so a verdict would be a guess.
        abort_if($privacy->enabledInSession(), 403, 'Turn privacy mode off to review.');

        $fields = array_keys(config("documents.types.{$extraction->document_type}.fields"));

        $validated = $request->validate([
            'review' => ['required', 'array'],
            'review.*.verdict' => ['required', 'in:correct,wrong'],
            'review.*.corrected' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'review.*.verdict.required' => 'Mark every field as correct or wrong.',
        ]);

        $review = collect($validated['review'])
            ->only($fields)
            ->map(fn ($r) => [
                'verdict' => $r['verdict'],
                'corrected' => $r['verdict'] === 'wrong' ? ($r['corrected'] ?? null) : null,
            ])
            ->all();

        if (count($review) !== count($fields)) {
            return back()->withErrors(['review' => 'Mark every field as correct or wrong.'])->withInput();
        }

        $extraction->update([
            'review' => $review + ['_note' => $validated['note'] ?? null],
            'reviewed_at' => now(),
        ]);

        $next = DocumentExtraction::query()
            ->where('status', 'done')->unreviewed()
            ->orderBy('batch')->orderBy('case_number')
            ->first(['id']);

        return $next
            ? redirect()->route('reviews.show', $next)->with('saved', "Saved {$extraction->batch}/{$extraction->case_number}.")
            : redirect()->route('reviews.summary')->with('saved', 'All processed documents are reviewed.');
    }

    /**
     * The original photo, or with ?redacted=1 its privacy-masked copy. In
     * privacy mode only masked copies are served, never the original. The two
     * have different URLs, so a cached original is never shown in its place.
     */
    public function image(Request $request, DocumentExtraction $extraction, string $side, PrivacyMasks $privacy): BinaryFileResponse
    {
        $redacted = $request->boolean('redacted');
        abort_if($privacy->enabledInSession() && ! $redacted, 403, 'Privacy mode shows masked copies only.');

        $path = $redacted
            ? ($extraction->ocr[$side]['redacted']['path'] ?? null)
            : match ($side) {
                'front' => $extraction->front_path,
                'back' => $extraction->back_path,
                default => null,
            };

        abort_if($path === null, 404);

        return response()->file(Storage::disk(config('documents.disk'))->path($path), [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** Switch privacy mode for this browser session. */
    public function privacy(Request $request, PrivacyMasks $privacy): RedirectResponse
    {
        $request->session()->put(PrivacyMasks::SESSION_KEY, ! $privacy->enabledInSession());

        return back();
    }

    public function summary(ReviewStats $stats): View
    {
        $reviewed = DocumentExtraction::query()->reviewed()->get(['fields', 'checks', 'judge', 'review']);
        $done = DocumentExtraction::query()->where('status', 'done')->get(['checks', 'timings']);

        return view('reviews.summary', [
            'stats' => $stats->compute($reviewed),
            'timings' => [
                'ocr' => $done->avg(fn ($r) => $r->timings['ocr_ms'] ?? null),
                'vlm' => $done->avg(fn ($r) => $r->timings['vlm_ms'] ?? null),
                'judge' => $done->avg(fn ($r) => $r->timings['judge_ms'] ?? null),
                'n' => $done->count(),
            ],
            'mrzFound' => $done->filter(fn ($r) => ! empty($r->checks['_mrz']))->count(),
            'definition' => config('documents.types.it_id_card'),
        ]);
    }
}
