<?php

namespace Tests\Unit;

use App\Documents\Scorer;
use Tests\TestCase;

class ScorerTest extends TestCase
{
    public function test_strings_ignore_case_spacing_and_punctuation(): void
    {
        $score = (new Scorer)->score(
            ['licence_number' => 'OKAFO 860722 D99', 'address' => '4 Harbour Road, Eastport 30915'],
            ['licence_number' => 'okafo860722d99', 'address' => '4 HARBOUR ROAD EASTPORT 30915'],
        );

        $this->assertSame(1.0, $score['score']);
    }

    public function test_names_match_in_any_order(): void
    {
        $score = (new Scorer)->score(['full_name' => 'Anika Rose Mendes'], ['full_name' => 'MENDES ANIKA ROSE']);

        $this->assertSame(1.0, $score['score']);
    }

    public function test_dates_are_compared_as_dates(): void
    {
        $scorer = new Scorer;

        $this->assertSame(1.0, $scorer->score(['dob' => '1991-03-14'], ['dob' => '1991-03-14'])['score']);
        $this->assertSame(0.0, $scorer->score(['dob' => '1991-03-14'], ['dob' => '1991-14-03'])['score']);
    }

    public function test_numbers_allow_formatting_and_rounding(): void
    {
        $score = (new Scorer)->score(['total' => 1282.05, 'tax' => 61.05], ['total' => '₹1,282.05', 'tax' => 61.049]);

        $this->assertSame(1.0, $score['score']);
    }

    public function test_null_expected_rewards_null_and_penalises_guesses(): void
    {
        $scorer = new Scorer;

        $this->assertSame(1.0, $scorer->score(['restrictions' => null], ['restrictions' => null])['score']);
        $this->assertSame(0.0, $scorer->score(['restrictions' => null], ['restrictions' => '01'])['score']);
    }

    public function test_lists_score_by_f1(): void
    {
        $scorer = new Scorer;
        $expected = ['vehicle_classes' => ['AM', 'A1', 'B']];

        $this->assertSame(1.0, $scorer->score($expected, ['vehicle_classes' => ['B', 'A1', 'AM']])['score']);
        // 2 of 3 found, 1 wrong extra: precision 2/3, recall 2/3.
        $this->assertEqualsWithDelta(2 / 3, $scorer->score($expected, ['vehicle_classes' => ['AM', 'B', 'C']])['score'], 0.001);
    }

    public function test_line_items_need_matching_amount_and_similar_description(): void
    {
        $expected = ['items' => [['description' => 'TOMATO 1KG', 'amount' => 48.0], ['description' => 'BREAD BROWN', 'amount' => 55.0]]];

        $score = (new Scorer)->score($expected, ['items' => [
            ['description' => 'Tomato 1kg', 'amount' => 48],
            ['description' => 'BREAD BROWN', 'amount' => 50],
        ]]);

        $this->assertEqualsWithDelta(0.5, $score['score'], 0.001);
    }

    public function test_ocr_coverage_finds_dates_in_printed_formats(): void
    {
        $coverage = (new Scorer)->ocrCoverage(
            ['date_of_birth' => '1991-03-14', 'id_number' => 'EST4829173K', 'sex' => 'F', 'address' => '12 Maple Court'],
            "Date of birth\n14.03.1991\nDocument No.\nEST4829173K\nSex\nF",
        );

        $this->assertSame(0.75, $coverage['coverage']);
        $this->assertSame(['address'], $coverage['missing']);
    }
}
