<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Language;
use App\Models\QrCode;
use App\Models\User;
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

    public function test_admin_settings_persist_and_no_longer_include_otp(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/admin/settings', ['manual_max_file_size' => 10485760, 'support_contact' => 'help@example.com'])
            ->assertOk()
            ->assertJsonPath('data.manual_max_file_size', 10485760)
            ->assertJsonPath('data.support_contact', 'help@example.com')
            ->assertJsonMissingPath('data.otp_enabled');

        $this->assertDatabaseHas('settings', ['key' => 'manual_max_file_size', 'value' => '10485760']);
    }

    public function test_customer_session_is_created_from_machine_serial_number_with_mobile(): void
    {
        $response = $this->post('/api/public/customer/session', $this->customerPayload(), ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertCookie('customer_session')
            ->assertJsonPath('data.customer.mobile', '+919876543210')
            ->assertJsonPath('data.machine.serial_no', 'BS255')
            ->assertJsonPath('data.machine.fert.code', '8000001')
            ->assertJsonStructure(['data' => ['id', 'customer' => ['name', 'mobile'], 'machine' => ['serial_no', 'fert'], 'language', 'expires_at']]);

        $token = $response->getCookie('customer_session', false)->getValue();
        $this->withCredentials()->withUnencryptedCookie('customer_session', $token)
            ->getJson('/api/customer/manuals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Bull Smart Backhoe Loader Operator Manual');
    }

    public function test_customer_session_can_be_created_without_a_mobile_number(): void
    {
        $this->post('/api/public/customer/session', $this->customerPayload(['mobile' => null]), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertCookie('customer_session')
            ->assertJsonPath('data.customer.mobile', null)
            ->assertJsonPath('data.customer.name', 'Serial Customer');

        $this->post('/api/public/customer/session', $this->customerPayload(['mobile' => '']), ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame(2, Customer::query()->whereNull('mobile')->count());
    }

    public function test_otp_endpoints_no_longer_exist(): void
    {
        $this->postJson('/api/public/customer/request-otp', $this->customerPayload())->assertNotFound();
        $this->postJson('/api/public/customer/verify-otp', $this->customerPayload())->assertNotFound();
    }

    private function actingAsAdmin(): User
    {
        $admin = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'super_admin'))->firstOrFail();
        $this->assertTrue($admin->hasPermission('settings.manage'));
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    private function customerPayload(array $overrides = []): array
    {
        $qr = QrCode::query()->firstOrFail();

        return array_merge([
            'qr_token' => app(\App\Services\PortalService::class)->qrToken($qr),
            'machine_serial_no' => 'BS255',
            'name' => 'Serial Customer',
            'mobile' => '9876543210',
            'language_id' => Language::query()->where('code', 'en')->value('id'),
        ], $overrides);
    }
}
