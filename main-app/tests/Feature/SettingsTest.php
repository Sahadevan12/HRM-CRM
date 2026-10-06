<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function company(string $email = 'a@test.com'): User
    {
        $admin = User::where('type', 'superadmin')->first();
        $company = User::create([
            'name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'company', 'active_plan' => \App\Models\Plan::where('free_plan', true)->value('id'),
            'total_user' => 5, 'email_verified_at' => now(), 'creator_id' => $admin->id, 'created_by' => $admin->id,
        ]);
        $company->assignRole('company');

        return $company;
    }

    private function staff(User $company): User
    {
        $staff = User::create([
            'name' => 's', 'email' => 's@' . $company->email, 'password' => 'secret-pass-1', 'type' => 'staff',
            'email_verified_at' => now(), 'creator_id' => $company->id, 'created_by' => $company->id,
        ]);
        $staff->assignRole('staff');

        return $staff;
    }

    public function test_settings_are_stored_per_tenant_and_helpers_read_them(): void
    {
        $a = $this->company('a@test.com');
        $b = $this->company('b@test.com');

        $this->actingAs($a)->post('/settings/brand', [
            'titleText' => 'Company A', 'themeColor' => 'blue', 'themeMode' => 'dark',
        ])->assertSessionHas('success');

        $this->actingAs($a);
        $this->assertSame('Company A', company_setting('titleText'));

        $this->actingAs($b);
        $this->assertNull(company_setting('titleText'));
    }

    public function test_staff_reads_the_settings_of_their_company(): void
    {
        $a = $this->company();
        setSetting('titleText', 'Acme ERP', $a->id);
        $staff = $this->staff($a);

        $this->actingAs($staff);
        $this->assertSame('Acme ERP', company_setting('titleText'));
    }

    public function test_saving_a_setting_refreshes_the_cache(): void
    {
        $a = $this->company();
        $this->actingAs($a);

        setSetting('titleText', 'First');
        $this->assertSame('First', company_setting('titleText'));

        setSetting('titleText', 'Second');
        $this->assertSame('Second', company_setting('titleText'));
    }

    public function test_staff_without_permission_cannot_change_settings(): void
    {
        $staff = $this->staff($this->company());

        $this->actingAs($staff)->get('/settings')->assertRedirect(route('dashboard'));
        $this->actingAs($staff)->post('/settings/brand', [
            'titleText' => 'Hacked', 'themeColor' => 'blue', 'themeMode' => 'dark',
        ])->assertSessionHas('error');

        $this->assertDatabaseMissing('settings', ['key' => 'titleText']);
    }

    public function test_invalid_values_are_rejected(): void
    {
        $a = $this->company();

        $this->actingAs($a)->post('/settings/brand', ['titleText' => '', 'themeColor' => 'pink', 'themeMode' => 'neon'])
            ->assertSessionHasErrors(['titleText', 'themeColor', 'themeMode']);

        $this->actingAs($a)->post('/settings/currency', [
            'currencyCode' => 'DOLLAR', 'currencySymbol' => '$', 'currencyPosition' => 'before', 'currencyDecimals' => 9,
        ])->assertSessionHasErrors(['currencyCode', 'currencyDecimals']);

        $this->actingAs($a)->post('/settings/system', [
            'defaultLanguage' => 'xx', 'dateFormat' => 'Y-m-d', 'timezone' => 'Mars/Base',
        ])->assertSessionHasErrors(['defaultLanguage', 'timezone']);
    }

    public function test_currency_helper_uses_tenant_settings(): void
    {
        $a = $this->company();
        $this->actingAs($a)->post('/settings/currency', [
            'currencyCode' => 'inr', 'currencySymbol' => '₹', 'currencyPosition' => 'before', 'currencyDecimals' => 2,
        ])->assertSessionHas('success');

        $this->actingAs($a);
        $this->assertSame('₹1,234.50', formatCurrency(1234.5));
        $this->assertSame('INR', company_setting('currencyCode'));
    }

    public function test_user_can_switch_language_and_translations_are_shared(): void
    {
        $a = $this->company();

        $this->actingAs($a)->post('/languages/change', ['lang' => 'ta'])->assertRedirect();
        $this->assertSame('ta', $a->fresh()->lang);

        $this->actingAs($a->fresh())->get('/settings')
            ->assertInertia(fn ($page) => $page
                ->where('auth.lang', 'ta')
                ->where('translations.Dashboard', 'முகப்பு')
                ->has('languages.en')
                ->has('auth.user.activatedPackages'));

        $this->actingAs($a)->post('/languages/change', ['lang' => 'zz'])->assertSessionHasErrors('lang');
    }

    public function test_guests_only_receive_public_admin_settings(): void
    {
        $admin = User::where('type', 'superadmin')->first();
        setSetting('titleText', 'Public Brand', $admin->id, isPublic: true);
        setSetting('smtpPassword', 'secret', $admin->id, isPublic: false);

        $this->get('/login')->assertInertia(fn ($page) => $page
            ->where('adminAllSetting.titleText', 'Public Brand')
            ->missing('adminAllSetting.smtpPassword'));
    }
}
