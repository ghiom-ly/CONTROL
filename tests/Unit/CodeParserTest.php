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
        $this->assertSame(['A', 'B', 'C'], CodeParser::split("A;B\tC"));
        $this->assertSame([], CodeParser::split("  \n ،, "));
    }

    public function test_too_long_code_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CodeParser::split(str_repeat('X', 81));
    }
}
