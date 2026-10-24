<?php

namespace Tests\Feature;

use App\Models\Fert;
use App\Models\Language;
use App\Models\QrCode;
use App\Models\QrType;
use App\Models\User;
use App\Services\PortalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A machine opens only from a QR code whose type (Domestic, Export, ...) its FERT is mapped to.
 */
class QrTypeAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_domestic_machine_opens_from_a_domestic_qr_but_not_from_an_export_qr(): void
    {
        $this->mapFert('8000001', ['domestic']);

        $this->lookup($this->qrToken('domestic'), 'BS255')->assertOk()->assertJsonPath('data.machine.fert.code', '8000001');

        $this->lookup($this->qrToken('export'), 'BS255')
            ->assertStatus(422)
            ->assertJsonPath('errors.machine_serial_no.0', 'This machine is not available on this QR code. Please scan the QR label on your machine or contact support.');
    }

    public function test_the_session_is_refused_too_so_the_check_cannot_be_skipped(): void
    {
        $this->mapFert('8000001', ['domestic']);

        $this->postJson('/api/public/customer/session', [
            'qr_token' => $this->qrToken('export'),
            'machine_serial_no' => 'BS255',
            'language_id' => Language::query()->where('code', 'en')->value('id'),
            'name' => 'Export Customer',
        ])->assertStatus(422)->assertJsonValidationErrors('machine_serial_no');

        $this->assertDatabaseCount('customer_sessions', 0);
    }

    public function test_a_fert_mapped_to_several_types_opens_from_each_of_them(): void
    {
        $this->mapFert('8000001', ['domestic', 'export']);

        $this->lookup($this->qrToken('domestic'), 'BS255')->assertOk();
        $this->lookup($this->qrToken('export'), 'BS255')->assertOk();
        $this->lookup($this->qrToken('export-non-bull'), 'BS255')->assertStatus(422);
    }

    public function test_an_unmapped_fert_opens_from_every_qr_type(): void
    {
        $this->assertSame(0, Fert::query()->where('code', '8000001')->firstOrFail()->qrTypes()->count());

        foreach (['domestic', 'export', 'export-non-bull'] as $type) {
            $this->lookup($this->qrToken($type), 'BS255')->assertOk();
        }
    }

    public function test_admin_maps_qr_types_on_the_fert_master_and_can_filter_by_them(): void
    {
        $admin = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'super_admin'))->firstOrFail();
        $fert = Fert::query()->where('code', '8000001')->firstOrFail();
        $domestic = QrType::query()->where('code', 'domestic')->value('id');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/ferts/'.$fert->id, ['code' => $fert->code, 'name' => $fert->name, 'status' => 'active', 'qr_type_ids' => [$domestic]])
            ->assertOk()
            ->assertJsonPath('data.qr_types.0.code', 'domestic');

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/ferts?qr_type_id='.$domestic)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/ferts?qr_type_id=none')->assertOk()
            ->assertJsonMissing(['code' => '8000001']);

        // An update without qr_type_ids leaves the mapping alone; an empty list clears it.
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/ferts/'.$fert->id, ['code' => $fert->code, 'name' => 'Renamed', 'status' => 'active'])
            ->assertOk()->assertJsonCount(1, 'data.qr_types');
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/ferts/'.$fert->id, ['code' => $fert->code, 'name' => 'Renamed', 'status' => 'active', 'qr_type_ids' => []])
            ->assertOk()->assertJsonCount(0, 'data.qr_types');
    }

    public function test_csv_import_maps_qr_types_and_rejects_an_unknown_one_without_partial_changes(): void
    {
        $admin = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'super_admin'))->firstOrFail();
        $csv = "FERT,DESCRIPTION,QR_TYPE\n8000001,,domestic\n8000002,,Export;domestic\n8000003,,\n";

        $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/ferts/import', ['file' => UploadedFile::fake()->createWithContent('ferts.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.updated', 2)
            ->assertJsonPath('data.skipped', 1);

        $this->assertSame(['domestic'], $this->mappedCodes('8000001'));
        $this->assertSame(['domestic', 'export'], $this->mappedCodes('8000002'));
        $this->assertSame([], $this->mappedCodes('8000003'));

        $bad = "FERT,DESCRIPTION,QR_TYPE\n8000003,,domestic\n8000001,,overseas\n";
        $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/ferts/import', ['file' => UploadedFile::fake()->createWithContent('ferts.csv', $bad)], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
        $this->assertSame([], $this->mappedCodes('8000003'));
    }

    private function mapFert(string $code, array $types): void
    {
        Fert::query()->where('code', $code)->firstOrFail()->qrTypes()->sync(QrType::query()->whereIn('code', $types)->pluck('id'));
    }

    private function mappedCodes(string $code): array
    {
        return Fert::query()->where('code', $code)->firstOrFail()->qrTypes()->orderBy('code')->pluck('code')->all();
    }

    private function lookup(string $token, string $serial)
    {
        return $this->postJson('/api/public/machine', ['qr_token' => $token, 'machine_serial_no' => $serial]);
    }

    private function qrToken(string $typeCode): string
    {
        $type = QrType::query()->where('code', $typeCode)->firstOrFail();
        $existing = QrCode::query()->where('qr_type_id', $type->id)->first();
        if ($existing) {
            return app(PortalService::class)->qrToken($existing);
        }

        $token = Str::random(64);
        QrCode::query()->create([
            'reference' => 'QR-TEST-'.Str::upper($typeCode),
            'token_hash' => hash('sha256', $token),
            'token_ciphertext' => Crypt::encryptString($token),
            'qr_type_id' => $type->id,
            'label' => $type->name.' test label',
            'status' => 'active',
        ]);

        return $token;
    }
}
