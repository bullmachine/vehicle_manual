<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSession;
use App\Models\Manual;
use App\Models\QrCode;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualVersionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_can_add_a_new_version_to_an_existing_manual(): void
    {
        $this->actingAsAdmin();
        $manual = $this->publishedManual();

        $response = $this->postJson('/api/admin/manuals/'.$manual->id.'/versions', [
            'version' => '4.0',
            'effective_date' => '2026-04-01',
            'status' => 'published',
            'pdf' => $this->fakePdf(),
        ]);

        $response->assertCreated()->assertJsonPath('data.version', '4.0');
        $this->assertSame(2, $manual->versions()->count());
        $this->assertDatabaseHas('manuals', ['id' => $manual->id, 'status' => 'published']);
    }

    public function test_a_duplicate_version_label_is_rejected_for_the_same_manual(): void
    {
        $this->actingAsAdmin();
        $manual = $this->publishedManual();
        $existing = $manual->versions()->firstOrFail()->version;

        $this->postJson('/api/admin/manuals/'.$manual->id.'/versions', [
            'version' => $existing,
            'pdf' => $this->fakePdf(),
        ])->assertStatus(422)->assertJsonValidationErrors('version');

        $this->assertSame(1, $manual->versions()->count());
    }

    public function test_the_same_version_label_is_allowed_on_a_different_manual(): void
    {
        $this->actingAsAdmin();
        $first = $this->publishedManual();
        $label = $first->versions()->firstOrFail()->version;
        $second = $this->createManual(['version' => '1.0'])->assertCreated();

        $this->postJson('/api/admin/manuals/'.$second->json('data.id').'/versions', [
            'version' => $label,
            'pdf' => $this->fakePdf(),
        ])->assertCreated();

        $this->assertSame($label, Manual::query()->findOrFail($second->json('data.id'))->versions()->where('version', $label)->value('version'));
    }

    public function test_customer_sees_every_published_version_newest_first(): void
    {
        $this->actingAsAdmin();
        $manual = $this->publishedManual();
        $original = $manual->versions()->where('status', 'published')->firstOrFail();
        $token = $this->customerSessionFor($manual);

        $this->postJson('/api/admin/manuals/'.$manual->id.'/versions', [
            'version' => '4.0',
            'status' => 'published',
            'pdf' => $this->fakePdf(),
        ])->assertCreated();

        $manuals = $this->withCredentials()->withUnencryptedCookie('customer_session', $token)
            ->getJson('/api/customer/manuals');

        $manuals->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(2, 'data.0.versions');

        $versions = $manuals->json('data.0.versions');
        $this->assertSame('4.0', $versions[0]['version']);
        $this->assertSame($original->version, $versions[1]['version']);
        $this->assertGreaterThan($versions[1]['id'], $versions[0]['id']);
    }

    public function test_older_versions_stay_visible_when_show_old_versions_is_false(): void
    {
        $this->actingAsAdmin();
        $manual = $this->publishedManual();
        $token = $this->customerSessionFor($manual);

        $this->postJson('/api/admin/manuals/'.$manual->id.'/versions', [
            'version' => '4.0',
            'status' => 'published',
            'pdf' => $this->fakePdf(),
        ])->assertCreated();

        Manual::query()->whereKey($manual->id)->update(['show_old_versions' => false]);

        $this->withCredentials()->withUnencryptedCookie('customer_session', $token)
            ->getJson('/api/customer/manuals')
            ->assertOk()
            ->assertJsonCount(2, 'data.0.versions');
    }

    public function test_draft_versions_are_never_visible_to_customers(): void
    {
        $this->actingAsAdmin();
        $manual = $this->publishedManual();
        $token = $this->customerSessionFor($manual);

        $this->postJson('/api/admin/manuals/'.$manual->id.'/versions', [
            'version' => '5.0-draft',
            'status' => 'draft',
            'pdf' => $this->fakePdf(),
        ])->assertCreated();

        $this->withCredentials()->withUnencryptedCookie('customer_session', $token)
            ->getJson('/api/customer/manuals')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.versions')
            ->assertJsonPath('data.0.versions.0.status', null);

        $this->assertSame('5.0-draft', $manual->versions()->where('status', 'draft')->value('version'));
    }

    public function test_multiple_manuals_sharing_a_configuration_are_all_visible(): void
    {
        $this->actingAsAdmin();
        $manual = $this->publishedManual();
        $token = $this->customerSessionFor($manual);
        $before = $this->withCredentials()->withUnencryptedCookie('customer_session', $token)
            ->getJson('/api/customer/manuals')->json('data');

        $this->createManual([
            'title' => 'Second Document For Same Configuration',
            'vehicle_variant_id' => $manual->vehicle_variant_id,
            'engine_id' => $manual->engine_id,
            'language_id' => $manual->language_id,
            'manual_type_id' => $manual->manual_type_id,
            'qr_type_ids' => $manual->qrTypes()->pluck('qr_types.id')->all(),
            'status' => 'published',
        ])->assertCreated();

        $after = $this->withCredentials()->withUnencryptedCookie('customer_session', $token)
            ->getJson('/api/customer/manuals')->json('data');

        $this->assertCount(count($before) + 1, $after);
        $this->assertSame(2, $this->duplicateConfigurationCount($after, $manual));
    }

    public function test_archiving_one_version_keeps_the_manual_visible(): void
    {
        $this->actingAsAdmin();
        $manual = $this->publishedManual();
        $token = $this->customerSessionFor($manual);

        $this->postJson('/api/admin/manuals/'.$manual->id.'/versions', [
            'version' => '4.0',
            'status' => 'published',
            'pdf' => $this->fakePdf(),
        ])->assertCreated();

        $older = $manual->versions()->where('version', '!=', '4.0')->firstOrFail();

        $this->postJson('/api/admin/manuals/'.$manual->id.'/versions/'.$older->id.'/archive')
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->withCredentials()->withUnencryptedCookie('customer_session', $token)
            ->getJson('/api/customer/manuals')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.versions')
            ->assertJsonPath('data.0.versions.0.version', '4.0');

        $this->assertDatabaseHas('audit_logs', ['action' => 'version_archived']);
    }

    public function test_archiving_the_last_published_version_is_rejected(): void
    {
        $this->actingAsAdmin();
        $manual = $this->publishedManual();
        $version = $manual->versions()->where('status', 'published')->firstOrFail();

        $this->postJson('/api/admin/manuals/'.$manual->id.'/versions/'.$version->id.'/archive')
            ->assertStatus(422)
            ->assertJsonValidationErrors('version_id');

        $this->assertSame('published', $version->fresh()->status);
    }

    public function test_an_archived_version_cannot_be_opened_by_a_customer(): void
    {
        $this->actingAsAdmin();
        $manual = $this->publishedManual();
        $token = $this->customerSessionFor($manual);

        $this->postJson('/api/admin/manuals/'.$manual->id.'/versions', [
            'version' => '4.0',
            'status' => 'published',
            'pdf' => $this->fakePdf(),
        ])->assertCreated();

        $archived = $manual->versions()->where('version', '!=', '4.0')->firstOrFail();
        $this->postJson('/api/admin/manuals/'.$manual->id.'/versions/'.$archived->id.'/archive')->assertOk();

        $this->withCredentials()->withUnencryptedCookie('customer_session', $token)
            ->getJson('/api/customer/manuals/'.$manual->id.'?version_id='.$archived->id)
            ->assertNotFound();

        $this->withCredentials()->withUnencryptedCookie('customer_session', $token)
            ->getJson('/api/customer/manuals/'.$manual->id)
            ->assertOk()
            ->assertJsonPath('data.version.id', $manual->versions()->where('status', 'published')->value('id'));
    }

    public function test_archiving_a_version_twice_is_rejected(): void
    {
        $this->actingAsAdmin();
        $manual = $this->publishedManual();

        $this->postJson('/api/admin/manuals/'.$manual->id.'/versions', [
            'version' => '4.0',
            'status' => 'published',
            'pdf' => $this->fakePdf(),
        ])->assertCreated();

        $older = $manual->versions()->where('version', '!=', '4.0')->firstOrFail();
        $this->postJson('/api/admin/manuals/'.$manual->id.'/versions/'.$older->id.'/archive')->assertOk();

        $this->postJson('/api/admin/manuals/'.$manual->id.'/versions/'.$older->id.'/archive')
            ->assertStatus(422)
            ->assertJsonValidationErrors('version_id');
    }

    public function test_a_version_from_another_manual_cannot_be_archived(): void
    {
        $this->actingAsAdmin();
        $manual = $this->publishedManual();
        $other = Manual::query()->whereKeyNot($manual->id)->firstOrFail();
        $foreign = $other->versions()->where('status', 'published')->firstOrFail();

        $this->postJson('/api/admin/manuals/'.$manual->id.'/versions/'.$foreign->id.'/archive')
            ->assertNotFound();

        $this->assertSame('published', $foreign->fresh()->status);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'super_admin'))->firstOrFail();
        $this->assertTrue($admin->hasPermission('manuals.manage'));
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    private function publishedManual(): Manual
    {
        return Manual::query()
            ->where('status', 'published')
            ->whereHas('versions', fn ($query) => $query->where('status', 'published'))
            ->firstOrFail();
    }

    private function createManual(array $overrides = [])
    {
        $manual = $this->publishedManual();
        $payload = array_merge([
            'title' => 'Imported Manual',
            'manual_type_id' => $manual->manual_type_id,
            'vehicle_variant_id' => $manual->vehicle_variant_id,
            'engine_id' => $manual->engine_id,
            'language_id' => $manual->language_id,
            'version' => '1.0',
            'status' => 'published',
            'qr_type_ids' => $manual->qrTypes()->pluck('qr_types.id')->all(),
        ], $overrides);

        $payload['pdf'] = $overrides['pdf'] ?? $this->fakePdf();

        return $this->postJson('/api/admin/manuals', $payload);
    }

    private function fakePdf(string $name = 'manual.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, implode("\n", [
            '%PDF-1.4',
            '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj',
            '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj',
            '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj',
            'trailer<</Root 1 0 R>>',
            '%%EOF',
        ]));
    }

    private function customerSessionFor(Manual $manual): string
    {
        $token = 'manual-versions-'.Str::random(24);
        $qrTypeId = $manual->qrTypes()->value('qr_types.id');
        $qr = QrCode::query()->where('qr_type_id', $qrTypeId)->firstOrFail();
        $customer = Customer::query()->create(['name' => 'Version Tester', 'mobile' => '+919876543299']);

        CustomerSession::query()->create([
            'session_token_hash' => hash('sha256', $token),
            'customer_id' => $customer->id,
            'qr_code_id' => $qr->id,
            'vehicle_variant_id' => $manual->vehicle_variant_id,
            'engine_id' => $manual->engine_id,
            'language_id' => $manual->language_id,
            'expires_at' => now()->addHour(),
        ]);

        return $token;
    }

    private function duplicateConfigurationCount(array $manuals, Manual $reference): int
    {
        $count = 0;
        foreach ($manuals as $manual) {
            if ($manual['vehicle'] === $reference->vehicleVariant?->name
                && $manual['engine'] === $reference->engine?->name
                && $manual['language'] === $reference->language?->name
                && $manual['type'] === $reference->manualType?->name) {
                $count++;
            }
        }

        return $count;
    }
}
