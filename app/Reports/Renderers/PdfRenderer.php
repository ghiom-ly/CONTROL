<?php

namespace App\Reports\Renderers;

use App\Reports\ReportData;
use App\Support\Branding;
use Illuminate\Support\Facades\Cache;
use Mpdf\Mpdf;

/** PDF عربي بتشكيل الحروف (OTL) واتجاه من اليمين لليسار */
class PdfRenderer
{
    /** ملف PDF واحد في نفس الوقت على الخادم الصغير؛ الطلب الثاني ينتظر حتى 60 ثانية */
    public function render(ReportData $data): string
    {
        @set_time_limit(180);

        return Cache::lock('pdf-render', 180)->block(60, fn () => $this->doRender($data));
    }

    private function doRender(ReportData $data): string
    {
        $tmp = storage_path('app/mpdf');
        @mkdir($tmp, 0775, true);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $data->landscape() ? 'A4-L' : 'A4',
            'default_font' => 'dejavusans',
            'directionality' => 'rtl',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $tmp,
            'margin_top' => 10, 'margin_bottom' => 14, 'margin_left' => 10, 'margin_right' => 10,
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->SetFooter('{PAGENO} / {nbpg}');
        $mpdf->WriteHTML(view('reports-center.document', ['data' => $data, 'brand' => Branding::header(), 'mode' => 'pdf'])->render());

        return $mpdf->Output('', 'S');
    }
}
