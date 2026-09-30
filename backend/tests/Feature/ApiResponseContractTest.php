<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\Manual;
use App\Models\QrCode;
use App\Models\Setting;
use App\Models\User;
use App\Models\VehicleEngineMapping;
use App\Models\VehicleVariant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApiResponseContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
    }

    public function test_unauthenticated_api_request_returns_json_even_without_accept_header(): void
    {
        $response = $this->get('/api/admin/settings', ['Accept' => '*/*']);

        $response->assertUnauthorized()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_unknown_api_route_returns_json_even_without_accept_header(): void
    {
        $response = $this->get('/api/this-route-does-not-exist', ['Accept' => '*/*']);

        $response->assertNotFound()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonStructure(['message', 'exception']);
    }

    public function test_wrong_method_on_api_route_returns_json_not_html(): void
    {
        $response = $this->post('/api/admin/settings', [], ['Accept' => '*/*']);

        $response->assertStatus(405)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    public function test_admin_can_turn_off_sms_otp_and_the_setting_persists(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/admin/settings', $this->settingsPayload(['otp_enabled' => false]))
            ->assertOk()
            ->assertJsonPath('data.otp_enabled', false);

        $this->assertDatabaseHas('settings', ['key' => 'otp_enabled', 'value' => '0']);

        $this->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.otp_enabled', false);

        $this->putJson('/api/admin/settings', $this->settingsPayload(['otp_enabled' => true]))->assertOk();
        $this->assertDatabaseHas('settings', ['key' => 'otp_enabled', 'value' => '1']);
    }

    public function test_customer_session_can_be_created_without_otp_when_otp_is_disabled(): void
    {
        $this->actingAsAdmin();
        $this->putJson('/api/admin/settings', $this->settingsPayload(['otp_enabled' => false]))->assertOk();

        $response = $this->post('/api/public/customer/session', $this->customerPayload(), ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertCookie('customer_session')
            ->assertJsonPath('data.customer.mobile', '+919876543210')
            ->assertJsonStructure(['data' => ['id', 'customer' => ['name', 'mobile'], 'expires_at']]);

        $token = $response->getCookie('customer_session', false)->getValue();
        $manuals = $this->withCredentials()->withUnencryptedCookie('customer_session', $token)
            ->getJson('/api/customer/manuals');

        $manuals->assertOk()->assertJsonCount(1);
        $this->assertSame(0, \App\Models\OtpVerification::query()->count());
    }

    public function test_direct_customer_session_is_rejected_while_otp_is_enabled(): void
    {
        $this->post('/api/public/customer/session', $this->customerPayload(), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('otp');
    }

    private function actingAsAdmin(): User
    {
        $admin = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'super_admin'))->firstOrFail();
        $this->assertTrue($admin->hasPermission('settings.manage'));
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'otp_enabled' => true,
            'otp_length' => 6,
            'otp_expiry_minutes' => 5,
            'otp_max_attempts' => 5,
            'otp_resend_cooldown' => 30,
            'manual_max_file_size' => 52428800,
            'support_contact' => 'support@example.com',
        ], $overrides);
    }

    private function customerPayload(): array
    {
        $qr = QrCode::query()->firstOrFail();
        $vehicle = VehicleVariant::query()->whereHas('qrTypes', fn ($query) => $query->where('qr_types.id', $qr->qr_type_id))->firstOrFail();

        return [
            'qr_token' => app(\App\Services\PortalService::class)->qrToken($qr),
            'name' => 'Otp Disabled Customer',
            'mobile' => '9876543210',
            'vehicle_variant_id' => $vehicle->id,
            'engine_id' => VehicleEngineMapping::query()->where('vehicle_variant_id', $vehicle->id)->value('engine_id'),
            'language_id' => Language::query()->where('code', 'en')->value('id'),
        ];
    }
}
