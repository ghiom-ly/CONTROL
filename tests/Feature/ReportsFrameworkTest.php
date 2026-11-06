<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemSetting;
use App\Reports\ReportData;
use App\Reports\Renderers\ExcelRenderer;
use App\Reports\Renderers\PdfRenderer;
use App\Support\Branding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReportsFrameworkTest extends TestCase
{
    private function data(int $rows = 3, string $sectionTitle = 'قسم تجريبي'): ReportData
    {
        return new ReportData(
            title: 'تقرير تجريبي', subtitle: 'الفترة: 2026/11/01 — 2026/11/06',
            summary: ['الطلبيات' => 15, 'نسبة التسليم' => '60%'],
            sections: [[
                'title' => $sectionTitle, 'headers' => ['المتجر', 'الطلبيات'],
                'rows' => array_map(fn ($i) => ["متجر الأمل {$i}", $i], range(1, $rows)), 'landscape' => false,
            ]],
        );
    }

    public function test_logo_upload_and_delete(): void
    {
        Storage::fake('private');
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        $this->actingAs($boss)->post('/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 80)])->assertRedirect();
        $path = SystemSetting::get('company_logo');
        $this->assertNotEmpty($path);
        Storage::disk('private')->assertExists($path);

        $this->actingAs($boss)->delete('/settings/logo')->assertRedirect();
        $this->assertEmpty(SystemSetting::get('company_logo'));
        Storage::disk('private')->assertMissing($path);

        $this->actingAs($this->makeUser(Role::ADMIN))->post('/settings/logo', ['logo' => UploadedFile::fake()->image('l.png')])->assertForbidden();
    }

    public function test_missing_logo_file_ignored(): void
    {
        SystemSetting::where('key', 'company_logo')->update(['value' => 'branding/gone.png']);
        cache()->flush();
        $this->assertNull(Branding::header()['logo_path']);
        $this->assertSame('شركة النورس', Branding::header()['name']);
    }

    public function test_pdf_renderer_outputs_pdf(): void
    {
        $this->assertStringStartsWith('%PDF', app(PdfRenderer::class)->render($this->data()));
    }

    public function test_pdf_caps_rows(): void
    {
        $html = view('reports-center.document', ['data' => $this->data(2005), 'brand' => Branding::header(), 'mode' => 'print'])->render();
        $this->assertStringContainsString('تم عرض أول 500', $html);
        $this->assertSame(500, substr_count($html, '<tr class="r">'));
    }

    public function test_excel_sheet_title_sanitized(): void
    {
        $res = app(ExcelRenderer::class)->download($this->data(2, 'المتجر/الأول: [تجربة]*؟ اسم طويل جداً جداً جداً'), 'x.xlsx');
        ob_start();
        $res->sendContent();
        $bytes = ob_get_clean();
        $tmp = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($tmp, $bytes);
        $title = IOFactory::load($tmp)->getSheet(0)->getTitle();
        $this->assertLessThanOrEqual(31, mb_strlen($title));
        $this->assertDoesNotMatchRegularExpression('/[\\\\\/\?\*\[\]:]/u', $title);
    }

    public function test_viewer_gets_exports_permission(): void
    {
        $this->assertTrue($this->makeUser(Role::VIEWER)->hasPermission('exports.generate'));
        $this->assertFalse($this->makeUser()->hasPermission('exports.generate'));
    }
}
