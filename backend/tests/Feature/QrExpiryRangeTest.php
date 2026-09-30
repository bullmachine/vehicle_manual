<?php

namespace Tests\Feature;

use App\Models\QrCode;
use App\Models\QrType;
use App\Models\Role;
use App\Models\User;
use App\Services\PortalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QrExpiryRangeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::query()->create([
            'name' => 'Expiry Admin',
            'email' => 'expiry-admin@example.test',
            'password' => 'StrongPass123!',
            'role_id' => Role::query()->where('slug', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    public function test_qr_can_be_created_with_an_expiry_beyond_the_2038_timestamp_limit(): void
    {
        $type = QrType::query()->where('code', 'domestic')->firstOrFail();

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/qr-codes', [
            'qr_type_id' => $type->id,
            'label' => 'Never expires',
            'expires_at' => '2100-01-01',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.label', 'Never expires')
            ->assertJsonPath('data.expires_at', '2100-01-01T00:00:00.000000Z');

        $this->assertDatabaseHas('qr_codes', [
            'label' => 'Never expires',
            'expires_at' => '2100-01-01 00:00:00',
        ]);
    }

    public function test_a_qr_with_a_far_future_expiry_is_still_scannable(): void
    {
        $type = QrType::query()->where('code', 'domestic')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/qr-codes', [
            'qr_type_id' => $type->id,
            'label' => 'Long lived',
            'expires_at' => '2100-01-01',
        ])->assertCreated();

        $qr = QrCode::query()->where('label', 'Long lived')->firstOrFail();
        $token = app(PortalService::class)->qrToken($qr);

        $this->getJson('/api/public/qr/'.$token)
            ->assertOk()
            ->assertJsonPath('qr.reference', $qr->reference)
            ->assertJsonCount(6, 'options.vehicle_variants');
    }

    public function test_an_impossible_expiry_date_is_rejected_with_a_validation_error(): void
    {
        $type = QrType::query()->where('code', 'domestic')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/qr-codes', [
            'qr_type_id' => $type->id,
            'label' => 'Broken date',
            'expires_at' => '9999-99-99',
        ])->assertStatus(422)->assertJsonValidationErrors('expires_at');

        $this->assertDatabaseMissing('qr_codes', ['label' => 'Broken date']);
    }

    public function test_an_omitted_expiry_stays_null(): void
    {
        $type = QrType::query()->where('code', 'domestic')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/qr-codes', [
            'qr_type_id' => $type->id,
            'label' => 'No expiry',
        ])->assertCreated()->assertJsonPath('data.expires_at', null);

        $this->assertDatabaseHas('qr_codes', ['label' => 'No expiry', 'expires_at' => null]);
    }
}
