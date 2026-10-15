<?php

namespace Tests\Feature;

use App\Models\Fert;
use App\Models\Machine;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MachineSyncTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://sap.test/sap/bc/zsap_data_api';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        config([
            'services.sap_machines.url' => self::URL,
            'services.sap_machines.username' => 'RFC_TEST',
            'services.sap_machines.password' => 'secret',
            'services.sap_machines.plants' => ['1500'],
        ]);
    }

    public function test_sync_calls_sap_with_the_documented_request_and_stores_machines(): void
    {
        Http::fake([self::URL => Http::response([
            ['INVOICE' => '90000072', 'FERT' => '8000001', 'DESCRIPTION' => 'Bull Smart Backhoe Loader', 'PLANT' => '1500', 'MSNO' => 'BS255', 'DELIVERY_DATE' => '20130129'],
            ['INVOICE' => '90000080', 'FERT' => '8000001', 'DESCRIPTION' => 'Bull Smart Backhoe Loader', 'PLANT' => '1500', 'MSNO' => 'BS264', 'DELIVERY_DATE' => '20130118'],
            ['INVOICE' => '90000099', 'FERT' => '8800001', 'DESCRIPTION' => 'Bull New Model', 'PLANT' => '1500', 'MSNO' => 'nm 001', 'DELIVERY_DATE' => '00000000'],
            ['INVOICE' => '90000100', 'FERT' => '', 'DESCRIPTION' => 'No FERT', 'PLANT' => '1500', 'MSNO' => 'BAD1'],
        ])]);

        $this->artisan('machines:sync', ['--from' => '20130110', '--to' => '20130130'])
            ->expectsOutputToContain('4 received, 2 new, 1 updated, 1 skipped, 1 new FERTs')
            ->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === self::URL
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('RFC_TEST:secret'))
            && $request->data() === ['API_TYP' => 'UM_API', 'PLANT' => '1500', 'FROM_DT' => '20130110', 'TO_DT' => '20130130']);

        $this->assertDatabaseHas('machines', ['msno' => 'BS264', 'fert' => '8000001', 'invoice' => '90000080', 'source' => 'sap']);
        $this->assertSame('2013-01-18', Machine::query()->where('msno', 'BS264')->value('delivery_date')->toDateString());
        $this->assertDatabaseHas('machines', ['msno' => 'NM001', 'fert' => '8800001', 'delivery_date' => null]);
        $this->assertDatabaseMissing('machines', ['msno' => 'BAD1']);
        $this->assertDatabaseHas('ferts', ['code' => '8800001', 'name' => 'Bull New Model', 'status' => 'active']);
    }

    public function test_multi_machine_invoice_lines_are_split_into_separate_serials(): void
    {
        Http::fake([self::URL => Http::response([
            ['INVOICE' => '1420013117', 'FERT' => '8004629', 'DESCRIPTION' => 'SS-76-BHL', 'PLANT' => '1500', 'MSNO' => '10.HDSS8011', 'DELIVERY_DATE' => '20261001'],
            ['INVOICE' => '1420013200', 'FERT' => '8004700', 'DESCRIPTION' => 'HP43 Loader', 'PLANT' => '1500', 'MSNO' => '10.HP43C00865/HP43C00866/HP43C00867', 'DELIVERY_DATE' => '20261002'],
            ['INVOICE' => '1420013201', 'FERT' => '8004700', 'DESCRIPTION' => 'HP43 Loader', 'PLANT' => '1500', 'MSNO' => '.10.HP43C00892/HP43C00894', 'DELIVERY_DATE' => '20261002'],
            ['INVOICE' => '1420013202', 'FERT' => '8004700', 'DESCRIPTION' => 'HP43 Loader', 'PLANT' => '1500', 'MSNO' => 'HN2588, HN2589', 'DELIVERY_DATE' => '20261002'],
            ['INVOICE' => '1421007185', 'FERT' => '8004629', 'DESCRIPTION' => 'SS-76-BHL', 'PLANT' => '1500', 'MSNO' => '.', 'DELIVERY_DATE' => '20261002'],
            ['INVOICE' => '1421007186', 'FERT' => '8004629', 'DESCRIPTION' => 'SS-76-BHL', 'PLANT' => '1500', 'MSNO' => '', 'DELIVERY_DATE' => '20261002'],
            ['INVOICE' => '1421007300', 'FERT' => '8004575', 'DESCRIPTION' => '49HP BS-V', 'PLANT' => '1500', 'MSNO' => 'CH4900003', 'DELIVERY_DATE' => '20261001'],
            ['INVOICE' => '1421007301', 'FERT' => '8004575', 'DESCRIPTION' => '49HP BS-V', 'PLANT' => '1500', 'MSNO' => "\u{00A0}HDSS7504", 'DELIVERY_DATE' => '20261001'],
            ['INVOICE' => '1421007302', 'FERT' => '8004575', 'DESCRIPTION' => '49HP BS-V', 'PLANT' => '1500', 'MSNO' => 'HH3883.', 'DELIVERY_DATE' => '20261001'],
        ])]);

        $this->artisan('machines:sync', ['--from' => '20261001', '--to' => '20261014'])
            ->expectsOutputToContain('9 received, 11 new, 0 updated, 2 skipped, 3 new FERTs')
            ->assertExitCode(0);

        foreach (['HDSS8011', 'HP43C00865', 'HP43C00866', 'HP43C00867', 'HP43C00892', 'HP43C00894', 'HN2588', 'HN2589', 'CH4900003', 'HDSS7504', 'HH3883'] as $serial) {
            $this->assertDatabaseHas('machines', ['msno' => $serial]);
        }
        $this->assertDatabaseHas('machines', ['msno' => 'HP43C00866', 'fert' => '8004700', 'invoice' => '1420013200']);
        $this->assertDatabaseMissing('machines', ['msno' => '10.HDSS8011']);
        $this->assertDatabaseMissing('machines', ['msno' => '.']);
    }

    public function test_long_ranges_are_split_into_chunks(): void
    {
        Http::fake([self::URL => Http::response([])]);

        $this->artisan('machines:sync', ['--from' => '2013-01-01', '--to' => '2013-03-15', '--chunk' => 31])->assertExitCode(0);

        $ranges = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data()['FROM_DT'].'-'.$pair[0]->data()['TO_DT'])->all();
        $this->assertSame(['20130101-20130131', '20130201-20130303', '20130304-20130315'], $ranges);
    }

    public function test_sync_reports_bad_credentials_and_unreachable_sap(): void
    {
        Http::fake([self::URL => Http::response('Unauthorized', 401)]);
        $this->artisan('machines:sync', ['--from' => '20130110', '--to' => '20130130'])
            ->expectsOutputToContain('rejected the credentials')
            ->assertExitCode(1);

        config(['services.sap_machines.url' => null]);
        $this->artisan('machines:sync')
            ->expectsOutputToContain('SAP_MACHINE_API_URL')
            ->assertExitCode(1);
    }

    public function test_synced_serial_numbers_are_found_by_the_customer_lookup(): void
    {
        Http::fake([self::URL => Http::response([
            ['INVOICE' => '90000080', 'FERT' => '8000001', 'DESCRIPTION' => 'Bull Smart Backhoe Loader', 'PLANT' => '1500', 'MSNO' => 'BS264', 'DELIVERY_DATE' => '20130118'],
        ])]);
        $this->artisan('machines:sync', ['--from' => '20130110', '--to' => '20130130'])->assertExitCode(0);

        $token = app(\App\Services\PortalService::class)->qrToken(\App\Models\QrCode::query()->firstOrFail());
        $this->postJson('/api/public/machine', ['qr_token' => $token, 'machine_serial_no' => 'bs264'])
            ->assertOk()
            ->assertJsonPath('data.machine.fert.code', '8000001')
            ->assertJsonCount(2, 'data.languages');
    }

    public function test_the_dummy_json_file_can_be_loaded_with_the_same_command(): void
    {
        Machine::query()->delete();

        $this->artisan('machines:sync', ['--file' => config('portal.machine_data_path')])
            ->expectsOutputToContain('5 received, 5 new')
            ->assertExitCode(0);

        $this->assertSame(5, Machine::query()->where('source', 'file')->count());
        $this->assertTrue(Fert::query()->where('code', '8000004')->exists());
    }

    public function test_admin_can_list_and_search_synced_machines(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/machines?q=bs25')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.total', 5)
            ->assertJsonPath('summary.sap_configured', true)
            ->assertJsonPath('summary.last_sync', null)
            ->assertJsonPath('data.0.fert_name', 'Bull Smart Backhoe Loader');

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/machines?fert=8000004')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.msno', 'WL700');
    }

    public function test_admin_can_sync_from_sap_and_the_run_is_recorded(): void
    {
        Http::fake([self::URL => Http::response([
            ['INVOICE' => '90000080', 'FERT' => '8000001', 'DESCRIPTION' => 'Bull Smart Backhoe Loader', 'PLANT' => '1500', 'MSNO' => 'BS264', 'DELIVERY_DATE' => '20130118'],
        ])]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/machines/sync', ['from' => '2013-01-10', 'to' => '2013-01-30'])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('last_sync.status', 'success')
            ->assertJsonPath('last_sync.by', 'admin@example.com')
            ->assertJsonPath('last_sync.plants', ['1500']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'synced', 'module' => 'machines']);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/machines')
            ->assertJsonPath('summary.last_sync.result.created', 1);
    }

    public function test_a_failed_admin_sync_is_reported_and_recorded(): void
    {
        Http::fake([self::URL => Http::response('Unauthorized', 401)]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/machines/sync', ['from' => '2013-01-10', 'to' => '2013-01-30', 'plants' => ['1500']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sync');

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/machines')
            ->assertJsonPath('summary.last_sync.status', 'failed');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/machines/sync', ['from' => '2013-01-30', 'to' => '2013-01-10'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    public function test_machines_require_the_masters_permission(): void
    {
        $viewer = User::query()->create([
            'name' => 'Viewer',
            'email' => 'machines-viewer@example.test',
            'password' => 'StrongPass123!',
            'role_id' => Role::query()->where('slug', 'viewer')->value('id'),
            'is_active' => true,
        ]);

        $this->actingAs($viewer, 'sanctum')->getJson('/api/admin/machines')->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson('/api/admin/machines/sync', ['from' => '2013-01-10', 'to' => '2013-01-30'])->assertForbidden();
    }

    private function admin(): User
    {
        return User::query()->whereHas('role', fn ($query) => $query->where('slug', 'super_admin'))->firstOrFail();
    }
}
