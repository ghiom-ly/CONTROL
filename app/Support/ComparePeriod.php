<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * فترة حالية + الفترة السابقة لها مباشرة بنفس الطول (للمقارنة).
 * day = اليوم مقابل أمس، week = آخر 7 أيام، month = آخر 30 يوماً، custom = من–إلى.
 */
class ComparePeriod
{
    public const PRESETS = ['day' => 'يوم', 'week' => 'أسبوع', 'month' => 'شهر', 'custom' => 'فترة مخصصة'];

    public const MAX_DAYS = 366;

    /** فترة بطول أقصى MAX_DAYS تنتهي في to (للوحة التحكم التي تقبل فترات أطول) */
    public static function clamped(CarbonImmutable $from, CarbonImmutable $to, string $preset = 'custom'): self
    {
        $min = $to->startOfDay()->subDays(self::MAX_DAYS - 1);

        return self::between($from->lt($min) ? $min : $from, $to, $preset);
    }

    public readonly CarbonImmutable $prevFrom;

    public readonly CarbonImmutable $prevTo;

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $preset = 'custom',
    ) {
        $days = $this->days();
        $this->prevTo = $from->subDay()->endOfDay();
        $this->prevFrom = $from->subDays($days)->startOfDay();
    }

    public static function between(CarbonImmutable $from, CarbonImmutable $to, string $preset = 'custom'): self
    {
        return new self($from->startOfDay(), $to->endOfDay(), $preset);
    }

    public static function fromRequest(Request $request): self
    {
        $preset = (string) $request->query('period', 'week');
        $today = CarbonImmutable::today();

        return match ($preset) {
            'day' => self::between($today, $today, 'day'),
            'month' => self::between($today->subDays(29), $today, 'month'),
            'custom' => self::custom($request),
            default => self::between($today->subDays(6), $today, 'week'),
        };
    }

    private static function custom(Request $request): self
    {
        $data = validator($request->query(), [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], ['to.after_or_equal' => 'تاريخ النهاية يجب أن يكون بعد تاريخ البداية.'])->validate();

        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);
        // حماية الخادم: الفترة المخصصة سنة كحد أقصى
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'أقصى طول للفترة '.self::MAX_DAYS.' يوماً.']);
        }

        return self::between($from, $to, 'custom');
    }

    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    public function query(): array
    {
        return $this->preset === 'custom'
            ? ['period' => 'custom', 'from' => $this->from->toDateString(), 'to' => $this->to->toDateString()]
            : ['period' => $this->preset];
    }

    public function label(): string
    {
        return $this->from->format('Y/m/d').' — '.$this->to->format('Y/m/d')
            .' مقابل '.$this->prevFrom->format('Y/m/d').' — '.$this->prevTo->format('Y/m/d');
    }
}
