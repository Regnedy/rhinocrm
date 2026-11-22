<?php

namespace App\Modules\Campaigns\Controllers;

use App\Modules\Campaigns\Services\CampaignWhatsappCaptureService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class WhatsappCapturePageController
{
    public function show(string $token, CampaignWhatsappCaptureService $service): View
    {
        try {
            return view('public.whatsapp-connect', $service->publicContext($token));
        } catch (\Throwable $exception) {
            abort(in_array((int) $exception->getCode(), [404, 410, 503], true) ? (int) $exception->getCode() : 404, $exception->getMessage());
        }
    }

    public function vcard(string $token, CampaignWhatsappCaptureService $service): Response
    {
        try {
            $content = $service->vcard($token);
            return response($content, 200, [
                'Content-Type' => 'text/vcard; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="RHINO-CRM.vcf"',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (\Throwable $exception) {
            abort(in_array((int) $exception->getCode(), [404, 410, 503], true) ? (int) $exception->getCode() : 404, $exception->getMessage());
        }
    }

    public function whatsapp(string $token, CampaignWhatsappCaptureService $service): RedirectResponse
    {
        try {
            return redirect()->away($service->whatsappRedirect($token));
        } catch (\Throwable $exception) {
            abort(in_array((int) $exception->getCode(), [404, 410, 503], true) ? (int) $exception->getCode() : 404, $exception->getMessage());
        }
    }
}
