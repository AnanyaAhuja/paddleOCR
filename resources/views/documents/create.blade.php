<x-documents::layout title="Extract a document">
    <h1 class="mb-1 text-2xl font-semibold">Extract a document</h1>
    <p class="mb-6 text-sm text-stone-600">
        PaddleOCR reads each side, then Qwen returns the fields as JSON. Add the back when the document has one.
    </p>

    <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data"
          class="space-y-5 rounded-xl border border-stone-200 bg-white p-6">
        @csrf

        <label class="block">
            <span class="mb-1 block text-sm font-medium">Document type</span>
            <select name="type" class="w-full rounded-md border border-stone-300 px-3 py-2">
                @foreach ($types as $key => $type)
                    <option value="{{ $key }}" @selected(old('type') === $key)>{{ $type['label'] }}</option>
                @endforeach
            </select>
        </label>

        <div class="grid gap-5 sm:grid-cols-2">
            <label class="block">
                <span class="mb-1 block text-sm font-medium">Front</span>
                <input type="file" name="front" accept="image/*" required class="block w-full text-sm">
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium">Back <span class="text-stone-500">(optional)</span></span>
                <input type="file" name="back" accept="image/*" class="block w-full text-sm">
            </label>
        </div>

        @if ($errors->any())
            <p class="text-sm text-red-700">{{ $errors->first() }}</p>
        @endif

        <button type="submit" class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white hover:bg-stone-700">
            Extract
        </button>
    </form>
</x-documents::layout>
