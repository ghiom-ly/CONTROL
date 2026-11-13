<?php

namespace Tests\Unit;

use App\Support\CodeParser;
use PHPUnit\Framework\TestCase;

class CodeParserTest extends TestCase
{
    public function test_paste_parser_normalizes_and_dedupes(): void
    {
        $this->assertSame(['NR-55821', '55907', 'AB1'], CodeParser::split("nr-55821، NR-55821\n\n ٥٥٩٠٧ ,ab1"));
    }

    public function test_semicolons_tabs_and_empty_input(): void
    {
        $this->assertSame(['A1', 'B2', 'C3'], CodeParser::split("A1;B2\tC3"));
        $this->assertSame([], CodeParser::split("  \n ،, "));
    }

    public function test_strips_invisible_marks_and_persian_digits(): void
    {
        $this->assertSame(['AB12', '345'], CodeParser::split("\u{200F}ab12\u{200E} \u{FEFF}۳۴٥\u{2066}"));
    }

    public function test_words_without_digits_are_not_codes(): void
    {
        // الموظف يكتب «لا يوجد» أو «لايوجد» بدل ما يخلي الخانة فاضية — مش أكواد
        $this->assertSame([], CodeParser::split('لايوجد'));
        $this->assertSame([], CodeParser::split('لا يوجد'));
        $this->assertSame([], CodeParser::split('ما فيش - لا شيء'));
        $this->assertSame(['4749558', 'SX2611110037'], CodeParser::split('4749558 لايوجد sx2611110037'));
    }

    public function test_too_long_code_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CodeParser::split(str_repeat('X1', 41));
    }
}
