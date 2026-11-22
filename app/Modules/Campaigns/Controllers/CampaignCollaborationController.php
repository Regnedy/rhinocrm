<?php

namespace App\Modules\Campaigns\Controllers;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Campaigns\Services\CampaignAccessService;
use App\Modules\Campaigns\Services\CampaignCollaborationService;
use App\Modules\Campaigns\Services\CampaignInvitationService;
use App\Modules\Campaigns\Services\CampaignQrJoinService;
use App\Modules\Campaigns\Services\CampaignWhatsappCaptureService;
use App\Modules\NutritionEngine\Services\NutritionPlanPdfService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class CampaignCollaborationController
{
    use ApiResponse;

    public function invitations(CampaignInvitationService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->inbox());
    }

    public function campaignInvitations(string $id, CampaignInvitationService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->forCampaign($id));
    }

    public function invite(string $id, Request $request, CampaignInvitationService $service): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        return $this->respond(fn (): array => $service->invite($id, (string) $data['email']), 'Invitación enviada.', 201);
    }

    public function respondInvitation(string $id, Request $request, CampaignInvitationService $service): JsonResponse
    {
        $data = $request->validate(['response' => ['required', 'in:ACCEPTED,DECLINED']]);
        return $this->respond(fn (): array => $service->respond($id, (string) $data['response']), 'Invitación respondida.');
    }

    public function revokeInvitation(string $id, string $invitationId, CampaignInvitationService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->revoke($id, $invitationId), 'Invitación revocada.');
    }

    public function createJoinQr(string $id, Request $request, CampaignQrJoinService $service): JsonResponse
    {
        $data = $request->validate([
            'ttl_minutes' => ['nullable', 'integer', 'min:15', 'max:1440'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        return $this->respond(
            fn (): array => $service->create($id, (int) ($data['ttl_minutes'] ?? 480), (int) ($data['max_uses'] ?? 100)),
            'QR de Party generado.',
            201,
        );
    }

    public function revokeJoinQr(string $id, CampaignQrJoinService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->revoke($id), 'QR de Party desactivado.');
    }

    public function previewJoinQr(Request $request, CampaignQrJoinService $service): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:200']]);
        return $this->respond(fn (): array => $service->preview((string) $data['token']));
    }

    public function acceptJoinQr(Request $request, CampaignQrJoinService $service): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'personal_device_ids' => ['nullable', 'array', 'max:20'],
            'personal_device_ids.*' => ['string', 'max:80'],
        ]);
        return $this->respond(fn (): array => $service->accept((string) $data['token'], (array) ($data['personal_device_ids'] ?? [])), 'Te uniste al Party.');
    }


    public function collaborators(string $id, CampaignCollaborationService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->collaborators($id));
    }

    public function markContactStarted(string $id, string $contactId, CampaignCollaborationService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->markContactStarted($id, $contactId), 'Contacto iniciado.');
    }

    public function markResponded(string $id, string $contactId, CampaignCollaborationService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->markResponded($id, $contactId), 'Respuesta registrada.');
    }

    public function updateCommercialStatus(string $id, string $contactId, Request $request, CampaignCollaborationService $service): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:UNEXPLORED,INTERESTED,EVALUATING,NOT_INTERESTED_NOW,PURCHASE_COMPLETED']]);
        return $this->respond(fn (): array => $service->updateCommercialStatus($id, $contactId, (string) $data['status']), 'Estado comercial actualizado.');
    }

    public function updatePurchaseOutcome(string $id, string $contactId, Request $request, CampaignCollaborationService $service): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', 'in:UNKNOWN,NO_PURCHASE'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        return $this->respond(
            fn (): array => $service->updatePurchaseOutcome($id, $contactId, (string) $data['outcome'], $data['notes'] ?? null),
            'Resultado de compra actualizado.',
        );
    }

    public function assignOwner(string $id, string $contactId, Request $request, CampaignCollaborationService $service): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'string', 'max:128']]);
        return $this->respond(fn (): array => $service->assignOwner($id, $contactId, (string) $data['user_id']), 'Responsable actualizado.');
    }

    public function participant(string $id, string $contactId, CampaignCollaborationService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->participantWorkspace($id, $contactId));
    }

    public function history(string $id, string $contactId, CampaignCollaborationService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->history($id, $contactId));
    }

    public function participantMeasurementDashboard(string $id, string $contactId, CampaignCollaborationService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->participantMeasurementDashboard($id, $contactId));
    }

    public function whatsappCapture(
        string $id,
        string $contactId,
        CampaignCollaborationService $service,
        CampaignWhatsappCaptureService $capture,
    ): JsonResponse {
        return $this->respond(function () use ($id, $contactId, $service, $capture): array {
            $service->participantMeasurementDashboard($id, $contactId);
            return $capture->ensure($id, $contactId, true);
        });
    }

    public function confirmWhatsapp(
        string $id,
        string $contactId,
        Request $request,
        CampaignCollaborationService $service,
        CampaignWhatsappCaptureService $capture,
        UserContextDTO $context,
    ): JsonResponse {
        $data = $request->validate(['phone' => ['required', 'string', 'max:40']]);
        return $this->respond(function () use ($id, $contactId, $service, $capture, $context, $data): array {
            $service->participantMeasurementDashboard($id, $contactId);
            return $capture->confirmManually($id, $contactId, (string) $data['phone'], $context->userId);
        }, 'WhatsApp confirmado.');
    }

    public function updateProfile(string $id, string $contactId, Request $request, CampaignCollaborationService $service): JsonResponse
    {
        $data = $request->validate([
            'goal' => ['nullable', 'string', 'max:80'],
            'activity_level' => ['nullable', 'string', 'max:40'],
            'meals_per_day' => ['nullable', 'integer', 'min:3', 'max:5'],
            'dietary_pattern' => ['nullable', 'string', 'max:60'],
            'budget_level' => ['nullable', 'string', 'max:40'],
            'cooking_time_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'preferences' => ['nullable'],
            'allergies' => ['nullable'],
            'health_flags' => ['nullable'],
            'medications' => ['nullable'],
            'schedule_preferences' => ['nullable'],
            'occupation_schedule' => ['nullable'],
            'kitchen_resources' => ['nullable'],
            'food_access_notes' => ['nullable', 'string', 'max:1000'],
            'adherence_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'mobile_wizard_answers' => ['nullable', 'array', 'max:40'],
        ]);
        return $this->respond(fn (): array => $service->updateNutritionProfile($id, $contactId, $data), 'Perfil alimentario actualizado.');
    }

    public function generateDiet(string $id, string $contactId, Request $request, CampaignCollaborationService $service): JsonResponse
    {
        $data = $request->validate([
            'measurement_id' => ['nullable', 'string', 'max:100'],
            'template_id' => ['nullable', 'string', 'max:100'],
        ]);
        $idempotencyKey = trim((string) $request->header('X-Idempotency-Key'));
        return $this->respond(fn (): array => $service->generateDiet(
            $id,
            $contactId,
            isset($data['measurement_id']) ? (string) $data['measurement_id'] : null,
            isset($data['template_id']) ? (string) $data['template_id'] : null,
            $idempotencyKey !== '' ? $idempotencyKey : null,
        ), 'Dieta generada; el autor quedó como responsable del contacto.', 201);
    }

    public function planPdf(
        string $id,
        string $contactId,
        string $planId,
        Request $request,
        CampaignCollaborationService $service,
        CampaignAccessService $access,
    ): BinaryFileResponse {
        try {
            $scope = $service->planAccess($id, $contactId, $planId);
            $file = $access->runInCampaign($scope['campaign'], fn (): array => app(NutritionPlanPdfService::class)->generate($planId));
            $headers = ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff'];
            return $request->boolean('download', false)
                ? response()->download($file['absolute_path'], $file['filename'], $headers)
                : response()->file($file['absolute_path'], array_merge($headers, ['Content-Disposition' => 'inline; filename="'.$file['filename'].'"']));
        } catch (\Throwable $exception) {
            abort(in_array((int) $exception->getCode(), [403, 404, 409, 422], true) ? (int) $exception->getCode() : 500, $exception->getMessage());
        }
    }

    public function sendPlanWhatsapp(string $id, string $contactId, string $planId, Request $request, CampaignCollaborationService $service): JsonResponse
    {
        $data = $request->validate(['format' => ['nullable', 'in:pdf,images,both']]);
        return $this->respond(
            fn (): array => $service->sendPlanWhatsapp($id, $contactId, $planId, (string) ($data['format'] ?? 'pdf')),
            'Plan enviado por WhatsApp.',
        );
    }

    public function products(string $id, CampaignCollaborationService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->products($id));
    }

    public function sale(string $id, string $contactId, Request $request, CampaignCollaborationService $service): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', 'string', 'max:60'],
            'cart' => ['required', 'array', 'min:1', 'max:100'],
            'cart.*.product_id' => ['required', 'string', 'max:64'],
            'cart.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        $idempotencyKey = (string) $request->header('X-Idempotency-Key');
        if ($idempotencyKey === '') return $this->errorResponse('X-Idempotency-Key es obligatorio.', 'IDEMPOTENCY_KEY_REQUIRED', 422);
        return $this->respond(fn (): array => $service->recordSale($id, $contactId, $data, $idempotencyKey), 'Venta registrada.', 201);
    }

    public function closePreview(string $id, CampaignCollaborationService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->closePreview($id));
    }

    public function close(string $id, Request $request, CampaignCollaborationService $service): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:10000'],
            'product_totals' => ['nullable', 'array', 'max:500'],
            'product_totals.*.product_id' => ['required_with:product_totals', 'string', 'max:64'],
            'product_totals.*.confirmed_quantity' => ['required_with:product_totals', 'integer', 'min:0', 'max:1000000'],
        ]);
        return $this->respond(fn (): array => $service->close($id, $data['notes'] ?? null, (array) ($data['product_totals'] ?? [])), 'Evento cerrado y contactos asignados.');
    }

    public function activity(string $id, CampaignCollaborationService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->activity($id));
    }

    private function respond(callable $callback, string $message = 'Success', int $status = 200): JsonResponse
    {
        try {
            return $this->successResponse($callback(), $message, $status);
        } catch (\Throwable $exception) {
            $code = (int) $exception->getCode();
            $statusCode = in_array($code, [403, 404, 409, 422], true) ? $code : 500;
            if ($statusCode === 500) report($exception);
            return $this->errorResponse(
                $statusCode === 500 ? 'No fue posible completar la operación colaborativa.' : $exception->getMessage(),
                'CAMPAIGN_COLLABORATION_FAILED',
                $statusCode,
            );
        }
    }
}
