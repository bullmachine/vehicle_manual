<?php

namespace Database\Seeders;

use App\Models\Fert;
use App\Models\Language;
use App\Models\Manual;
use App\Models\ManualType;
use App\Models\Permission;
use App\Models\QrCode;
use App\Models\QrType;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\MachineSync;
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

        // Created once; re-seeding must never reset the password or role of an existing admin.
        $admin = User::firstOrCreate(
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

        foreach ([
            'manual_max_file_size' => [(string) config('portal.manual_max_file_size'), 'integer'],
            'support_contact' => ['support@example.com', 'string'],
        ] as $key => [$value, $type]) {
            // Keep values an admin has already changed.
            Setting::firstOrCreate(['key' => $key], ['value' => $value, 'type' => $type, 'updated_by' => $admin->id]);
        }

        // Dummy machines, sample manuals and a demo QR code are for development and tests only.
        if (app()->environment(['local', 'testing'])) {
            $this->seedDemoData($admin, $languages, $manualType, $qrTypes['domestic']);
        }
    }

    private function seedDemoData(User $admin, $languages, ManualType $manualType, QrType $qrType): void
    {
        // Load the dummy machines (database/data/machines.json, SAP response format); this also fills the FERT master.
        app(MachineSync::class)->syncFromFile((string) config('portal.machine_data_path'));

        // Sample manuals for the first three FERTs; the rest show "no manuals yet" until an admin maps one.
        $ferts = Fert::query()->whereIn('code', ['8000001', '8000002', '8000003'])->orderBy('code')->get();

        $pdf = $this->samplePdf('BULL Smart Backhoe Loader Operator Manual');
        $paths = ['en' => 'manuals/sample-backhoe-en-v1.pdf', 'ta' => 'manuals/sample-backhoe-ta-v1.pdf'];
        foreach ($paths as $path) {
            Storage::disk('local')->put($path, $pdf);
        }

        foreach ($ferts as $fert) {
            foreach ($paths as $languageCode => $path) {
                $manual = Manual::updateOrCreate([
                    'title' => $fert->name.' Operator Manual',
                    'manual_type_id' => $manualType->id,
                    'language_id' => $languages[$languageCode]->id,
                ], ['status' => 'published', 'show_old_versions' => true, 'created_by' => $admin->id]);
                $manual->ferts()->sync([$fert->id]);
                $manual->versions()->updateOrCreate(['version' => '1.0'], [
                    'effective_date' => now()->toDateString(),
                    'description' => 'Development fixture manual',
                    'file_disk' => 'local',
                    'file_path' => $path,
                    'original_name' => basename($path),
                    'mime_type' => 'application/pdf',
                    'file_size' => strlen($pdf),
                    'checksum' => hash('sha256', $pdf),
                    'page_count' => 1,
                    'status' => 'published',
                    'published_at' => now(),
                    'created_by' => $admin->id,
                ]);
            }
        }

        // Keep the existing token on re-seed so a demo QR link that was already shared keeps working.
        if (! QrCode::query()->exists()) {
            $token = Str::random(64);
            QrCode::create([
                'reference' => 'QR-'.now()->format('Y').'-000001',
                'token_hash' => hash('sha256', $token),
                'token_ciphertext' => Crypt::encryptString($token),
                'qr_type_id' => $qrType->id,
                'label' => 'Demo Domestic access label',
                'status' => 'active',
                'created_by' => $admin->id,
            ]);
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
