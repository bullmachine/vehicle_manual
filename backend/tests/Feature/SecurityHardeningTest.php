<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Machine;
use App\Models\Manual;
use App\Models\Permission;
use App\Models\QrCode;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\TwoFactorService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_user_manager_cannot_grant_or_take_over_super_admin(): void
    {
        $managerRole = Role::query()->create(['name' => 'User manager', 'slug' => 'user-manager']);
        $managerRole->permissions()->sync(Permission::query()->where('slug', 'users.manage')->pluck('id'));
        $manager = $this->user('manager@example.test', $managerRole);
        $superRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $super = User::query()->where('role_id', $superRole->id)->firstOrFail();

        // Promote themselves.
        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/admin/admin-users/'.$manager->id, ['role_id' => $superRole->id])
            ->assertForbidden();

        // Create a new super admin.
        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/admin/admin-users', [
                'name' => 'Sneaky', 'email' => 'sneaky@example.test', 'password' => 'StrongPass123!', 'password_confirmation' => 'StrongPass123!', 'role_id' => $superRole->id,
            ])
            ->assertForbidden();

        // Lock out or re-password an existing super admin.
        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/admin/admin-users/'.$super->id, ['is_active' => false])
            ->assertForbidden();

        $this->assertSame($managerRole->id, $manager->fresh()->role_id);
        $this->assertTrue((bool) $super->fresh()->is_active);
    }

    public function test_nobody_can_change_their_own_role_or_deactivate_themselves(): void
    {
        $super = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'super_admin'))->firstOrFail();
        $viewerRole = Role::query()->where('slug', 'viewer')->firstOrFail();

        $this->actingAs($super, 'sanctum')
            ->putJson('/api/admin/admin-users/'.$super->id, ['role_id' => $viewerRole->id])
            ->assertStatus(422);
        $this->actingAs($super, 'sanctum')
            ->putJson('/api/admin/admin-users/'.$super->id, ['is_active' => false])
            ->assertStatus(422);

        // A super admin can still manage other accounts.
        $other = $this->user('other@example.test', $viewerRole);
        $this->actingAs($super, 'sanctum')
            ->putJson('/api/admin/admin-users/'.$other->id, ['role_id' => Role::query()->where('slug', 'admin')->value('id')])
            ->assertOk();
    }

    public function test_passwords_are_never_written_to_the_audit_log(): void
    {
        $super = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'super_admin'))->firstOrFail();

        $created = $this->actingAs($super, 'sanctum')
            ->postJson('/api/admin/admin-users', [
                'name' => 'New Admin', 'email' => 'new-admin@example.test', 'password' => 'VerySecret123!', 'password_confirmation' => 'VerySecret123!', 'role_id' => Role::query()->where('slug', 'admin')->value('id'),
            ])
            ->assertCreated();

        $this->actingAs($super, 'sanctum')
            ->putJson('/api/admin/admin-users/'.$created->json('data.id'), ['password' => 'AnotherSecret123!', 'password_confirmation' => 'AnotherSecret123!'])
            ->assertOk();

        $logs = AuditLog::query()->where('module', 'admin_users')->get()->map(fn ($log) => json_encode([$log->old_values, $log->new_values]))->implode(' ');
        $this->assertStringNotContainsString('VerySecret123!', $logs);
        $this->assertStringNotContainsString('AnotherSecret123!', $logs);
        $this->assertStringNotContainsString('$2y$', $logs);
        $this->assertStringContainsString('changed', $logs);
    }

    public function test_wrong_two_factor_codes_count_towards_the_login_lockout(): void
    {
        $user = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'super_admin'))->firstOrFail();
        $user->forceFill([
            'password' => Hash::make('StrongPass123!'),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ])->save();
        // "000000" is not the current code for this secret.
        $this->assertFalse(app(TwoFactorService::class)->verify($user->fresh(), '000000'));

        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $this->postJson('/api/admin/login', ['login' => $user->email, 'password' => 'StrongPass123!', 'otp' => '000000'])
                ->assertStatus(202)
                ->assertJsonPath('message', 'The two-factor code is incorrect.');
        }

        $this->postJson('/api/admin/login', ['login' => $user->email, 'password' => 'StrongPass123!', 'otp' => '000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');
    }

    public function test_list_endpoints_cap_the_page_size(): void
    {
        $super = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'super_admin'))->firstOrFail();

        foreach (['qr-codes', 'audit-logs', 'support-enquiries', 'reports/qr-scans', 'reports/manual-usage', 'reports/customers', 'ferts', 'manuals'] as $endpoint) {
            $this->actingAs($super, 'sanctum')
                ->getJson("/api/admin/{$endpoint}?per_page=100000")
                ->assertOk()
                ->assertJsonPath('per_page', 100);
        }
    }

    public function test_seeding_in_production_adds_no_demo_data_and_keeps_existing_admin_changes(): void
    {
        Manual::query()->delete();
        Machine::query()->delete();
        QrCode::query()->delete();
        $this->refreshApplicationWithEnvironment('production');
        $this->runSeeder();

        $this->assertSame(0, Machine::query()->count());
        $this->assertSame(0, Manual::query()->count());
        $this->assertSame(0, QrCode::query()->count());
        $this->assertTrue(User::query()->where('email', 'admin@example.com')->exists());

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $admin->forceFill(['password' => Hash::make('ChangedByAdmin123!')])->save();
        Setting::query()->where('key', 'support_contact')->update(['value' => 'help@bull.example']);

        $this->runSeeder();

        $this->assertTrue(Hash::check('ChangedByAdmin123!', $admin->fresh()->password));
        $this->assertSame('help@bull.example', Setting::value('support_contact'));
    }

    private function user(string $email, Role $role): User
    {
        return User::query()->create(['name' => $email, 'email' => $email, 'password' => 'StrongPass123!', 'role_id' => $role->id, 'is_active' => true]);
    }

    /**
     * Run the seeder directly; `db:seed` would stop to ask for confirmation in production.
     */
    private function runSeeder(): void
    {
        $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->run();
    }

    private function refreshApplicationWithEnvironment(string $environment): void
    {
        $this->app['env'] = $environment;
    }
}
