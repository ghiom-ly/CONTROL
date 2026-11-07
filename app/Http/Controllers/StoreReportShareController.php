<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\StoreMessage;
use App\Models\SystemSetting;
use App\Reports\Definitions\StoreReport;
use App\Reports\Renderers\PdfRenderer;
use App\Reports\ReportParams;
use App\Support\ComparePeriod;
use App\Support\WhatsAppNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** تقرير المتجر لصاحبه: رابط موقّع 7 أيام يُرسل عبر واتساب ويفتح PDF بلا دخول */
class StoreReportShareController extends Controller
{
    public const LINK_DAYS = 7;

    /** من مركز التقارير: المتجر بكوده */
    public function shareByCode(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('messages.send'), 403);
        $code = mb_strtoupper(trim((string) $request->input('store_code')));
        $store = $code !== '' ? Store::where('code', $code)->first() : null;
        if (! $store) {
            throw ValidationException::withMessages(['store_code' => 'كود المتجر غير موجود.']);
        }

        return $this->share($request, $store);
    }

    public function share(Request $request, Store $store): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('messages.send'), 403);
        $phone = WhatsAppNumber::normalize($store->phone);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'رقم المتجر غير صالح لواتساب — عدّله في صفحة المتجر.']);
        }
        $p = ComparePeriod::fromRequest($request);
        [$from, $to] = [$p->from->toDateString(), $p->to->toDateString()];

        $url = URL::temporarySignedRoute('store-report.public', now()->addDays(self::LINK_DAYS), ['store' => $store->id, 'from' => $from, 'to' => $to]);
        $company = (string) SystemSetting::get('company_name', 'شركة النورس');
        $body = 'السلام عليكم'.($store->owner_name ? ' '.$store->owner_name : '')."،\n"
            ."تقرير أداء متجركم {$store->name} للفترة {$from} — {$to}:\n{$url}\n"
            .'(الرابط صالح '.self::LINK_DAYS." أيام)\nمع التحية — {$company}";

        StoreMessage::create([
            'store_id' => $store->id, 'type' => 'report', 'body' => $body, 'phone' => $phone, 'sent_by' => $request->user()->id,
            'meta' => ['from' => $from, 'to' => $to, 'url' => $url],
        ]);

        return redirect()->away('https://wa.me/'.$phone.'?text='.rawurlencode($body));
    }

    public function public(Request $request, int $store, PdfRenderer $pdf): Response
    {
        // التوقيع أولاً، ثم المتجر — متجر محذوف يعرض صفحة انتهاء الرابط لا 404
        if (! $request->hasValidSignature() || ! ($store = Store::find($store))) {
            return response()->view('reports-center.link-expired', [], 403);
        }
        $period = ComparePeriod::between(CarbonImmutable::parse((string) $request->query('from')), CarbonImmutable::parse((string) $request->query('to')));
        $data = app(StoreReport::class)->build(new ReportParams($period, new \App\Models\User, store: $store));

        return response($pdf->render($data), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="store-report-'.$store->code.'.pdf"',
        ]);
    }
}
