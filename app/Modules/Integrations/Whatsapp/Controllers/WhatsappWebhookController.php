<?php

namespace App\Modules\Integrations\Whatsapp\Controllers;

use App\Modules\Integrations\Whatsapp\Services\WhatsappInboundService;
use App\Modules\Integrations\Whatsapp\Services\WhatsappStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class WhatsappWebhookController
{
    public function verify(Request $request)
    {
        if (
            $request->query('hub_mode') === 'subscribe'
            && hash_equals((string) config('services.meta.webhook_verify_token'), (string) $request->query('hub_verify_token'))
        ) {
            return response($request->query('hub_challenge'), 200);
        }
        return response('Forbidden', 403);
    }

    public function handle(
        Request $request,
        WhatsappStatusService $statuses,
        WhatsappInboundService $inbound,
    ): JsonResponse {
        $signature = (string) $request->header('X-Hub-Signature-256');
        $secret = (string) config('services.meta.app_secret');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        if ($signature === '' || $secret === '' || !hash_equals($expected, $signature)) {
            Log::channel('security')->warning('Webhook Meta inválido', ['ip' => $request->ip()]);
            return response()->json(['error' => 'Firma inválida'], 401);
        }

        foreach (($request->all()['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                foreach (($value['messages'] ?? []) as $message) {
                    try {
                        $inbound->handle($value, (array) $message);
                    } catch (\Throwable $exception) {
                        Log::error('No se pudo procesar un WhatsApp entrante.', [
                            'message_id' => $message['id'] ?? null,
                            'error' => mb_substr($exception->getMessage(), 0, 1000),
                        ]);
                    }
                }
                foreach (($value['statuses'] ?? []) as $status) {
                    if (isset($status['id'], $status['status'])) {
                        $statuses->apply(
                            (string) $status['id'],
                            (string) $status['status'],
                            $status['errors'][0]['title'] ?? $status['errors'][0]['message'] ?? null,
                        );
                    }
                }
            }
        }

        return response()->json(['success' => true]);
    }
}
