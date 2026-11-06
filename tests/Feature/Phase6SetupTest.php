<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\SystemSetting;
use App\Support\ShipmentUrl;
use Tests\TestCase;

class Phase6SetupTest extends TestCase
{
    private const URL = 'https://backoffice.nawris.algoriza.com/orders/{code}';

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

    public function test_migration_adds_setting_and_permissions(): void
    {
        $this->assertSame(self::URL, SystemSetting::get('shipment_url_template'));
        $emp = $this->makeUser();
        $this->assertTrue($emp->hasPermission('shipments.lookup'));
        $this->assertTrue($emp->hasPermission('contacts.create'));
        $this->assertFalse($emp->hasPermission('shipments.view'));
        $this->assertFalse($emp->hasPermission('contacts.view'));
    }

    public function test_url_for_code(): void
    {
        $this->assertSame('https://backoffice.nawris.algoriza.com/orders/4749558', ShipmentUrl::for('4749558'));
        $this->assertStringContainsString(rawurlencode('A B/1'), ShipmentUrl::for('A B/1'));
        $this->assertNull(ShipmentUrl::for(null));
    }

    public function test_report_page_links_codes(): void
    {
        $r = $this->reportWithCode('4749558');
        $this->actingAs($this->makeUser(Role::ADMIN))->get("/reports/{$r->id}")->assertOk()
            ->assertSee('https://backoffice.nawris.algoriza.com/orders/4749558', false)
            ->assertSee('target="_blank"', false);
    }

    public function test_empty_template_renders_plain_code(): void
    {
        SystemSetting::where('key', 'shipment_url_template')->update(['value' => null]);
        cache()->flush();
        $this->assertNull(ShipmentUrl::for('4749558'));
        $r = $this->reportWithCode('4749558');
        $this->actingAs($this->makeUser(Role::ADMIN))->get("/reports/{$r->id}")->assertOk()
            ->assertSee('4749558')->assertDontSee('backoffice.nawris', false);
    }

    public function test_template_without_placeholder_rejected(): void
    {
        $payload = SystemSetting::all()->mapWithKeys(fn ($s) => [$s->key => $s->value])->all();
        $payload['shipment_url_template'] = 'https://x.com/orders';
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))->put('/settings', ['settings' => $payload])
            ->assertSessionHasErrors('settings.shipment_url_template');

        $payload['shipment_url_template'] = 'https://x.com/orders/{code}';
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))->put('/settings', ['settings' => $payload])->assertSessionHasNoErrors();
        $this->assertSame('https://x.com/orders/{code}', SystemSetting::get('shipment_url_template'));
    }
}
