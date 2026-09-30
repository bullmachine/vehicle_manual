<?php

namespace Database\Seeders;

use App\Models\Engine;
use App\Models\Language;
use App\Models\Manual;
use App\Models\ManualType;
use App\Models\Permission;
use App\Models\QrCode;
use App\Models\QrType;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\VehicleEngineMapping;
use App\Models\VehicleVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = collect([
            'dashboard.view' => 'View dashboard',
            'masters.manage' => 'Manage master data',
            'manuals.manage' => 'Manage manuals',
            'qr.manage' => 'Manage QR codes',
            'reports.view' => 'View reports',
            'enquiries.view' => 'View support requests',
            'enquiries.manage' => 'Manage support requests',
            'settings.manage' => 'Manage settings',
            'audit.view' => 'View audit logs',
            'users.manage' => 'Manage admin users',
        ])->map(fn ($name, $slug) => Permission::updateOrCreate(['slug' => $slug], ['name' => $name]))->values();

        $superAdmin = Role::updateOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin', 'description' => 'Full system access']);
        $adminRole = Role::updateOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'description' => 'Masters, manuals, QR codes and reports']);
        $viewerRole = Role::updateOrCreate(['slug' => 'viewer'], ['name' => 'Viewer', 'description' => 'Dashboard and reports only']);
        $superAdmin->permissions()->sync($permissions->pluck('id'));
        $adminRole->permissions()->sync($permissions->whereIn('slug', ['dashboard.view', 'masters.manage', 'manuals.manage', 'qr.manage', 'reports.view', 'enquiries.view', 'enquiries.manage'])->pluck('id'));
        $viewerRole->permissions()->sync($permissions->whereIn('slug', ['dashboard.view', 'reports.view', 'enquiries.view'])->pluck('id'));

        $admin = User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.com')],
            [
                'name' => env('ADMIN_NAME', 'BULL Administrator'),
                'username' => env('ADMIN_USERNAME', 'admin'),
                'password' => env('ADMIN_PASSWORD', 'ChangeMe123!'),
                'role_id' => $superAdmin->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $vehicles = collect([
            ['code' => 'TRACTOR-45', 'name' => 'Tractor 45 HP', 'display_order' => 1],
            ['code' => 'TRACTOR-50', 'name' => 'Tractor 50 HP', 'display_order' => 2],
            ['code' => 'TRACTOR-55', 'name' => 'Tractor 55 HP', 'display_order' => 3],
            ['code' => 'TRACTOR-60', 'name' => 'Tractor 60 HP', 'display_order' => 4],
            ['code' => 'BACKHOE', 'name' => 'Backhoe Loader', 'display_order' => 5],
            ['code' => 'MINI', 'name' => 'Mini Tractor', 'display_order' => 6],
        ])->mapWithKeys(fn ($data) => [$data['code'] => VehicleVariant::updateOrCreate(['code' => $data['code']], $data + ['status' => 'active'])]);
        $engines = collect([
            ['code' => 'DIESEL-A', 'name' => 'Diesel A'],
            ['code' => 'DIESEL-B', 'name' => 'Diesel B'],
            ['code' => 'DIESEL-C', 'name' => 'Diesel C'],
            ['code' => 'PETROL-A', 'name' => 'Petrol A'],
            ['code' => 'ELECTRIC', 'name' => 'Electric'],
        ])->mapWithKeys(fn ($data) => [$data['code'] => Engine::updateOrCreate(['code' => $data['code']], $data + ['status' => 'active'])]);
        foreach ($engines as $engine) {
            VehicleEngineMapping::updateOrCreate(['vehicle_variant_id' => $vehicles['TRACTOR-50']->id, 'engine_id' => $engine->id]);
        }
        VehicleEngineMapping::updateOrCreate(['vehicle_variant_id' => $vehicles['TRACTOR-60']->id, 'engine_id' => $engines['DIESEL-C']->id]);

        $variantEngineCodes = [
            'TRACTOR-45' => ['DIESEL-A', 'DIESEL-B'],
            'TRACTOR-50' => ['DIESEL-A', 'DIESEL-B', 'DIESEL-C', 'PETROL-A', 'ELECTRIC'],
            'TRACTOR-55' => ['DIESEL-B', 'DIESEL-C'],
            'TRACTOR-60' => ['DIESEL-C', 'PETROL-A'],
            'BACKHOE' => ['DIESEL-A', 'DIESEL-C'],
            'MINI' => ['PETROL-A', 'ELECTRIC'],
        ];

        foreach ($variantEngineCodes as $variantCode => $engineCodes) {
            foreach ($engineCodes as $engineCode) {
                VehicleEngineMapping::updateOrCreate([
                    'vehicle_variant_id' => $vehicles[$variantCode]->id,
                    'engine_id' => $engines[$engineCode]->id,
                ]);
            }
        }

        $languages = collect([
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'display_order' => 1],
            ['code' => 'ta', 'name' => 'Tamil', 'native_name' => 'தமிழ்', 'display_order' => 2],
            ['code' => 'hi', 'name' => 'Hindi', 'native_name' => 'हिन्दी', 'display_order' => 3],
            ['code' => 'te', 'name' => 'Telugu', 'native_name' => 'తెలుగు', 'display_order' => 4],
            ['code' => 'kn', 'name' => 'Kannada', 'native_name' => 'ಕನ್ನಡ', 'display_order' => 5],
        ])->mapWithKeys(fn ($data) => [$data['code'] => Language::updateOrCreate(['code' => $data['code']], $data + ['status' => 'active'])]);

        $manualType = ManualType::updateOrCreate(['code' => 'operator'], ['name' => 'Operator Manual', 'status' => 'active', 'display_order' => 1]);
        ManualType::updateOrCreate(['code' => 'service'], ['name' => 'Service Manual', 'status' => 'active', 'display_order' => 2]);
        ManualType::updateOrCreate(['code' => 'maintenance'], ['name' => 'Maintenance Manual', 'status' => 'active', 'display_order' => 3]);
        $qrTypes = collect([
            ['code' => 'domestic', 'name' => 'Domestic', 'display_order' => 1],
            ['code' => 'export', 'name' => 'Export', 'display_order' => 2],
            ['code' => 'export-non-bull', 'name' => 'Export (Non-BULL)', 'display_order' => 3],
        ])->mapWithKeys(fn ($data) => [$data['code'] => QrType::updateOrCreate(['code' => $data['code']], $data + ['status' => 'active'])]);

        $allQrTypeIds = $qrTypes->pluck('id')->all();

        foreach ($vehicles as $vehicle) {
            $vehicle->qrTypes()->sync($allQrTypeIds);
        }

        $pdf = $this->samplePdf('BULL Tractor 50 HP Operator Manual');
        $englishPath = 'manuals/sample-tractor50-en-v3.pdf';
        $tamilPath = 'manuals/sample-tractor50-ta-v3.pdf';
        Storage::disk('local')->put($englishPath, $pdf);
        Storage::disk('local')->put($tamilPath, $pdf);

        $primaryEngine = [
            'TRACTOR-45' => 'DIESEL-A',
            'TRACTOR-50' => 'DIESEL-A',
            'TRACTOR-55' => 'DIESEL-B',
            'TRACTOR-60' => 'DIESEL-C',
            'BACKHOE' => 'DIESEL-A',
            'MINI' => 'PETROL-A',
        ];

        $manuals = [];
        foreach ($variantEngineCodes as $variantCode => $engineCodes) {
            foreach (['en', 'ta'] as $languageCode) {
                $manuals[] = [
                    'language' => $languages[$languageCode],
                    'path' => $languageCode === 'en' ? $englishPath : $tamilPath,
                    'title' => $vehicles[$variantCode]->name.' Operator Manual',
                    'variant' => $variantCode,
                    'engine' => $primaryEngine[$variantCode] ?? $engineCodes[0],
                ];
            }
        }

        foreach ($manuals as $item) {
            $manual = Manual::updateOrCreate([
                'title' => $item['title'],
                'manual_type_id' => $manualType->id,
                'vehicle_variant_id' => $vehicles[$item['variant']]->id,
                'engine_id' => $engines[$item['engine']]->id,
                'language_id' => $item['language']->id,
            ], ['status' => 'published', 'show_old_versions' => true, 'created_by' => $admin->id]);
            $manual->qrTypes()->sync($allQrTypeIds);
            $manual->versions()->updateOrCreate(['version' => '3.0'], [
                'effective_date' => now()->toDateString(),
                'description' => 'Development fixture manual',
                'file_disk' => 'local',
                'file_path' => $item['path'],
                'original_name' => basename($item['path']),
                'mime_type' => 'application/pdf',
                'file_size' => strlen($pdf),
                'checksum' => hash('sha256', $pdf),
                'page_count' => 1,
                'status' => 'published',
                'published_at' => now(),
                'created_by' => $admin->id,
            ]);
        }

        $token = Str::random(64);
        QrCode::updateOrCreate(['reference' => 'QR-'.now()->format('Y').'-000001'], [
            'token_hash' => hash('sha256', $token),
            'token_ciphertext' => Crypt::encryptString($token),
            'qr_type_id' => $qrTypes['domestic']->id,
            'vehicle_variant_id' => null,
            'engine_id' => null,
            'language_id' => null,
            'label' => 'Demo Domestic access label',
            'vehicle_number' => 'DEMO-50-001',
            'chassis_number' => 'DEMOCHASSIS50',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        foreach ([
            'otp_enabled' => ['1', 'boolean'],
            'otp_length' => ['6', 'integer'],
            'otp_expiry_minutes' => ['5', 'integer'],
            'otp_max_attempts' => ['5', 'integer'],
            'otp_resend_cooldown' => ['30', 'integer'],
            'manual_max_file_size' => [(string) config('portal.manual_max_file_size'), 'integer'],
            'support_contact' => ['support@example.com', 'string'],
        ] as $key => [$value, $type]) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'type' => $type, 'updated_by' => $admin->id]);
        }
    }

    private function samplePdf(string $title): string
    {
        $text = substr($title, 0, 80);
        $stream = "BT /F1 24 Tf 72 720 Td (".str_replace(['(', ')'], '', $text).") Tj ET";
        $objects = [
            "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
            "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n",
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >> endobj\n",
            "4 0 obj << /Length ".strlen($stream)." >> stream\n".$stream."\nendstream endobj\n",
            "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".count($objects)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer << /Size ".count($objects)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
        return $pdf;
    }
}
