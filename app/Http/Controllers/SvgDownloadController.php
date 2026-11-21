<?php

namespace App\Http\Controllers;

use App\Exceptions\PicToSvgException;
use App\Services\Svg\SvgExportService;
use App\Services\Svg\SvgValidationService;
use App\Support\TemporaryFiles\ConversionStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SvgDownloadController extends Controller
{
    public function __invoke(Request $request, string $temporaryId, ConversionStore $store, SvgExportService $export, SvgValidationService $validator): Response
    {
        $dir = $store->authorize($temporaryId, $request->query('token'));
        $request->validate([
            'version' => ['sometimes', 'string', 'regex:/\A[a-f0-9]{12}\z/'],
            'width' => ['sometimes', 'numeric', 'min:0.01', 'max:100000'],
            'unit' => ['required_with:width', 'in:mm,in'],
        ]);

        $version = $request->query('version') ?? ($store->readJson($dir, 'latest.json')['version'] ?? null);
        $file = is_string($version) && preg_match('/\A[a-f0-9]{12}\z/', $version) === 1 ? $dir.'/result-'.$version.'.svg' : null;
        if ($file === null || ! is_file($file)) {
            throw new PicToSvgException('This result is no longer available. Please convert the image again.', 'expired', 404);
        }

        $svg = (string) file_get_contents($file);
        if ($request->filled('width')) {
            $svg = $export->withDimensions($svg, (float) $request->query('width'), (string) $request->query('unit'));
        }
        $validator->validate($svg); // never serve anything that does not pass validation

        $meta = $store->readJson($dir, 'meta.json') ?? [];
        $base = preg_replace('/[^A-Za-z0-9_\-]+/', '-', pathinfo((string) ($meta['name'] ?? 'image'), PATHINFO_FILENAME)) ?: 'image';
        $filename = trim(substr($base, 0, 80), '-') ?: 'image';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'-pictosvg.svg"',
            'Content-Length' => (string) strlen($svg),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
