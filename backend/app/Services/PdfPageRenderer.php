<?php

namespace App\Services;

use App\Models\ManualVersion;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class PdfPageRenderer
{
    public function available(): bool
    {
        return extension_loaded('imagick');
    }

    public function render(ManualVersion $version, int $page, string $watermark): Response
    {
        if (! $this->available()) {
            if (config('portal.pdf_renderer_required')) {
                abort(503, 'The PDF renderer is not available.');
            }

            return response($this->placeholder($version, $page, $watermark), 200, [
                'Content-Type' => 'image/svg+xml; charset=UTF-8',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $disk = Storage::disk($version->file_disk);
        $path = $version->file_path;
        $temporaryPath = tempnam(sys_get_temp_dir(), 'manual-page-');

        try {
            $image = new \Imagick();
            $image->readImage($disk->path($path));
            if ($page < 1 || $page > $image->getNumberImages()) {
                abort(404, 'Page not found.');
            }
            $image->setIteratorIndex($page - 1);
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(82);
            $contents = $image->getImageBlob();
            $image->clear();
            $image->destroy();
        } finally {
            if ($temporaryPath && file_exists($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }

        return response($contents, 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'X-Manual-Watermark' => substr($watermark, 0, 120),
        ]);
    }

    private function placeholder(ManualVersion $version, int $page, string $watermark): string
    {
        $title = htmlspecialchars($version->manual?->title ?? 'Vehicle manual', ENT_QUOTES, 'UTF-8');
        $mark = htmlspecialchars($watermark, ENT_QUOTES, 'UTF-8');
        $versionLabel = htmlspecialchars($version->version, ENT_QUOTES, 'UTF-8');

        return '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="1600" viewBox="0 0 1200 1600"><rect width="1200" height="1600" fill="#f8fafc"/><rect x="60" y="60" width="1080" height="1480" rx="28" fill="#fff" stroke="#cbd5e1" stroke-width="4"/><text x="600" y="300" text-anchor="middle" font-family="Arial,sans-serif" font-size="34" font-weight="700" fill="#0f172a">BULL VEHICLE MANUAL</text><text x="600" y="380" text-anchor="middle" font-family="Arial,sans-serif" font-size="26" fill="#334155">'.$title.'</text><text x="600" y="450" text-anchor="middle" font-family="Arial,sans-serif" font-size="24" fill="#64748b">Version '.$versionLabel.' · Page '.(int) $page.'</text><g transform="rotate(-28 600 850)" opacity="0.16"><text x="600" y="850" text-anchor="middle" font-family="Arial,sans-serif" font-size="30" fill="#0f172a">'.$mark.'</text><text x="600" y="900" text-anchor="middle" font-family="Arial,sans-serif" font-size="30" fill="#0f172a">'.$mark.'</text><text x="600" y="950" text-anchor="middle" font-family="Arial,sans-serif" font-size="30" fill="#0f172a">'.$mark.'</text></g><text x="600" y="1430" text-anchor="middle" font-family="Arial,sans-serif" font-size="22" fill="#94a3b8">Configure Ghostscript and Imagick for production PDF rendering.</text></svg>';
    }
}
