<?php

namespace Tests\Unit;

use App\Documents\Checks\FiscalCode;
use App\Documents\Checks\IdCardChecks;
use App\Documents\Checks\Mrz;
use App\Services\JevJudge;
use Tests\TestCase;

class IdCardChecksTest extends TestCase
{
    // ICAO 9303 part 5 TD1 specimen (fictional holder).
    private const MRZ = "I<UTOD231458907<<<<<<<<<<<<<<<\n7408122F1204159UTO<<<<<<<<<<<6\nERIKSSON<<ANNA<MARIA<<<<<<<<<<";

    public function test_mrz_parses_the_icao_specimen_and_its_check_digits_pass(): void
    {
        $mrz = (new Mrz)->fromText("Some header text\n".self::MRZ."\nfooter");

        $this->assertSame('D23145890', $mrz['document_number']);
        $this->assertSame('1974-08-12', $mrz['date_of_birth']);
        $this->assertSame('2012-04-15', $mrz['expiry_date']);
        $this->assertSame('F', $mrz['sex']);
        $this->assertSame('ERIKSSON', $mrz['surname']);
        $this->assertSame('ANNA MARIA', $mrz['given_names']);
        $this->assertSame(['document_number' => true, 'date_of_birth' => true, 'expiry_date' => true, 'composite' => true], $mrz['valid']);
    }

    public function test_mrz_check_digit_catches_a_misread_digit(): void
    {
        // 8 -> 3 in a weight-7 position changes the sum by 35, which the check digit catches.
        $mrz = (new Mrz)->fromText(str_replace('D23145890', 'D23145390', self::MRZ));

        $this->assertFalse($mrz['valid']['document_number']);
    }

    public function test_mrz_tolerates_ocr_spacing_and_guillemets(): void
    {
        $text = "I<UTO D23145890 7«««««««\n7408122F1204159UTO<<<<<<<<<<<6\nERIKSSON<<ANNA<MARIA<<<<<<<<<<";

        $this->assertTrue((new Mrz)->fromText($text)['valid']['date_of_birth']);
    }

    public function test_no_mrz_in_plain_text(): void
    {
        $this->assertNull((new Mrz)->fromText("REPUBBLICA ITALIANA\nCARTA DI IDENTITA"));
    }

    public function test_fiscal_code_checksum_and_decoding(): void
    {
        $cf = new FiscalCode;

        // The textbook example: Mario Rossi, born 10 December 1985.
        $this->assertTrue($cf->isValid('RSSMRA85T10A562S'));
        $this->assertTrue($cf->isValid('rssmra 85t10 a562s'));
        $this->assertFalse($cf->isValid('RSSMRA85T10A562T'), 'wrong check letter');
        $this->assertFalse($cf->isValid('RSSMRA85T10A562'), 'too short');

        $decoded = $cf->decode('RSSMRA85T10A562S');
        $this->assertSame(['RSS', 'MRA', '85', 12, 10, 'M'], [$decoded['surname'], $decoded['name'], $decoded['birth_year_2d'], $decoded['birth_month'], $decoded['birth_day'], $decoded['sex']]);
        $this->assertSame('RSS', $cf->surnameCode('Rossi'));
        $this->assertSame('MRA', $cf->nameCode('Mario'));
        $this->assertSame('DNG', $cf->surnameCode("D'Angelo"));
        $this->assertSame('GFR', $cf->nameCode('Gianfranco')); // 4+ consonants (G N F R N C): 1st, 3rd, 4th
    }

    public function test_checks_mark_fields_that_match_and_flag_ones_that_do_not(): void
    {
        $checks = new IdCardChecks(new Mrz, new FiscalCode);

        $result = $checks->run([
            'document_number' => 'D23145890',
            'date_of_birth' => '1974-08-12',
            'expiry_date' => '2012-04-16',      // off by a day
            'sex' => 'F',
            'surname' => 'Eriksson',
            'given_names' => 'Anna Maria',
            'nationality' => 'UTO',
            'fiscal_code' => null,
        ], self::MRZ)['fields'];

        $this->assertTrue($result['document_number']['ok']);
        $this->assertTrue($result['date_of_birth']['ok']);
        $this->assertFalse($result['expiry_date']['ok']);
        $this->assertTrue($result['surname']['ok']);
        $this->assertTrue($result['given_names']['ok']);
        $this->assertArrayNotHasKey('fiscal_code', $result);
    }

    public function test_fiscal_code_cross_checks_name_birth_date_and_sex(): void
    {
        $result = (new IdCardChecks(new Mrz, new FiscalCode))->run([
            'surname' => 'Rossi', 'given_names' => 'Mario', 'date_of_birth' => '1985-12-10', 'sex' => 'F',
            'fiscal_code' => 'RSSMRA85T10A562S',
        ], 'no mrz here')['fields'];

        $this->assertTrue($result['fiscal_code']['ok']);
        $this->assertTrue($result['surname']['ok']);
        $this->assertTrue($result['date_of_birth']['ok']);
        $this->assertFalse($result['sex']['ok'], 'code says male');
    }

    public function test_judge_reads_p_true_from_option_letter_logprobs(): void
    {
        $judge = new JevJudge;
        $response = fn (array $top) => ['choices' => [['message' => ['content' => ' B'], 'logprobs' => ['content' => [['top_logprobs' => $top]]]]]];

        $p = $judge->probabilityTrue($response([
            ['token' => ' B', 'logprob' => log(0.9)],
            ['token' => ' **', 'logprob' => log(0.05)],
            ['token' => ' A', 'logprob' => log(0.1)],
        ]));
        $this->assertEqualsWithDelta(0.9, $p, 0.001);

        // No logprobs: fall back to the chosen letter.
        $this->assertSame(0.0, $judge->probabilityTrue(['choices' => [['message' => ['content' => 'A']]]]));
        $this->assertNull($judge->probabilityTrue(['choices' => [['message' => ['content' => 'maybe']]]]));
    }

    public function test_judge_questions_show_dates_as_printed_and_skip_empty_values(): void
    {
        $questions = (new JevJudge)->questionsFor(
            ['date_of_birth' => '2005-12-16', 'address' => null, 'surname' => 'ROSSI'],
            config('documents.types.it_id_card.fields'),
            'Italian identity card (CIE)',
        );

        $this->assertSame(['date_of_birth', 'surname'], array_keys($questions));
        $this->assertStringContainsString('"16.12.2005"', $questions['date_of_birth']);
    }
}
