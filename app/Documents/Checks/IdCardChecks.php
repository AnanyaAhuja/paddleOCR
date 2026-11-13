<?php

namespace App\Documents\Checks;

/**
 * Rule-based checks for an ID card: compares the extracted fields with the
 * MRZ (when its check digits pass) and with what the fiscal code encodes.
 *
 * Each checked field gets ['ok' => bool, 'by' => [source => bool]]: ok is
 * false if any source disagrees, true if at least one agrees.
 */
class IdCardChecks
{
    public function __construct(
        private readonly Mrz $mrz,
        private readonly FiscalCode $fiscalCode,
    ) {}

    /**
     * @param  array<string, mixed>  $fields
     * @return array{fields: array<string, array{ok: bool, by: array<string, bool>}>, mrz: array<string, mixed>|null, fiscal_code_valid: bool|null}
     */
    public function run(array $fields, string $ocrText): array
    {
        $by = [];
        $mrz = $this->mrz->fromText($ocrText);

        if ($mrz !== null) {
            $valid = $mrz['valid'];
            // Sex, nationality and names have no check digit of their own, so
            // only trust them when the dates around them read correctly.
            $readWell = $valid['date_of_birth'] && $valid['expiry_date'];

            $compare = [
                'document_number' => $valid['document_number'],
                'date_of_birth' => $valid['date_of_birth'],
                'expiry_date' => $valid['expiry_date'],
                'sex' => $readWell,
                'nationality' => $readWell,
                'surname' => $readWell,
                'given_names' => $readWell,
            ];

            foreach ($compare as $field => $trusted) {
                if ($trusted && $mrz[$field] !== null && $mrz[$field] !== '' && array_key_exists($field, $fields)) {
                    $by[$field]['mrz'] = $this->same($field, $fields[$field], $mrz[$field]);
                }
            }
        }

        $fiscalValid = null;
        if (! empty($fields['fiscal_code'])) {
            $fiscalValid = $this->fiscalCode->isValid($fields['fiscal_code']);
            $by['fiscal_code']['checksum'] = $fiscalValid;

            if ($fiscalValid) {
                $code = $this->fiscalCode->decode($fields['fiscal_code']);

                if (! empty($fields['surname'])) {
                    $by['surname']['fiscal_code'] = $code['surname'] === $this->fiscalCode->surnameCode($fields['surname']);
                }
                if (! empty($fields['given_names'])) {
                    $by['given_names']['fiscal_code'] = $code['name'] === $this->fiscalCode->nameCode($fields['given_names']);
                }
                if (! empty($fields['date_of_birth'])) {
                    [$y, $m, $d] = array_map('intval', explode('-', $fields['date_of_birth']));
                    $by['date_of_birth']['fiscal_code'] = sprintf('%02d', $y % 100) === $code['birth_year_2d']
                        && $m === $code['birth_month'] && $d === $code['birth_day'];
                }
                if (! empty($fields['sex'])) {
                    $by['sex']['fiscal_code'] = strtoupper(trim($fields['sex'])) === $code['sex'];
                }
            }
        }

        $results = [];
        foreach ($by as $field => $sources) {
            $results[$field] = [
                'ok' => ! in_array(false, $sources, true),
                'by' => $sources,
            ];
        }

        return ['fields' => $results, 'mrz' => $mrz, 'fiscal_code_valid' => $fiscalValid];
    }

    private function same(string $field, mixed $extracted, string $fromMrz): bool
    {
        $a = $this->letters((string) $extracted);
        $b = $this->letters($fromMrz);

        return match ($field) {
            // MRZ writes ITA; the card may say ITA or ITALIANA.
            'nationality' => $a !== '' && str_starts_with($a, $b),
            // Names longer than the MRZ field are cut off there.
            'surname', 'given_names' => $a === $b || (strlen($b) >= 10 && str_starts_with($a, $b)),
            default => $a === $b,
        };
    }

    private function letters(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $value) ?: $value;

        return preg_replace('/[^A-Z0-9]/', '', strtoupper($ascii));
    }
}
