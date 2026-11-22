<?php

namespace App\Modules\NutritionEngine\Services;

use App\Modules\Integrations\Whatsapp\Contracts\WhatsappChannelResolverInterface;
use App\Modules\Integrations\Whatsapp\Contracts\WhatsappProviderInterface;
use Dompdf\Dompdf;
use Dompdf\Options;

final class QuickNutritionWhatsappTestService
{
    public function __construct(
        private readonly WhatsappProviderInterface $whatsapp,
        private readonly WhatsappChannelResolverInterface $channels,
    ) {}

    /** @return array<string,mixed> */
    public function send(string $rawPhone, string $format): array
    {
        $phone = preg_replace('/\D+/', '', $rawPhone) ?? '';
        $format = strtolower(trim($format));
        if (strlen($phone) < 11 || strlen($phone) > 15) {
            throw new \RuntimeException('Número de WhatsApp de prueba inválido.', 422);
        }
        if (!in_array($format, ['pdf', 'images', 'both'], true)) {
            throw new \RuntimeException('Formato de prueba inválido.', 422);
        }

        return match ($format) {
            'pdf' => $this->sendPdf($phone),
            'images' => $this->sendImages($phone),
            'both' => $this->sendBoth($phone),
        };
    }


    /** @return array<string,mixed> */
    private function sendBoth(string $phone): array
    {
        $channel = $this->channels->resolve();
        $pdfTemplate = trim((string) ($channel->pdfTemplateName ?? ''));
        $imageTemplate = trim((string) ($channel->imagesTemplateName ?? ''));
        if ($pdfTemplate === '' || $imageTemplate === '') {
            throw new \RuntimeException('Configura las plantillas DOCUMENT e IMAGE en Organización > WhatsApp del socio antes de probar AMBAS.', 409);
        }

        $pdf = $this->sendPdf($phone);
        $images = $this->sendImages($phone);
        $externalIds = array_merge($pdf['external_ids'] ?? [], $images['external_ids'] ?? []);

        return [
            'status' => 'SENT',
            'format' => 'BOTH',
            'phone' => $phone,
            'sent_count' => count($externalIds),
            'external_ids' => $externalIds,
        ];
    }

    /** @return array<string,mixed> */
    private function sendPdf(string $phone): array
    {
        $channel = $this->channels->resolve();
        $template = trim((string) ($channel->pdfTemplateName ?? ''));
        if ($template === '') {
            throw new \RuntimeException('Configura la plantilla PDF (DOCUMENT) en Organización > WhatsApp del socio.', 409);
        }

        $path = tempnam(sys_get_temp_dir(), 'rhino-test-pdf-');
        if ($path === false) {
            throw new \RuntimeException('No se pudo crear el PDF temporal de prueba.', 500);
        }
        $pdfPath = $path.'.pdf';
        @unlink($path);
        try {
            $options = new Options();
            $options->set('defaultFont', 'DejaVu Sans');
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($this->testHtml(), 'UTF-8');
            $dompdf->setPaper('letter', 'portrait');
            $dompdf->render();
            file_put_contents($pdfPath, $dompdf->output());
            $mediaId = $this->whatsapp->uploadDocument($pdfPath, 'RHINO-Plan-Rapido-TEST.pdf', 'application/pdf', $channel->partnerId);
            $externalId = $this->whatsapp->sendDocumentTemplate(
                $phone,
                $template,
                $mediaId,
                'RHINO-Plan-Rapido-TEST.pdf',
                ['1' => 'Prueba RHINO', '2' => 'TEST'],
                $channel->templateLanguage,
                $channel->partnerId,
            );
            return ['status' => 'SENT', 'format' => 'PDF', 'phone' => $phone, 'sent_count' => 1, 'external_ids' => [$externalId]];
        } finally {
            @unlink($pdfPath);
        }
    }

    /** @return array<string,mixed> */
    private function sendImages(string $phone): array
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('PHP GD es requerido para la prueba de imágenes.', 503);
        }
        $channel = $this->channels->resolve();
        $template = trim((string) ($channel->imagesTemplateName ?? ''));
        if ($template === '') {
            throw new \RuntimeException('Configura la plantilla de imágenes (IMAGE) en Organización > WhatsApp del socio.', 409);
        }

        $externalIds = [];
        $paths = [];
        try {
            for ($page = 1; $page <= 2; $page++) {
                $path = sys_get_temp_dir().'/rhino-test-image-'.bin2hex(random_bytes(8)).'.jpg';
                $paths[] = $path;
                $this->renderTestImage($path, $page, 2);
                $mediaId = $this->whatsapp->uploadImage($path, 'RHINO-TEST-'.$page.'.jpg', 'image/jpeg', $channel->partnerId);
                $externalIds[] = $this->whatsapp->sendImageTemplate(
                    $phone,
                    $template,
                    $mediaId,
                    ['1' => 'Prueba RHINO', '2' => (string) $page, '3' => '2'],
                    $channel->templateLanguage,
                    $channel->partnerId,
                );
            }
            return ['status' => 'SENT', 'format' => 'IMAGES', 'phone' => $phone, 'sent_count' => count($externalIds), 'external_ids' => $externalIds];
        } finally {
            foreach ($paths as $path) @unlink($path);
        }
    }

    private function renderTestImage(string $path, int $page, int $total): void
    {
        $image = imagecreatetruecolor(1080, 1350);
        if ($image === false) throw new \RuntimeException('No se pudo crear la imagen TEST.', 500);
        $white = imagecolorallocate($image, 255, 255, 255);
        $green = imagecolorallocate($image, 25, 120, 78);
        $dark = imagecolorallocate($image, 35, 44, 40);
        imagefill($image, 0, 0, $white);
        imagefilledrectangle($image, 0, 0, 1080, 180, $green);
        imagestring($image, 5, 70, 70, 'RHINO CRM - MODO TEST', $white);
        imagestring($image, 5, 70, 260, 'Plan Alimenticio Rapido - datos ficticios', $dark);
        imagestring($image, 5, 70, 330, 'Pagina '.$page.' de '.$total, $dark);
        imagestring($image, 5, 70, 430, 'Esta imagen valida el envio directo por WhatsApp Cloud API.', $dark);
        imagestring($image, 5, 70, 500, 'No se creo prospecto, pesaje ni dieta real en RHINO CRM.', $dark);
        imagejpeg($image, $path, 94);
        imagedestroy($image);
    }

    private function testHtml(): string
    {
        return '<!doctype html><html lang="es"><meta charset="utf-8"><style>body{font-family:DejaVu Sans;padding:40px;color:#26332d}h1{color:#19784e}.box{border:2px solid #19784e;padding:24px;border-radius:12px}</style><body><h1>RHINO CRM · MODO TEST</h1><div class="box"><h2>Plan Alimenticio Rápido</h2><p><strong>DATOS FICTICIOS · NO SE GUARDA EN CRM</strong></p><p>Este PDF existe únicamente para validar que WhatsApp Cloud API entrega el archivo directamente al número indicado.</p></div></body></html>';
    }
}
