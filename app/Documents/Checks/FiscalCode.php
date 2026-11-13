<?php

namespace App\Documents\Checks;

/**
 * Italian codice fiscale: 16 characters encoding surname, name, birth date,
 * sex and birthplace, plus a check letter.
 */
class FiscalCode
{
    private const ODD = [
        '0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9, '5' => 13, '6' => 15, '7' => 17, '8' => 19, '9' => 21,
        'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9, 'F' => 13, 'G' => 15, 'H' => 17, 'I' => 19, 'J' => 21,
        'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20, 'O' => 11, 'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14,
        'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23,
    ];

    private const MONTHS = 'ABCDEHLMPRST';

    // Omocodia: digits replaced by these letters when two people share a code.
    private const OMOCODIA = 'LMNPQRSTUV';

    public function isValid(string $code): bool
    {
        $code = $this->normalize($code);

        if (! preg_match('/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $code)) {
            return false;
        }

        return $this->checkLetter(substr($code, 0, 15)) === $code[15];
    }

    public function checkLetter(string $first15): string
    {
        $sum = 0;
        foreach (str_split($first15) as $i => $char) {
            // Positions are 1-based in the spec: 1st, 3rd, ... use the odd table.
            $sum += $i % 2 === 0 ? self::ODD[$char] : (ctype_digit($char) ? (int) $char : ord($char) - 65);
        }

        return chr(65 + $sum % 26);
    }

    /**
     * What the code says about its holder (only meaningful when isValid()).
     *
     * @return array{surname: string, name: string, birth_year_2d: string, birth_month: int, birth_day: int, sex: string}
     */
    public function decode(string $code): array
    {
        $code = $this->normalize($code);
        $digits = fn (string $s) => strtr($s, array_combine(str_split(self::OMOCODIA), range(0, 9)));
        $day = (int) $digits(substr($code, 9, 2));

        return [
            'surname' => substr($code, 0, 3),
            'name' => substr($code, 3, 3),
            'birth_year_2d' => $digits(substr($code, 6, 2)),
            'birth_month' => strpos(self::MONTHS, $code[8]) + 1,
            'birth_day' => $day > 40 ? $day - 40 : $day,
            'sex' => $day > 40 ? 'F' : 'M',
        ];
    }

    /** The 3-letter code for a surname: consonants, then vowels, padded with X. */
    public function surnameCode(string $surname): string
    {
        [$consonants, $vowels] = $this->letters($surname);

        return substr($consonants.$vowels.'XXX', 0, 3);
    }

    /** The 3-letter code for given names: with 4+ consonants, the 1st, 3rd and 4th. */
    public function nameCode(string $name): string
    {
        [$consonants, $vowels] = $this->letters($name);

        if (strlen($consonants) >= 4) {
            return $consonants[0].$consonants[2].$consonants[3];
        }

        return substr($consonants.$vowels.'XXX', 0, 3);
    }

    public function normalize(string $code): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    }

    /** @return array{string, string} consonants and vowels, accents stripped */
    private function letters(string $value): array
    {
        $ascii = strtoupper(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $value) ?: $value));

        return [preg_replace('/[AEIOU]/', '', $ascii), preg_replace('/[^AEIOU]/', '', $ascii)];
    }
}
