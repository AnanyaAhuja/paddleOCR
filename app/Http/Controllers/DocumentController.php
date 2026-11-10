<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DocumentController extends Controller
{
    public function create(): View
    {
        return view('documents.create', ['types' => config('documents.types')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(config('documents.types')))],
            'front' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
            'back' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
        ]);

        $id = (string) Str::ulid();
        $directory = config('documents.directory')."/{$id}";
        $pages = [];

        foreach (['front', 'back'] as $side) {
            if ($file = $request->file($side)) {
                $pages[$side] = $file->storeAs($directory, $side.'.'.$file->extension(), config('documents.disk'));
            }
        }

        Cache::put(ExtractDocument::cacheKey($id), [
            'status' => 'pending',
            'type' => $validated['type'],
            'sides' => array_keys($pages),
            'created_at' => now()->toIso8601String(),
        ], config('documents.result_ttl'));

        ExtractDocument::dispatch($id, $validated['type'], $pages);

        return redirect()->route('documents.show', $id);
    }

    public function show(string $id): View
    {
        $document = Cache::get(ExtractDocument::cacheKey($id));

        abort_if($document === null, 404);

        return view('documents.show', [
            'id' => $id,
            'document' => $document,
            'definition' => config("documents.types.{$document['type']}"),
        ]);
    }
}
