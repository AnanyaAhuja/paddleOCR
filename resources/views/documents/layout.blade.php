<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @stack('head')
        <title>{{ $title ?? 'Documents' }} · {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-stone-50 text-stone-900 antialiased">
        <main class="mx-auto max-w-3xl px-4 py-10">
            <header class="mb-8 flex items-baseline justify-between gap-4">
                <a href="{{ route('documents.create') }}" class="text-lg font-semibold">PaddleOCR + Qwen</a>
                <span class="text-xs text-stone-500">{{ config('services.qwen_vl.model') }}</span>
            </header>
            {{ $slot }}
        </main>
    </body>
</html>
