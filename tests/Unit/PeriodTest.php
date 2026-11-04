<?php

namespace Tests\Unit;

use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class PeriodTest extends TestCase
{
    protected function setUp(): void
    {
        CarbonImmutable::setTestNow('2026-11-04 10:00:00');
    }

    public function test_presets(): void
    {
        $this->assertSame('2026-11-03', Period::preset('yesterday')->from->toDateString());
        $this->assertSame('2026-10-29', Period::preset('last7')->from->toDateString());
        $this->assertSame('2026-10-01', Period::preset('last_month')->from->toDateString());
        $this->assertSame('2026-10-31', Period::preset('last_month')->to->toDateString());
        $this->assertSame('2026-11-01', Period::preset('this_month')->from->toDateString());
    }

    public function test_custom_period_and_swap(): void
    {
        $p = Period::fromRequest(Request::create('/', 'GET', ['period' => 'custom', 'from' => '2026-10-20', 'to' => '2026-10-10']));
        $this->assertSame('2026-10-10', $p->from->toDateString());
        $this->assertSame('2026-10-20', $p->to->toDateString());
    }

    public function test_invalid_input_falls_back(): void
    {
        $p = Period::fromRequest(Request::create('/', 'GET', ['period' => 'custom', 'from' => 'xx']), 'today');
        $this->assertSame('today', $p->preset);
        $this->assertSame('this_month', Period::fromRequest(Request::create('/', 'GET', ['period' => 'hack']))->preset);
    }

    public function test_previous_period_has_same_length(): void
    {
        $prev = Period::preset('last7')->previous();
        $this->assertSame('2026-10-22', $prev->from->toDateString());
        $this->assertSame('2026-10-28', $prev->to->toDateString());
    }
}
