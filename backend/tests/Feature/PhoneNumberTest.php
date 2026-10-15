<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\QrCode;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\PhoneNumberService;
use App\Services\PortalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function phones(): PhoneNumberService
    {
        return app(PhoneNumberService::class);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function indianFormats(): array
    {
        return [
            'bare' => ['9876543210', '+919876543210'],
            'spaced' => ['98765 43210', '+919876543210'],
            'dashed' => ['91-98765-43210', '+919876543210'],
            'leading zero' => ['09876543210', '+919876543210'],
            'country code without plus' => ['919876543210', '+919876543210'],
            'plus and spaces' => ['+91 98765 43210', '+919876543210'],
            'plus and dashes' => ['+91-98765-43210', '+919876543210'],
            'e164' => ['+919876543210', '+919876543210'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('indianFormats')]
    public function test_every_legacy_indian_input_still_normalises_to_e164(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->phones()->normalize($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function internationalFormats(): array
    {
        return [
            'united states' => ['+14155552671', '+14155552671'],
            'united states with punctuation' => ['+1 (415) 555-2671', '+14155552671'],
            'uae' => ['+971 50 123 4567', '+971501234567'],
            'uae e164' => ['+971501234567', '+971501234567'],
            'united kingdom' => ['+44 7911 123456', '+447911123456'],
            'germany' => ['+49 151 12345678', '+4915112345678'],
            'singapore' => ['+65 8123 4567', '+6581234567'],
            'saudi arabia' => ['+966 50 123 4567', '+966501234567'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('internationalFormats')]
    public function test_international_numbers_normalise_to_e164(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->phones()->normalize($input));
    }

    public function test_a_bare_number_is_read_as_the_configured_default_region(): void
    {
        config(['portal.phone.default_region' => 'AE']);

        $this->assertSame('AE', $this->phones()->defaultRegion());
        $this->assertSame('+971501234567', $this->phones()->normalize('050 123 4567'));
        $this->assertSame('+971501234567', $this->phones()->normalize('0501234567'));

        // An explicit country code always wins over the default region.
        $this->assertSame('+919876543210', $this->phones()->normalize('+91 98765 43210'));
    }

    public function test_a_bare_number_from_another_country_is_rejected_rather_than_guessed(): void
    {
        config(['portal.phone.default_region' => 'AE']);

        // "9876543210" is meaningless as a UAE number, so it must not silently become one.
        $this->expectException(ValidationException::class);
        $this->phones()->normalize('9876543210');
    }

    public function test_an_unknown_default_region_falls_back_to_india(): void
    {
        config(['portal.phone.default_region' => 'ZZ']);

        $this->assertSame('IN', $this->phones()->defaultRegion());
        $this->assertSame('+919876543210', $this->phones()->normalize('9876543210'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedInputs(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'letters' => ['abcdefghij'],
            'too short for india' => ['12345'],
            'too short for the united states' => ['+1415555267'],
            'impossible country code' => ['+999123456789'],
            'nine digit indian number' => ['987654321'],
            'trailing junk' => ['+919876543210abc'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedInputs')]
    public function test_unusable_numbers_are_rejected_with_a_validation_error(string $input): void
    {
        try {
            $this->phones()->normalize($input);
            $this->fail("Expected [{$input}] to be rejected.");
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors()['mobile'] ?? null);
        }
    }

    public function test_the_error_names_the_default_country_rather_than_claiming_india(): void
    {
        config(['portal.phone.default_region' => 'AE']);

        try {
            $this->phones()->normalize('not a phone number');
            $this->fail('Expected the input to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('United Arab Emirates', $exception->errors()['mobile'][0]);
            $this->assertStringContainsString('+971', $exception->errors()['mobile'][0]);
            $this->assertStringNotContainsString('Indian', $exception->errors()['mobile'][0]);
        }
    }

    public function test_the_allowlist_blocks_a_valid_number_from_a_disallowed_country(): void
    {
        config(['portal.phone.allowed_regions' => ['IN', 'AE']]);

        $this->assertSame(['IN', 'AE'], $this->phones()->allowedRegions());
        $this->assertTrue($this->phones()->regionAllowed('IN'));
        $this->assertTrue($this->phones()->regionAllowed('ae'));
        $this->assertFalse($this->phones()->regionAllowed('US'));
        $this->assertSame('+971501234567', $this->phones()->normalize('+971501234567'));

        $this->expectException(ValidationException::class);
        $this->phones()->normalize('+14155552671');
    }

    public function test_the_allowlist_error_names_the_rejected_country(): void
    {
        config(['portal.phone.allowed_regions' => ['IN']]);

        try {
            $this->phones()->normalize('+971 50 123 4567');
            $this->fail('Expected the UAE number to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('United Arab Emirates', $exception->errors()['mobile'][0]);
        }
    }

    public function test_an_empty_allowlist_accepts_every_country(): void
    {
        config(['portal.phone.allowed_regions' => []]);

        $this->assertSame([], $this->phones()->allowedRegions());
        $this->assertCount(245, $this->phones()->countries());
        $this->assertTrue($this->phones()->regionAllowed('KW'));
    }

    public function test_unrecognised_allowlist_entries_are_discarded(): void
    {
        config(['portal.phone.allowed_regions' => [' in ', 'AE', 'ZZ', 'zz', '']]);

        $this->assertSame(['IN', 'AE'], $this->phones()->allowedRegions());
    }

    public function test_the_country_list_is_named_sorted_and_carries_dial_codes(): void
    {
        $countries = $this->phones()->countries();

        $this->assertCount(245, $countries);
        $this->assertSame($countries, collect($countries)->sortBy('name')->values()->all());

        $names = array_column($countries, 'name');
        $this->assertSame($names, array_values(array_unique($names)));

        foreach ($countries as $country) {
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $country['code']);
            $this->assertMatchesRegularExpression('/^\+[1-9]\d{0,3}$/', $country['dial']);
            $this->assertNotSame('', $country['name']);
        }

        $india = collect($countries)->firstWhere('code', 'IN');
        $this->assertSame(['code' => 'IN', 'dial' => '+91', 'name' => 'India'], $india);
    }

    public function test_the_public_country_endpoint_returns_the_allowlist_and_the_default(): void
    {
        config(['portal.phone.default_region' => 'IN', 'portal.phone.allowed_regions' => ['IN', 'AE']]);

        $response = $this->getJson('/api/public/phone-countries');

        $response->assertOk()
            ->assertJsonPath('data.default', 'IN')
            ->assertJsonCount(2, 'data.countries');

        // The list stays sorted by country name, so India precedes United Arab Emirates.
        $this->assertSame(['IN', 'AE'], array_column($response->json('data.countries'), 'code'));
    }

    public function test_the_public_country_endpoint_lists_every_country_by_default(): void
    {
        $response = $this->getJson('/api/public/phone-countries');

        $response->assertOk()->assertJsonPath('data.default', 'IN')->assertJsonCount(245, 'data.countries');
    }

    public function test_the_qr_context_tells_the_portal_which_country_to_preselect(): void
    {
        config(['portal.phone.default_region' => 'AE']);

        $qr = QrCode::query()->firstOrFail();
        $token = app(PortalService::class)->qrToken($qr);

        $this->getJson('/api/public/qr/'.$token)
            ->assertOk()
            ->assertJsonPath('default_phone_region', 'AE');
    }

    public function test_a_customer_session_accepts_an_international_number(): void
    {
        $qr = QrCode::query()->firstOrFail();
        $payload = [
            'qr_token' => app(PortalService::class)->qrToken($qr),
            'machine_serial_no' => 'BS255',
            'name' => 'Layla Hassan',
            'mobile' => '+971 50 123 4567',
            'language_id' => Language::query()->where('code', 'en')->value('id'),
        ];

        $this->postJson('/api/public/customer/session', $payload)
            ->assertOk()
            ->assertJsonPath('data.customer.mobile', '+971501234567')
            ->assertCookie('customer_session');

        $this->assertDatabaseHas('customers', ['name' => 'Layla Hassan', 'mobile' => '+971501234567']);
    }

    public function test_an_invalid_optional_mobile_is_still_rejected(): void
    {
        $this->postJson('/api/public/customer/session', [
            'qr_token' => app(PortalService::class)->qrToken(QrCode::query()->firstOrFail()),
            'machine_serial_no' => 'BS255',
            'name' => 'Bad Number',
            'mobile' => '12',
            'language_id' => Language::query()->where('code', 'en')->value('id'),
        ])->assertStatus(422)->assertJsonValidationErrors('mobile');
    }

    public function test_two_different_countries_do_not_collide_on_the_unique_mobile_constraint(): void
    {
        $this->postJson('/api/public/support/enquiries', [
            'name' => 'Indian Customer',
            'mobile' => '9876543210',
            'subject' => 'Manual question',
            'message' => 'The clutch section will not load for me at all.',
        ])->assertCreated();

        $this->postJson('/api/public/support/enquiries', [
            'name' => 'UAE Customer',
            'mobile' => '+971501234567',
            'subject' => 'Manual question',
            'message' => 'The clutch section will not load for me at all.',
        ])->assertCreated();

        $this->assertDatabaseHas('support_enquiries', ['mobile' => '+919876543210']);
        $this->assertDatabaseHas('support_enquiries', ['mobile' => '+971501234567']);
    }

    public function test_the_contact_form_accepts_an_international_number(): void
    {
        $this->postJson('/api/public/support/enquiries', [
            'name' => 'Dana Whitfield',
            'mobile' => '+1 (415) 555-2671',
            'subject' => 'Where is the warranty section?',
            'message' => 'I cannot find the warranty information anywhere in the manual.',
        ])->assertCreated()->assertJsonPath('data.mobile', '+14155552671');

        $this->assertDatabaseHas('support_enquiries', ['name' => 'Dana Whitfield', 'mobile' => '+14155552671']);
    }

    public function test_the_contact_form_rejects_a_number_that_does_not_belong_to_the_chosen_country(): void
    {
        $this->postJson('/api/public/support/enquiries', [
            'name' => 'Wrong Country',
            'mobile' => '+1415555267',
            'subject' => 'Where is the warranty section?',
            'message' => 'I cannot find the warranty information anywhere in the manual.',
        ])->assertStatus(422)->assertJsonValidationErrors('mobile');

        $this->assertDatabaseCount('support_enquiries', 0);
    }

    public function test_the_admin_list_renders_a_readable_number_without_storing_the_country(): void
    {
        $this->postJson('/api/public/support/enquiries', [
            'name' => 'Layla Hassan',
            'mobile' => '+971 50 123 4567',
            'subject' => 'Manual not opening',
            'message' => 'The operator manual will not open on my phone at all.',
        ])->assertCreated();

        $this->actingAsRole('admin');
        $response = $this->getJson('/api/admin/support-enquiries');

        $response->assertOk()
            ->assertJsonPath('data.0.mobile', '+971501234567')
            ->assertJsonPath('data.0.mobile_display', '+971 50 123 4567')
            ->assertJsonPath('data.0.mobile_region', 'AE');

        $this->assertArrayNotHasKey('country_code', \Schema::getColumnListing('support_enquiries'));
        $this->assertArrayNotHasKey('country', \Schema::getColumnListing('support_enquiries'));
    }

    public function test_the_country_is_derived_on_read_for_a_number_stored_before_the_upgrade(): void
    {
        $this->assertSame('IN', $this->phones()->regionFor('+919876543210'));
        $this->assertSame('+91 98765 43210', $this->phones()->formatForDisplay('+919876543210'));
        $this->assertNull($this->phones()->regionFor(null));
        $this->assertNull($this->phones()->formatForDisplay(null));
    }

    public function test_display_helpers_degrade_gracefully_for_an_unreadable_stored_value(): void
    {
        $this->assertSame('not a number', $this->phones()->formatForDisplay('not a number'));
        $this->assertNull($this->phones()->regionFor('not a number'));
    }

    private function actingAsRole(string $slug): User
    {
        $user = User::query()->where('email', $slug.'@example.com')->first();

        if (! $user) {
            $role = Role::query()->where('slug', $slug)->firstOrFail();
            $user = User::create([
                'name' => ucfirst($slug),
                'email' => $slug.'@example.com',
                'password' => bcrypt('secret1234'),
            ]);
            $user->roles()->sync([$role->id]);
        }

        $this->actingAs($user, 'sanctum');

        return $user;
    }
}
