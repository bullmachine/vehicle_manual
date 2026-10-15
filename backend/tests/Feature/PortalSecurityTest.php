<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSession;
use App\Models\Fert;
use App\Models\Language;
use App\Models\Machine;
use App\Models\Manual;
use App\Models\QrCode;
use App\Models\Role;
use App\Models\User;
use App\Services\MachineSync;
use App\Services\PortalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            ->assertJsonCount(Language::query()->active()->count(), 'languages')
            ->assertJsonMissingPath('qr.token')
            ->assertJsonMissingPath('qr.token_hash')
            ->assertJsonMissingPath('otp_enabled');

        $this->assertDatabaseHas('qr_scan_logs', ['qr_code_id' => $qr->id]);
        $this->assertSame(1, $qr->fresh()->scan_count);
    }

    public function test_machine_lookup_resolves_the_serial_number_to_its_fert(): void
    {
        $this->postJson('/api/public/machine', ['qr_token' => $this->qrToken(), 'machine_serial_no' => ' bs 255 '])
            ->assertOk()
            ->assertJsonPath('data.machine.serial_no', 'BS255')
            ->assertJsonPath('data.machine.description', 'Bull Smart Backhoe Loader')
            ->assertJsonPath('data.machine.fert.code', '8000001')
            ->assertJsonMissingPath('data.machine.invoice')
            ->assertJsonCount(2, 'data.languages')
            ->assertJsonPath('data.languages.0.code', 'en')
            ->assertJsonPath('data.languages.1.code', 'ta');
    }

    public function test_machine_lookup_only_offers_languages_with_published_manuals(): void
    {
        Manual::query()
            ->where('language_id', Language::query()->where('code', 'ta')->value('id'))
            ->whereHas('ferts', fn ($query) => $query->where('code', '8000001'))
            ->update(['status' => 'unpublished']);

        $this->postJson('/api/public/machine', ['qr_token' => $this->qrToken(), 'machine_serial_no' => 'BS255'])
            ->assertOk()
            ->assertJsonCount(1, 'data.languages')
            ->assertJsonPath('data.languages.0.code', 'en');
    }

    public function test_an_unknown_serial_number_is_rejected(): void
    {
        $this->postJson('/api/public/machine', ['qr_token' => $this->qrToken(), 'machine_serial_no' => 'NOPE999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('machine_serial_no');
    }

    public function test_a_new_fert_in_machine_data_is_added_to_the_master_automatically(): void
    {
        // A machine row whose FERT is not in the master yet (e.g. the FERT was deleted after syncing).
        Machine::query()->create(['msno' => 'XX001', 'fert' => '9999999', 'description' => 'Brand New Loader', 'plant' => '1500']);
        $this->assertDatabaseMissing('ferts', ['code' => '9999999']);

        $this->postJson('/api/public/machine', ['qr_token' => $this->qrToken(), 'machine_serial_no' => 'XX001'])
            ->assertOk()
            ->assertJsonPath('data.machine.fert.code', '9999999')
            ->assertJsonPath('data.machine.description', 'Brand New Loader')
            ->assertJsonCount(0, 'data.languages');

        $this->assertDatabaseHas('ferts', ['code' => '9999999', 'name' => 'Brand New Loader', 'status' => 'active']);
    }

    public function test_a_machine_with_an_inactive_or_missing_fert_is_rejected(): void
    {
        $this->useMachineData([['MSNO' => 'NOFERT1', 'DESCRIPTION' => 'No FERT on record']]);
        $this->postJson('/api/public/machine', ['qr_token' => $this->qrToken(), 'machine_serial_no' => 'NOFERT1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('machine_serial_no');

        $this->useMachineData([['MSNO' => 'BS255', 'FERT' => '8000001']]);
        Fert::query()->where('code', '8000001')->update(['status' => 'inactive']);
        $this->postJson('/api/public/machine', ['qr_token' => $this->qrToken(), 'machine_serial_no' => 'BS255'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('machine_serial_no');
    }

    public function test_machine_lookup_requires_a_valid_qr(): void
    {
        $this->postJson('/api/public/machine', ['qr_token' => str_repeat('a', 64), 'machine_serial_no' => 'BS255'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('qr');

        QrCode::query()->update(['status' => 'inactive']);
        $this->postJson('/api/public/customer/session', $this->sessionPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('qr');
    }

    public function test_invalid_qr_is_rejected_without_creating_a_scan_log(): void
    {
        $response = $this->getJson('/api/public/qr/'.str_repeat('a', 64));

        $response->assertStatus(422)->assertJsonValidationErrors('qr');
        $this->assertDatabaseCount('qr_scan_logs', 0);
    }

    public function test_session_is_limited_to_the_machines_fert_and_chosen_language(): void
    {
        $token = $this->qrToken();
        $this->getJson('/api/public/qr/'.$token)->assertOk();

        $created = $this->postJson('/api/public/customer/session', $this->sessionPayload());
        $created->assertOk()
            ->assertJsonPath('data.machine.serial_no', 'BS255')
            ->assertJsonPath('data.language.name', 'English')
            ->assertCookie('customer_session');

        $this->assertDatabaseHas('qr_scan_logs', [
            'customer_session_id' => $created->json('data.id'),
            'machine_serial_no' => 'BS255',
            'fert_id' => Fert::query()->where('code', '8000001')->value('id'),
        ]);

        $sessionToken = $created->getCookie('customer_session', false)->getValue();
        $this->withCredentials()->withUnencryptedCookie('customer_session', $sessionToken)
            ->getJson('/api/customer/session')
            ->assertOk()
            ->assertJsonPath('data.id', $created->json('data.id'));

        $manuals = $this->withCredentials()->withUnencryptedCookie('customer_session', $sessionToken)
            ->getJson('/api/customer/manuals');
        $manuals->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.machine', 'Bull Smart Backhoe Loader');
        $authorizedId = $manuals->json('data.0.id');

        $otherFertManual = Manual::query()->whereHas('ferts', fn ($query) => $query->where('code', '8000002'))->firstOrFail();
        $otherLanguageManual = Manual::query()
            ->whereKeyNot($authorizedId)
            ->whereHas('ferts', fn ($query) => $query->where('code', '8000001'))
            ->firstOrFail();

        foreach ([$otherFertManual, $otherLanguageManual] as $manual) {
            $this->withCredentials()->withUnencryptedCookie('customer_session', $sessionToken)
                ->getJson('/api/customer/manuals/'.$manual->id)
                ->assertNotFound();
        }

        $page = $this->withCredentials()->withUnencryptedCookie('customer_session', $sessionToken)
            ->get('/api/customer/manuals/'.$authorizedId.'/pages/1');
        $page->assertOk()->assertHeader('content-type', 'image/svg+xml; charset=UTF-8');
        $this->assertStringContainsString('MSNO BS255', $page->getContent());
    }

    public function test_session_rejects_an_inactive_language_and_a_missing_name(): void
    {
        $language = Language::query()->where('code', 'hi')->firstOrFail();
        $language->update(['status' => 'inactive']);

        $this->postJson('/api/public/customer/session', $this->sessionPayload(['language_id' => $language->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('language_id');

        $this->postJson('/api/public/customer/session', $this->sessionPayload(['name' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_customer_routes_reject_missing_or_revoked_sessions(): void
    {
        $this->getJson('/api/customer/manuals')->assertUnauthorized();

        $qr = QrCode::query()->firstOrFail();
        $customer = Customer::query()->create(['name' => 'Revoked Customer', 'mobile' => '+919876543211']);
        $session = CustomerSession::query()->create([
            'session_token_hash' => hash('sha256', 'revoked-session'),
            'customer_id' => $customer->id,
            'qr_code_id' => $qr->id,
            'fert_id' => Fert::query()->value('id'),
            'machine_serial_no' => 'BS255',
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
        $this->withHeaders($headers)->getJson('/api/admin/ferts?per_page=100')->assertOk();
        $qrList = $this->withHeaders($headers)->getJson('/api/admin/qr-codes?per_page=100');
        $qrList->assertOk();
        $this->assertIsString($qrList->json('data.0.qr_url'));
        $this->withHeaders($headers)->getJson('/api/admin/reports/qr-scans')->assertOk();
        $this->withHeaders($headers)->getJson('/api/admin/reports/manual-usage')->assertOk();
        $this->withHeaders($headers)->getJson('/api/admin/reports/customers')->assertOk();
        $this->withHeaders($headers)->getJson('/api/admin/audit-logs')->assertOk();
    }

    public function test_admin_can_manage_ferts_and_map_manuals_to_them(): void
    {
        $admin = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'super_admin'))->firstOrFail();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/ferts', ['code' => '8000009', 'name' => 'Bull Test Loader', 'status' => 'active', 'display_order' => 9]);
        $created->assertCreated()->assertJsonPath('data.code', '8000009');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/ferts', ['code' => '8000009', 'name' => 'Duplicate', 'status' => 'active'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $manual = Manual::query()->whereHas('ferts', fn ($query) => $query->where('code', '8000001'))->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/manuals/'.$manual->id, ['fert_ids' => [$created->json('data.id')]])
            ->assertOk()
            ->assertJsonPath('data.ferts.0.code', '8000009');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/manuals?fert_id='.$created->json('data.id'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->useMachineData([['MSNO' => 'TL900', 'FERT' => '8000009', 'DESCRIPTION' => 'Bull Test Loader']]);
        $this->postJson('/api/public/machine', ['qr_token' => $this->qrToken(), 'machine_serial_no' => 'TL900'])
            ->assertOk()
            ->assertJsonCount(1, 'data.languages');
    }

    public function test_admin_can_bulk_import_ferts_from_csv_or_machine_json(): void
    {
        $admin = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'super_admin'))->firstOrFail();
        Fert::query()->where('code', '8000001')->update(['name' => 'Old name']);

        $csv = UploadedFile::fake()->createWithContent('ferts.csv', "FERT,DESCRIPTION\n8100001,Bull Loader A\n8100002,Bull Loader B\n8100001,Duplicate row\n,Missing code\n8000001,Bull Smart Backhoe Loader\n");
        $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/ferts/import', ['file' => $csv], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.skipped', 2);

        $this->assertDatabaseHas('ferts', ['code' => '8100002', 'name' => 'Bull Loader B', 'status' => 'active']);
        $this->assertDatabaseHas('ferts', ['code' => '8000001', 'name' => 'Bull Smart Backhoe Loader']);

        $json = UploadedFile::fake()->createWithContent('machines.json', json_encode([
            ['INVOICE' => '1', 'FERT' => '8200001', 'DESCRIPTION' => 'Bull Excavator', 'PLANT' => '1500', 'MSNO' => 'EX1'],
            ['INVOICE' => '2', 'FERT' => '8200001', 'DESCRIPTION' => 'Bull Excavator', 'PLANT' => '1500', 'MSNO' => 'EX2'],
        ]));
        $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/ferts/import', ['file' => $json], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.total', 2);

        $this->assertDatabaseHas('audit_logs', ['action' => 'imported', 'module' => 'ferts']);
    }

    public function test_fert_master_search_finds_a_fert_beyond_the_first_page(): void
    {
        $admin = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'super_admin'))->firstOrFail();
        foreach (range(1, 260) as $number) {
            Fert::query()->create(['code' => (string) (8300000 + $number), 'name' => 'Bulk FERT '.$number, 'status' => 'active']);
        }

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/ferts?per_page=50&q=8300250')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Bulk FERT 250');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/ferts?per_page=50&page=6')
            ->assertOk()
            ->assertJsonPath('last_page', 6);
    }

    /**
     * Store machine records as if they had been synced from SAP.
     */
    private function useMachineData(array $records): void
    {
        app(MachineSync::class)->store($records, 'sap');
    }

    private function qrToken(): string
    {
        return app(PortalService::class)->qrToken(QrCode::query()->firstOrFail());
    }

    private function sessionPayload(array $overrides = []): array
    {
        return array_merge([
            'qr_token' => $this->qrToken(),
            'machine_serial_no' => 'BS255',
            'name' => 'Test Customer',
            'mobile' => '9876543210',
            'language_id' => Language::query()->where('code', 'en')->value('id'),
        ], $overrides);
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
