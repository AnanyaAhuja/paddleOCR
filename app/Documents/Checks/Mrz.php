<?php

namespace App\Documents\Checks;

use Carbon\CarbonImmutable;

/**
 * Reads the 3-line machine-readable zone (ICAO 9303 TD1, used on ID cards)
 * and verifies its check digits.
 */
class Mrz
{
    /**
     * Find the three MRZ lines in OCR text and parse them.
     *
     * @return array<string, mixed>|null null when no TD1 MRZ was found
     */
    public function fromText(string $text): ?array
    {
        $candidates = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $clean = $this->clean($line);
            if (strlen($clean) >= 25 && strlen($clean) <= 34 && substr_count($clean, '<') >= 2) {
                $candidates[] = $clean;
            }
        }

        // Look for line 1 (starts with a document code) followed by two more.
        for ($i = 0; $i + 2 < count($candidates); $i++) {
            if (preg_match('/^[ACI][A-Z<]/', $candidates[$i])) {
                return $this->parse(array_slice($candidates, $i, 3));
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    public function parse(array $lines): array
    {
        [$one, $two, $three] = array_map(fn ($l) => str_pad(substr($l, 0, 30), 30, '<'), $lines);

        $number = substr($one, 5, 9);
        $birth = $this->digits(substr($two, 0, 6));
        $expiry = $this->digits(substr($two, 8, 6));
        $composite = substr($one, 5, 25).substr($two, 0, 7).substr($two, 8, 7).substr($two, 18, 11);

        [$surname, $given] = array_pad(explode('<<', trim($three, '<'), 2), 2, '');

        return [
            'lines' => [$one, $two, $three],
            'document_number' => rtrim($number, '<'),
            'date_of_birth' => $this->date($birth, birth: true),
            'sex' => in_array($two[7], ['M', 'F'], true) ? $two[7] : null,
            'expiry_date' => $this->date($expiry, birth: false),
            'nationality' => rtrim(substr($two, 15, 3), '<'),
            'surname' => str_replace('<', ' ', trim($surname, '<')),
            'given_names' => str_replace('<', ' ', trim($given, '<')),
            'valid' => [
                'document_number' => $this->checkDigit($number) === $this->digits($one[14]),
                'date_of_birth' => $this->checkDigit($birth) === $this->digits($two[6]),
                'expiry_date' => $this->checkDigit($expiry) === $this->digits($two[14]),
                'composite' => $this->checkDigit($composite) === $this->digits($two[29]),
            ],
        ];
    }

    /** ICAO 9303 check digit: weights 7, 3, 1; A-Z = 10-35; '<' = 0. */
    public function checkDigit(string $value): string
    {
        $weights = [7, 3, 1];
        $sum = 0;

        foreach (str_split($value) as $i => $char) {
            $n = match (true) {
                ctype_digit($char) => (int) $char,
                ctype_alpha($char) => ord($char) - 55,
                default => 0,
            };
            $sum += $n * $weights[$i % 3];
        }

        return (string) ($sum % 10);
    }

    private function clean(string $line): string
    {
        $line = strtoupper(str_replace(['«', ' '], ['<<', ''], $line));

        return preg_replace('/[^A-Z0-9<]/', '', $line);
    }

    /** OCR often reads digits in numeric positions as look-alike letters. */
    private function digits(string $value): string
    {
        return strtr($value, ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', 'Z' => '2', 'S' => '5', 'B' => '8', 'G' => '6']);
    }

    private function date(string $yymmdd, bool $birth): ?string
    {
        if (! preg_match('/^\d{6}$/', $yymmdd)) {
            return null;
        }

        $yy = (int) substr($yymmdd, 0, 2);
        $now = (int) now()->format('y');
        // Birth dates are in the past; expiry dates in this century.
        $year = $birth ? ($yy > $now ? 1900 + $yy : 2000 + $yy) : 2000 + $yy;
        $month = (int) substr($yymmdd, 2, 2);
        $day = (int) substr($yymmdd, 4, 2);

        return checkdate($month, $day, $year) ? CarbonImmutable::create($year, $month, $day)->format('Y-m-d') : null;
    }
}
