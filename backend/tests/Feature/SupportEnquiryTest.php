<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSession;
use App\Models\Engine;
use App\Models\Language;
use App\Models\QrCode;
use App\Models\Role;
use App\Models\SupportEnquiry;
use App\Models\User;
use App\Models\VehicleVariant;
use App\Services\PortalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportEnquiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_visitor_can_submit_a_support_enquiry(): void
    {
        $response = $this->postJson('/api/public/support/enquiries', [
            'name' => 'Raj Kumar',
            'mobile' => '98765 43210',
            'email' => 'raj@example.com',
            'subject' => 'Manual not opening',
            'message' => 'The operator manual will not open on my phone at all.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', SupportEnquiry::STATUS_NEW)
            ->assertJsonPath('data.subject', 'Manual not opening');

        $this->assertDatabaseHas('support_enquiries', [
            'name' => 'Raj Kumar',
            'mobile' => '+919876543210',
            'email' => 'raj@example.com',
            'status' => SupportEnquiry::STATUS_NEW,
            'source' => 'portal_footer',
        ]);
    }

    public function test_email_is_optional(): void
    {
        $this->postJson('/api/public/support/enquiries', [
            'name' => 'Anita',
            'mobile' => '9876543210',
            'subject' => 'OTP problem',
            'message' => 'I never received the verification code on my phone.',
        ])->assertCreated();

        $this->assertDatabaseHas('support_enquiries', ['name' => 'Anita', 'email' => null]);
    }

    public function test_an_invalid_email_is_rejected(): void
    {
        $this->postJson('/api/public/support/enquiries', [
            'name' => 'Anita',
            'mobile' => '9876543210',
            'email' => 'not-an-email',
            'subject' => 'OTP problem',
            'message' => 'I never received the verification code on my phone.',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('support_enquiries', 0);
    }

    public function test_required_fields_are_enforced(): void
    {
        $this->postJson('/api/public/support/enquiries', [
            'name' => '',
            'mobile' => '',
            'subject' => '',
            'message' => 'too short',
        ])->assertStatus(422)->assertJsonValidationErrors(['name', 'mobile', 'subject', 'message']);

        $this->assertDatabaseCount('support_enquiries', 0);
    }

    public function test_an_unknown_customer_session_is_rejected(): void
    {
        $this->postJson('/api/public/support/enquiries', [
            'name' => 'Anita',
            'mobile' => '9876543210',
            'subject' => 'OTP problem',
            'message' => 'I never received the verification code on my phone.',
            'customer_session_id' => 9999,
        ])->assertStatus(422)->assertJsonValidationErrors('customer_session_id');
    }

    public function test_a_revoked_session_is_not_attached(): void
    {
        $session = $this->customerSession();
        $session->update(['revoked_at' => now()]);

        $this->postJson('/api/public/support/enquiries', [
            'name' => 'Anita',
            'mobile' => '9876543210',
            'subject' => 'OTP problem',
            'message' => 'I never received the verification code on my phone.',
            'customer_session_id' => $session->id,
        ])->assertCreated();

        $this->assertDatabaseHas('support_enquiries', ['customer_session_id' => null]);
    }

    public function test_the_enquiry_captures_qr_and_session_context(): void
    {
        $session = $this->customerSession();
        $token = app(PortalService::class)->qrToken($session->qrCode);

        $this->postJson('/api/public/support/enquiries', [
            'name' => 'Anita',
            'mobile' => '9876543210',
            'subject' => 'Wrong manual shown',
            'message' => 'I scanned the QR code but the wrong manual appears.',
            'qr_token' => $token,
            'customer_session_id' => $session->id,
        ])->assertCreated();

        $this->assertDatabaseHas('support_enquiries', [
            'customer_session_id' => $session->id,
            'qr_code_id' => $session->qr_code_id,
        ]);
    }

    public function test_the_qr_token_is_never_stored_in_plaintext(): void
    {
        $session = $this->customerSession();
        $token = app(PortalService::class)->qrToken($session->qrCode);

        $this->postJson('/api/public/support/enquiries', [
            'name' => 'Anita',
            'mobile' => '9876543210',
            'subject' => 'Wrong manual shown',
            'message' => 'I scanned the QR code but the wrong manual appears.',
            'qr_token' => $token,
        ])->assertCreated();

        $row = (array) DB::table('support_enquiries')->first();
        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString($token, (string) $value, "Column {$column} leaked the QR token.");
        }
    }

    public function test_an_unrecognised_qr_token_still_creates_the_enquiry(): void
    {
        $this->postJson('/api/public/support/enquiries', [
            'name' => 'Anita',
            'mobile' => '9876543210',
            'subject' => 'Wrong manual shown',
            'message' => 'I scanned a QR code but the wrong manual appears.',
            'qr_token' => 'not-a-real-token',
        ])->assertCreated();

        $this->assertDatabaseHas('support_enquiries', ['name' => 'Anita', 'qr_code_id' => null]);
    }

    public function test_submission_is_rate_limited(): void
    {
        $payload = [
            'name' => 'Anita',
            'mobile' => '9876543210',
            'subject' => 'OTP problem',
            'message' => 'I never received the verification code on my phone.',
        ];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/public/support/enquiries', $payload)->assertCreated();
        }

        $this->postJson('/api/public/support/enquiries', $payload)->assertStatus(429);
        $this->assertDatabaseCount('support_enquiries', 5);
    }

    public function test_admins_must_be_authenticated_to_list_enquiries(): void
    {
        $this->getJson('/api/admin/support-enquiries')->assertUnauthorized();
    }

    public function test_a_viewer_can_list_but_not_manage_enquiries(): void
    {
        $viewer = $this->actingAsRole('viewer');
        $this->assertTrue($viewer->hasPermission('enquiries.view'));
        $this->assertFalse($viewer->hasPermission('enquiries.manage'));

        $enquiry = $this->supportEnquiry();

        $this->getJson('/api/admin/support-enquiries')
            ->assertOk()
            ->assertJsonPath('data.0.id', $enquiry->id);

        $this->putJson('/api/admin/support-enquiries/'.$enquiry->id, ['status' => SupportEnquiry::STATUS_CLOSED])
            ->assertForbidden();
        $this->deleteJson('/api/admin/support-enquiries/'.$enquiry->id)->assertForbidden();
    }

    public function test_the_list_can_be_filtered_and_searched(): void
    {
        $this->actingAsRole('admin');
        SupportEnquiry::query()->delete();

        $open = SupportEnquiry::create($this->attributes(['name' => 'Raj Kumar', 'subject' => 'Manual not opening']));
        SupportEnquiry::create($this->attributes([
            'name' => 'Sunita Rao',
            'subject' => 'Wrong manual shown',
            'status' => SupportEnquiry::STATUS_CLOSED,
        ]));

        $this->getJson('/api/admin/support-enquiries?status=new')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $open->id);

        $this->getJson('/api/admin/support-enquiries?q=Sunita')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Sunita Rao');

        $this->getJson('/api/admin/support-enquiries?status=bogus')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    public function test_an_admin_can_change_the_status_and_it_is_audited(): void
    {
        $admin = $this->actingAsRole('admin');
        $enquiry = $this->supportEnquiry();
        $this->assertNull($enquiry->resolved_at);

        $this->putJson('/api/admin/support-enquiries/'.$enquiry->id, ['status' => SupportEnquiry::STATUS_IN_PROGRESS])
            ->assertOk()
            ->assertJsonPath('data.status', SupportEnquiry::STATUS_IN_PROGRESS);

        $this->assertDatabaseHas('support_enquiries', [
            'id' => $enquiry->id,
            'status' => SupportEnquiry::STATUS_IN_PROGRESS,
        ]);
        $this->assertNull($enquiry->fresh()->resolved_at);
        $this->assertDatabaseHas('audit_logs', ['module' => 'support_enquiries', 'action' => 'updated']);

        $this->putJson('/api/admin/support-enquiries/'.$enquiry->id, ['status' => SupportEnquiry::STATUS_CLOSED])
            ->assertOk()
            ->assertJsonPath('data.status', SupportEnquiry::STATUS_CLOSED);

        $this->assertNotNull($enquiry->fresh()->resolved_at);

        $this->putJson('/api/admin/support-enquiries/'.$enquiry->id, ['status' => SupportEnquiry::STATUS_IN_PROGRESS])
            ->assertOk();

        $this->assertNull($enquiry->fresh()->resolved_at, 'Reopening an enquiry should clear the resolved timestamp.');
    }

    public function test_an_invalid_status_is_rejected(): void
    {
        $this->actingAsRole('admin');
        $enquiry = $this->supportEnquiry();

        $this->putJson('/api/admin/support-enquiries/'.$enquiry->id, ['status' => 'banana'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('support_enquiries', ['id' => $enquiry->id, 'status' => SupportEnquiry::STATUS_NEW]);
    }

    public function test_an_admin_can_delete_an_enquiry_and_it_is_audited(): void
    {
        $this->actingAsRole('admin');
        $enquiry = $this->supportEnquiry();

        $this->deleteJson('/api/admin/support-enquiries/'.$enquiry->id)->assertOk();

        $this->assertDatabaseMissing('support_enquiries', ['id' => $enquiry->id]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'support_enquiries', 'action' => 'deleted']);
    }

    public function test_the_admin_list_never_exposes_ip_or_user_agent(): void
    {
        $this->actingAsRole('admin');
        $enquiry = $this->supportEnquiry();

        $body = $this->getJson('/api/admin/support-enquiries')->assertOk()->content();

        $this->assertStringNotContainsString('user_agent', $body);
        $this->assertStringNotContainsString('127.0.0.1', $body);
        $this->assertNotNull($enquiry->ip_address);
    }

    private function actingAsRole(string $slug): User
    {
        $user = User::query()->where('email', $slug.'@example.com')->first();

        if (! $user) {
            $user = User::create([
                'name' => ucfirst($slug),
                'username' => $slug,
                'email' => $slug.'@example.com',
                'password' => 'Password123!',
                'role_id' => Role::query()->where('slug', $slug)->firstOrFail()->id,
                'is_active' => true,
            ]);
        }

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function customerSession(): CustomerSession
    {
        $qr = QrCode::query()->firstOrFail();
        $customer = Customer::query()->create(['name' => 'Support Tester', 'mobile' => '+919876543288']);

        return CustomerSession::query()->create([
            'session_token_hash' => hash('sha256', 'support-enquiry-'.uniqid()),
            'customer_id' => $customer->id,
            'qr_code_id' => $qr->id,
            'vehicle_variant_id' => $qr->vehicle_variant_id ?? VehicleVariant::query()->firstOrFail()->id,
            'engine_id' => $qr->engine_id ?? Engine::query()->firstOrFail()->id,
            'language_id' => $qr->language_id ?? Language::query()->firstOrFail()->id,
            'expires_at' => now()->addHour(),
        ]);
    }

    private function supportEnquiry(): SupportEnquiry
    {
        return SupportEnquiry::query()->create($this->attributes());
    }

    private function attributes(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Raj Kumar',
            'mobile' => '9876543210',
            'email' => 'raj@example.com',
            'subject' => 'Manual not opening',
            'message' => 'The operator manual will not open on my phone at all.',
            'status' => SupportEnquiry::STATUS_NEW,
            'source' => 'portal_footer',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ];
    }
}
