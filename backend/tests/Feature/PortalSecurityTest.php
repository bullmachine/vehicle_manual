<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\Manual;
use App\Models\QrCode;
use App\Models\QrType;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\VehicleEngineMapping;
use App\Models\VehicleVariant;
use App\Models\VehicleVariantQrType;
use App\Services\PortalService;
use App\Services\SmsProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
    }

    public function test_active_qr_context_is_scanned_without_exposing_the_token(): void
    {
        $qr = QrCode::query()->firstOrFail();
        $token = app(PortalService::class)->qrToken($qr);

        $response = $this->getJson('/api/public/qr/'.$token);

        $response->assertOk()
            ->assertJsonPath('qr.reference', $qr->reference)
            ->assertJsonPath('qr.type.code', 'domestic')
            ->assertJsonPath('selection.vehicle_required', true)
            ->assertJsonPath('selection.engine_required', true)
            ->assertJsonPath('selection.language_required', true)
            ->assertJsonCount(6, 'options.vehicle_variants')
            ->assertJsonMissingPath('qr.token')
            ->assertJsonMissingPath('qr.token_hash');

        $this->assertDatabaseHas('qr_scan_logs', ['qr_code_id' => $qr->id]);
        $this->assertSame(1, $qr->fresh()->scan_count);
    }

    public function test_vehicle_options_are_filtered_by_qr_type(): void
    {
        $export = QrType::query()->where('code', 'export')->firstOrFail();
        $backhoe = VehicleVariant::query()->where('code', 'BACKHOE')->firstOrFail();
        $mini = VehicleVariant::query()->where('code', 'MINI')->firstOrFail();

        VehicleVariantQrType::query()->where('qr_type_id', $export->id)->where('vehicle_variant_id', $backhoe->id)->delete();

        $options = app(PortalService::class)->vehicleOptionsForType($export->id);
        $this->assertNotContains($backhoe->id, array_column($options, 'id'));
        $this->assertContains($mini->id, array_column($options, 'id'));

        VehicleVariantQrType::query()->create(['vehicle_variant_id' => $backhoe->id, 'qr_type_id' => $export->id]);

        $options = app(PortalService::class)->vehicleOptionsForType($export->id);
        $this->assertContains($backhoe->id, array_column($options, 'id'));
    }

    public function test_unmapped_qr_type_falls_back_to_all_active_variants(): void
    {
        $type = QrType::query()->where('code', 'export-non-bull')->firstOrFail();
        VehicleVariantQrType::query()->where('qr_type_id', $type->id)->delete();

        $options = app(PortalService::class)->vehicleOptionsForType($type->id);

        $this->assertCount(VehicleVariant::query()->active()->count(), $options);
    }

    public function test_selection_rejects_a_vehicle_outside_the_qr_type(): void
    {
        $type = QrType::query()->where('code', 'export')->firstOrFail();
        $backhoe = VehicleVariant::query()->where('code', 'BACKHOE')->firstOrFail();
        VehicleVariantQrType::query()->where('qr_type_id', $type->id)->where('vehicle_variant_id', $backhoe->id)->delete();

        $qr = QrCode::query()->firstOrFail();
        $qr->update(['qr_type_id' => $type->id]);

        $this->postJson('/api/public/customer/request-otp', [
            'qr_token' => app(PortalService::class)->qrToken($qr->fresh()),
            'name' => 'Test Customer',
            'mobile' => '9876543210',
            'vehicle_variant_id' => $backhoe->id,
            'engine_id' => VehicleEngineMapping::query()->where('vehicle_variant_id', $backhoe->id)->value('engine_id'),
            'language_id' => Language::query()->where('code', 'en')->value('id'),
        ])->assertStatus(422)->assertJsonValidationErrors('vehicle_variant_id');
    }

    public function test_invalid_qr_is_rejected_without_creating_a_scan_log(): void
    {
        $response = $this->getJson('/api/public/qr/'.str_repeat('a', 64));

        $response->assertStatus(422)->assertJsonValidationErrors('qr');
        $this->assertDatabaseCount('qr_scan_logs', 0);
    }

    public function test_otp_verification_creates_a_session_and_limits_manual_access(): void
    {
        Setting::query()->where('key', 'otp_length')->update(['value' => '4']);
        $capturedSms = new class implements SmsProvider
        {
            public string $message = '';

            public function send(string $mobile, string $message): void
            {
                $this->message = $message;
            }
        };
        $this->app->instance(SmsProvider::class, $capturedSms);

        $qr = QrCode::query()->firstOrFail();
        $token = app(PortalService::class)->qrToken($qr);
        $vehicle = VehicleVariant::query()->where('code', 'TRACTOR-50')->firstOrFail();
        $payload = [
            'qr_token' => $token,
            'name' => 'Test Customer',
            'mobile' => '9876543210',
            'vehicle_variant_id' => $vehicle->id,
            'engine_id' => VehicleEngineMapping::query()->where('vehicle_variant_id', $vehicle->id)->whereHas('engine', fn ($query) => $query->where('code', 'DIESEL-A'))->value('engine_id'),
            'language_id' => Language::query()->where('code', 'en')->value('id'),
        ];

        $this->postJson('/api/public/customer/request-otp', $payload)->assertOk();
        preg_match('/(\d{4})/', $capturedSms->message, $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $verification = $this->postJson('/api/public/customer/verify-otp', [...$payload, 'otp' => $matches[1]]);
        $verification->assertOk()->assertJsonPath('data.customer.mobile', '+919876543210')->assertCookie('customer_session');

        $sessionToken = $verification->getCookie('customer_session', false)->getValue();
        $session = $this->withCredentials()->withUnencryptedCookie('customer_session', $sessionToken)
            ->getJson('/api/customer/session');
        $session->assertOk()->assertJsonPath('data.id', $verification->json('data.id'));

        $manuals = $this->withCredentials()->withUnencryptedCookie('customer_session', $sessionToken)
            ->getJson('/api/customer/manuals');
        $manuals->assertOk()->assertJsonCount(1);
        $authorizedManual = Manual::query()->findOrFail($manuals->json('data.0.id'));
        $currentVersion = $authorizedManual->versions()->where('version', '3.0')->firstOrFail();
        $requestedVersionId = $manuals->json('data.0.versions.0.id');
        $latestVersion = $currentVersion->replicate();
        $latestVersion->version = '4.0';
        $latestVersion->published_at = now();
        $latestVersion->save();

        $this->withCredentials()->withUnencryptedCookie('customer_session', $sessionToken)
            ->getJson('/api/customer/manuals/'.$authorizedManual->id.'?version_id='.$requestedVersionId)
            ->assertOk()
            ->assertJsonPath('data.version.id', $requestedVersionId);

        $unauthorizedManual = Manual::query()->whereKeyNot($manuals->json('data.0.id'))->firstOrFail();
        $this->withCredentials()->withUnencryptedCookie('customer_session', $sessionToken)
            ->getJson('/api/customer/manuals/'.$unauthorizedManual->id)
            ->assertNotFound();

        $page = $this->withCredentials()->withUnencryptedCookie('customer_session', $sessionToken)
            ->get('/api/customer/manuals/'.$manuals->json('data.0.id').'/pages/1');
        $page->assertOk()->assertHeader('content-type', 'image/svg+xml; charset=UTF-8');
    }

    public function test_customer_routes_reject_missing_or_revoked_sessions(): void
    {
        $this->getJson('/api/customer/manuals')->assertUnauthorized();

        $qr = QrCode::query()->firstOrFail();
        $customer = \App\Models\Customer::query()->create(['name' => 'Revoked Customer', 'mobile' => '+919876543211']);
        $session = \App\Models\CustomerSession::query()->create([
            'session_token_hash' => hash('sha256', 'revoked-session'),
            'customer_id' => $customer->id,
            'qr_code_id' => $qr->id,
            'vehicle_variant_id' => VehicleVariant::query()->value('id'),
            'engine_id' => VehicleEngineMapping::query()->value('engine_id'),
            'language_id' => Language::query()->firstOrFail()->id,
            'expires_at' => now()->addHour(),
            'revoked_at' => now(),
        ]);

        $this->withUnencryptedCookie('customer_session', 'revoked-session')
            ->getJson('/api/customer/session')
            ->assertUnauthorized();
        $this->assertDatabaseHas('customer_sessions', ['id' => $session->id, 'revoked_at' => $session->revoked_at]);
    }

    public function test_admin_permissions_are_enforced(): void
    {
        $viewerRole = Role::query()->create(['name' => 'Viewer', 'slug' => 'test-viewer']);
        $viewer = User::query()->create([
            'name' => 'Viewer',
            'email' => 'viewer@example.test',
            'password' => 'StrongPass123!',
            'role_id' => $viewerRole->id,
            'is_active' => true,
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/admin/manuals')
            ->assertForbidden();
        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/admin/settings')
            ->assertForbidden();

        $adminRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'StrongPass123!',
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $login = $this->postJson('/api/admin/login', ['login' => $admin->email, 'password' => 'StrongPass123!']);
        $login->assertOk()->assertJsonStructure(['token', 'user']);
        $headers = ['Authorization' => 'Bearer '.$login->json('token')];
        $this->assertTrue($admin->fresh()->hasPermission('dashboard.view'));
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/dashboard')->assertOk();
        $this->app['auth']->forgetGuards();

        $dashboard = $this->withHeaders($headers)->getJson('/api/admin/dashboard');
        $dashboard->assertOk();
        $this->withHeaders($headers)->getJson('/api/admin/engines?per_page=100')->assertOk();
        $qrList = $this->withHeaders($headers)->getJson('/api/admin/qr-codes?per_page=100');
        $qrList->assertOk();
        $this->assertIsString($qrList->json('data.0.qr_url'));
        $this->withHeaders($headers)->getJson('/api/admin/reports/qr-scans')->assertOk();
        $this->withHeaders($headers)->getJson('/api/admin/reports/manual-usage')->assertOk();
        $this->withHeaders($headers)->getJson('/api/admin/reports/customers')->assertOk();
        $this->withHeaders($headers)->getJson('/api/admin/audit-logs')->assertOk();
    }

    public function test_admin_can_manage_vehicle_qr_type_mappings(): void
    {
        $role = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $admin = User::query()->create([
            'name' => 'Mapping Admin',
            'email' => 'mapping-admin@example.test',
            'password' => 'StrongPass123!',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $vehicle = VehicleVariant::query()->where('code', 'MINI')->firstOrFail();
        $type = QrType::query()->where('code', 'export-non-bull')->firstOrFail();
        VehicleVariantQrType::query()->where('vehicle_variant_id', $vehicle->id)->where('qr_type_id', $type->id)->delete();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/vehicle-qr-types', [
                'vehicle_variant_id' => $vehicle->id,
                'qr_type_id' => $type->id,
            ]);
        $created->assertCreated();
        $created->assertJsonPath('data.vehicle_variant.code', 'MINI')->assertJsonPath('data.qr_type.code', 'export-non-bull');

        $duplicate = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/vehicle-qr-types', [
                'vehicle_variant_id' => $vehicle->id,
                'qr_type_id' => $type->id,
            ]);
        $duplicate->assertStatus(422)->assertJsonValidationErrors('vehicle_variant_id');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/vehicle-qr-types?per_page=100')
            ->assertOk()
            ->assertJsonCount(18, 'data');

        $delete = $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/admin/vehicle-qr-types/'.$created->json('data.id'));
        $delete->assertOk();

        $this->assertDatabaseMissing('vehicle_variant_qr_type', [
            'vehicle_variant_id' => $vehicle->id,
            'qr_type_id' => $type->id,
        ]);
    }

    public function test_master_update_and_delete_receive_the_correct_route_parameters(): void
    {
        $role = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $admin = User::query()->create([
            'name' => 'Master Admin',
            'email' => 'master-admin@example.test',
            'password' => 'StrongPass123!',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $language = Language::query()->create(['code' => 'mr', 'name' => 'Marathi', 'status' => 'active', 'display_order' => 6]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/languages/'.$language->id, ['code' => 'mr', 'name' => 'Marathi (updated)', 'status' => 'active', 'display_order' => 6])
            ->assertOk()
            ->assertJsonPath('data.name', 'Marathi (updated)');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/admin/languages/'.$language->id)
            ->assertOk();

        $this->assertDatabaseMissing('languages', ['id' => $language->id]);
    }
}
