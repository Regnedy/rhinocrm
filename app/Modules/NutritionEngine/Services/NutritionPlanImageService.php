<?php

namespace App\Modules\NutritionEngine\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class NutritionPlanImageService
{
    public function __construct(private readonly NutritionPlanPdfService $pdf) {}

    /** @return array<string,mixed> */
    public function generate(string $planId, string $format = 'png'): array
    {
        $format = strtolower(trim($format));
        if (!in_array($format, ['png', 'jpg', 'jpeg'], true)) $format = 'png';
        if ($format === 'jpeg') $format = 'jpg';

        if (!extension_loaded('gd') || !function_exists('imagepng')) {
            throw new \RuntimeException('La generación de imágenes requiere la extensión PHP GD activa en el servidor.', 503);
        }

        $render = $this->pdf->renderHtml($planId);
        $row = $render['row'];
        $diskName = (string) config('rhino.nutrition_engine.pdf_disk', 'local');
        $directory = trim((string) config('rhino.nutrition_engine.pdf_directory', 'nutrition-plans'), '/');
        $dpi = min(180, max(96, (int) config('rhino.nutrition_engine.image_dpi', 120)));
        $quality = min(95, max(70, (int) config('rhino.nutrition_engine.image_quality', 88)));
        $base = "{$directory}/{$render['layout_version']}/images/{$row->partner_id}/{$row->contact_id}/{$row->id}/{$format}";
        $disk = Storage::disk($diskName);
        $manifestPath = $base.'/manifest.json';
        if ($disk->exists($manifestPath)) {
            $cached = json_decode((string) $disk->get($manifestPath), true);
            if (is_array($cached) && isset($cached['pages']) && is_array($cached['pages'])) {
                $valid = $cached['pages'] !== [];
                foreach ($cached['pages'] as &$page) {
                    if (!is_array($page) || !isset($page['path']) || !$disk->exists((string) $page['path'])) {
                        $valid = false;
                        break;
                    }
                    $page['absolute_path'] = $disk->path((string) $page['path']);
                }
                unset($page);
                if ($valid) return $cached;
            }
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->setPdfBackend('GD');
        $options->setDpi($dpi);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($render['html'], 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $pageCount = max(1, (int) $canvas->get_page_count());
        $pages = [];
        $slug = Str::slug((string) $row->contact_name) ?: 'contacto';

        for ($page = 1; $page <= $pageCount; $page++) {
            $extension = $format === 'jpg' ? 'jpg' : 'png';
            $relative = sprintf('%s/page-%02d.%s', $base, $page, $extension);
            $bytes = $canvas->output([
                'type' => $format,
                'quality' => $quality,
                'page' => $page,
            ]);
            if (!is_string($bytes) || $bytes === '') {
                throw new \RuntimeException("No fue posible renderizar la página {$page} del plan como imagen.");
            }
            $disk->put($relative, $bytes);
            $pages[] = [
                'page' => $page,
                'disk' => $diskName,
                'path' => $relative,
                'absolute_path' => $disk->path($relative),
                'filename' => sprintf('Plan-rapido-RHINO-%s-%02d.%s', $slug, $page, $extension),
                'content_type' => $format === 'jpg' ? 'image/jpeg' : 'image/png',
                'sha256' => hash('sha256', $bytes),
            ];
        }

        $manifest = [
            'plan_id' => (string) $row->id,
            'format' => $format,
            'dpi' => $dpi,
            'page_count' => count($pages),
            'pages' => $pages,
        ];
        $portable = $manifest;
        $portable['pages'] = array_map(static function (array $page): array {
            unset($page['absolute_path']);
            return $page;
        }, $portable['pages']);
        $disk->put($manifestPath, json_encode($portable, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $manifest;
    }

    /** @return array<string,mixed> */
    public function page(string $planId, int $page, string $format = 'png'): array
    {
        $manifest = $this->generate($planId, $format);
        if ($page < 1 || $page > (int) $manifest['page_count']) {
            throw new \RuntimeException('Página de imagen no encontrada.', 404);
        }
        return $manifest['pages'][$page - 1];
    }
}
