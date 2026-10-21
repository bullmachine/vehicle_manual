<?php

namespace Tests\Feature;

use App\Models\Fert;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class FertExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_can_download_the_fert_master_as_excel(): void
    {
        Fert::query()->create(['code' => '0800123', 'name' => 'Loader <A & B>', 'status' => 'inactive']);
        $admin = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'super_admin'))->firstOrFail();

        $response = $this->actingAs($admin, 'sanctum')->get('/api/admin/ferts/export');

        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $sheet = $this->sheetXml($response->getFile()->getPathname());
        $this->assertStringContainsString('<t xml:space="preserve">FERT No</t>', $sheet);
        $this->assertStringContainsString('<t xml:space="preserve">0800123</t>', $sheet);
        $this->assertStringContainsString('Loader &lt;A &amp; B&gt;', $sheet);
        $this->assertStringContainsString('<t xml:space="preserve">Inactive</t>', $sheet);
        $this->assertDatabaseHas('audit_logs', ['action' => 'exported', 'module' => 'ferts']);
    }

    public function test_export_honours_the_search_filter_and_requires_permission(): void
    {
        $admin = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'super_admin'))->firstOrFail();

        $response = $this->actingAs($admin, 'sanctum')->get('/api/admin/ferts/export?q=8000002');
        $sheet = $this->sheetXml($response->getFile()->getPathname());
        $this->assertStringContainsString('8000002', $sheet);
        $this->assertStringNotContainsString('8000001', $sheet);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/ferts/export')->assertUnauthorized();
    }

    private function sheetXml(string $path): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        return $xml;
    }
}
