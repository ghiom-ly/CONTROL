<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\SystemSetting;
use App\Support\ShipmentUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** أكواد منظومة التجهيز (SX…): الضغط ينسخ الكود ويفتح المنظومة الثانية */
class FulfillmentCodeLinkTest extends TestCase
{
    use RefreshDatabase;

    private function reportWithCode(string $code): DailyReport
    {
        $emp = $this->makeUser();
        $store = Store::factory()->create();
        $r = DailyReport::factory()->pendingReview()->for($emp)->create();
        $s = Shipment::create(['code' => $code, 'store_id' => $store->id, 'registered_at' => today(), 'processing_status' => 'open']);
        ShipmentFollowup::create(['shipment_id' => $s->id, 'daily_report_id' => $r->id, 'store_id' => $store->id, 'shipment_code' => $code,
            'user_id' => $emp->id, 'followup_at' => now(), 'result' => 'customer_no_answer']);

        return $r;
    }

    public function test_migration_adds_settings_with_defaults(): void
    {
        $this->assertSame('SX', SystemSetting::get('fulfillment_code_prefix'));
        $this->assertSame('https://fulfillment.nawris.com/', SystemSetting::get('fulfillment_url'));
    }

    public function test_prefixed_codes_go_to_fulfillment_not_backoffice(): void
    {
        $this->assertSame('https://fulfillment.nawris.com/', ShipmentUrl::fulfillment('SX2611110037'));
        $this->assertSame('https://fulfillment.nawris.com/', ShipmentUrl::fulfillment(' sx2611110037 '));
        $this->assertNull(ShipmentUrl::for('SX2611110037'));

        $this->assertNull(ShipmentUrl::fulfillment('4749558'));
        $this->assertSame('https://backoffice.nawris.algoriza.com/orders/4749558', ShipmentUrl::for('4749558'));
        $this->assertNull(ShipmentUrl::fulfillment(null));
    }

    public function test_empty_prefix_or_url_disables_fulfillment(): void
    {
        SystemSetting::where('key', 'fulfillment_code_prefix')->update(['value' => null]);
        cache()->flush();
        $this->assertNull(ShipmentUrl::fulfillment('SX2611110037'));

        SystemSetting::where('key', 'fulfillment_code_prefix')->update(['value' => 'SX']);
        SystemSetting::where('key', 'fulfillment_url')->update(['value' => null]);
        cache()->flush();
        $this->assertNull(ShipmentUrl::fulfillment('SX2611110037'));
    }

    public function test_report_page_renders_copy_and_open_button(): void
    {
        $r = $this->reportWithCode('SX2611110037');
        $this->actingAs($this->makeUser(Role::ADMIN))->get("/reports/{$r->id}")->assertOk()
            ->assertSee('data-copy-open="https://fulfillment.nawris.com/"', false)
            ->assertSee('SX2611110037')
            ->assertDontSee('backoffice.nawris.algoriza.com/orders/SX2611110037', false);
    }

    public function test_settings_validation(): void
    {
        $payload = SystemSetting::all()->mapWithKeys(fn ($s) => [$s->key => $s->value])->all();
        $admin = $this->makeUser(Role::SUPER_ADMIN);

        $bad = $payload;
        $bad['fulfillment_url'] = 'javascript:alert(1)';
        $bad['fulfillment_code_prefix'] = 'S X<';
        $this->actingAs($admin)->put('/settings', ['settings' => $bad])
            ->assertSessionHasErrors(['settings.fulfillment_url', 'settings.fulfillment_code_prefix']);

        $good = $payload;
        $good['fulfillment_url'] = 'https://fulfillment.nawris.com/orders/list';
        $good['fulfillment_code_prefix'] = 'SX';
        $this->actingAs($admin)->put('/settings', ['settings' => $good])->assertSessionHasNoErrors();
        $this->assertSame('https://fulfillment.nawris.com/orders/list', SystemSetting::get('fulfillment_url'));
    }
}
