<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * الفترة الزمنية الموحدة لجميع الفلاتر: اليوم، أمس، آخر 7 أيام، آخر 30 يوماً،
 * الشهر الحالي، الشهر السابق، فترة مخصصة.
 */
final class Period
{
    public const PRESETS = [
        'today' => 'اليوم',
        'yesterday' => 'أمس',
        'last7' => 'آخر 7 أيام',
        'last30' => 'آخر 30 يوماً',
        'this_month' => 'الشهر الحالي',
        'last_month' => 'الشهر السابق',
        'custom' => 'فترة مخصصة',
    ];

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $preset = 'custom',
    ) {}

    public static function fromRequest(Request $request, string $default = 'this_month'): self
    {
        $preset = $request->query('period', $default);
        if ($preset === 'custom') {
            try {
                $from = CarbonImmutable::parse((string) $request->query('from'))->startOfDay();
                $to = CarbonImmutable::parse((string) $request->query('to'))->endOfDay();
                if ($from->gt($to)) {
                    [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
                }

                return new self($from, $to, 'custom');
            } catch (\Throwable) {
                $preset = $default;
            }
        }

        return self::preset(array_key_exists($preset, self::PRESETS) ? $preset : $default);
    }

    public static function preset(string $preset): self
    {
        $now = CarbonImmutable::now();

        return match ($preset) {
            'today' => new self($now->startOfDay(), $now->endOfDay(), $preset),
            'yesterday' => new self($now->subDay()->startOfDay(), $now->subDay()->endOfDay(), $preset),
            'last7' => new self($now->subDays(6)->startOfDay(), $now->endOfDay(), $preset),
            'last30' => new self($now->subDays(29)->startOfDay(), $now->endOfDay(), $preset),
            'last_month' => new self($now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth(), $preset),
            default => new self($now->startOfMonth(), $now->endOfMonth(), 'this_month'),
        };
    }

    /** الفترة السابقة المماثلة في الطول — للمقارنات */
    public function previous(): self
    {
        $days = $this->from->diffInDays($this->to->startOfDay()) + 1;

        return new self($this->from->subDays($days), $this->from->subSecond(), 'custom');
    }

    public function label(): string
    {
        return $this->preset !== 'custom'
            ? self::PRESETS[$this->preset]
            : $this->from->format('Y/m/d').' - '.$this->to->format('Y/m/d');
    }

    public function query(): array
    {
        return $this->preset === 'custom'
            ? ['period' => 'custom', 'from' => $this->from->toDateString(), 'to' => $this->to->toDateString()]
            : ['period' => $this->preset];
    }
}
