<?php

namespace App\Documents;

use App\Models\DocumentExtraction;
use Illuminate\Support\Facades\Storage;

/**
 * Privacy-masked copies of document pages (made by the OCR service, which
 * blacks out all text except known labels, faces and barcodes) and masked
 * field values, for showing documents without personal data.
 *
 * Stateless: safe under Octane.
 */
class PrivacyMasks
{
    public const SESSION_KEY = 'documents.privacy_mode';

    /**
     * The labels a document type's masked copy may show, or null when no
     * masked copy should be made.
     *
     * @return list<string>|null
     */
    public function keepLabels(string $type): ?array
    {
        if (! config('documents.privacy_enabled')) {
            return null;
        }

        // A type without a label list gets every line of text covered.
        return config("documents.types.{$type}.privacy.keep", []);
    }

    /**
     * Save the masked copies from an OCR result on the documents disk and
     * replace the image data with its path.
     *
     * @param  array<string, array<string, mixed>>  $ocr  side => OCR result
     * @return array<string, array<string, mixed>>
     */
    public function store(DocumentExtraction $row, array $ocr): array
    {
        $disk = Storage::disk(config('documents.disk'));

        foreach ($ocr as $side => $page) {
            $image = $page['redacted']['image'] ?? null;
            if ($image === null) {
                continue;
            }

            unset($ocr[$side]['redacted']['image']);
            if ($image !== '') {
                $path = "redacted/{$row->id}/{$side}.jpg";
                $disk->put($path, base64_decode($image));
                $ocr[$side]['redacted']['path'] = $path;
            }
        }

        return $ocr;
    }

    /** "ROSSI" -> "R••••": enough to tell fields apart, not enough to identify anyone. */
    public function maskValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $value = (string) $value;

        return mb_substr($value, 0, 1).str_repeat('•', max(2, mb_strlen($value) - 1));
    }

    public function enabledInSession(): bool
    {
        return (bool) session(self::SESSION_KEY, false);
    }
}
