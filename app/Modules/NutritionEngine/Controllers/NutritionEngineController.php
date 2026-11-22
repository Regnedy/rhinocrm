<?php

namespace App\Modules\NutritionEngine\Controllers;

use App\Modules\NutritionEngine\Services\NutritionAiLibraryService;
use App\Modules\NutritionEngine\Services\NutritionEngineService;
use App\Modules\NutritionEngine\Services\NutritionPillService;
use App\Modules\NutritionEngine\Services\NutritionPlanDeliveryService;
use App\Modules\NutritionEngine\Services\NutritionPlanEngineService;
use App\Modules\NutritionEngine\Services\NutritionPlanImageService;
use App\Modules\NutritionEngine\Services\NutritionPlanPdfService;
use App\Modules\NutritionEngine\Services\QuickNutritionPlanService;
use App\Modules\NutritionEngine\Services\QuickNutritionWhatsappTestService;
use App\Modules\NutritionEngine\Services\NutritionTemplateService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class NutritionEngineController
{
    use ApiResponse;

    public function summary(NutritionEngineService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->summary());
    }

    public function workspace(string $contactId, NutritionEngineService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->contactWorkspace($contactId));
    }

    public function updateProfile(string $contactId, Request $request, NutritionEngineService $service): JsonResponse
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
        ]);
        return $this->respond(fn (): array => $service->updateProfile($contactId, $data), 'Perfil alimentario actualizado.');
    }

    public function generatePlan(Request $request, NutritionPlanEngineService $plans): JsonResponse
    {
        $data = $request->validate([
            'contact_id' => ['required', 'string', 'max:64'],
            'measurement_id' => ['nullable', 'string', 'max:100'],
            'template_id' => ['nullable', 'string', 'max:100'],
        ]);
        return $this->respond(
            fn (): array => $plans->generateForContact(
                (string) $data['contact_id'],
                isset($data['measurement_id']) ? (string) $data['measurement_id'] : null,
                isset($data['template_id']) ? (string) $data['template_id'] : null,
            ),
            'Plan alimentario generado con la biblioteca aprobada.',
            201,
        );
    }


    public function generateQuickPlan(string $measurementId, Request $request, QuickNutritionPlanService $quick): JsonResponse
    {
        $data = $request->validate([
            'whatsapp' => ['nullable', 'string', 'max:40'],
        ]);

        return $this->respond(
            fn (): array => $quick->generateForMeasurement(
                $measurementId,
                isset($data['whatsapp']) ? (string) $data['whatsapp'] : null,
            ),
            'Plan alimenticio rápido generado desde el pesaje.',
            201,
        );
    }

    public function images(string $id, Request $request, NutritionPlanImageService $images): JsonResponse
    {
        $data = $request->validate([
            'format' => ['nullable', 'in:png,jpg,jpeg'],
        ]);
        $format = strtolower((string) ($data['format'] ?? 'jpg'));
        $format = $format === 'jpeg' ? 'jpg' : $format;

        return $this->respond(function () use ($id, $format, $images): array {
            $manifest = $images->generate($id, $format);
            $manifest['pages'] = array_map(
                static fn (array $page): array => [
                    'page' => (int) $page['page'],
                    'filename' => (string) $page['filename'],
                    'sha256' => (string) $page['sha256'],
                    'url' => '/api/v1/nutrition-engine/plans/'.$id.'/images/'.(int) $page['page'].'?format='.$format,
                    'mobile_url' => '/api/v1/mobile/nutrition-plans/'.$id.'/images/'.(int) $page['page'].'?format='.$format,
                ],
                $manifest['pages'],
            );
            unset($manifest['directory']);
            return $manifest;
        }, 'Imágenes del plan alimenticio generadas.');
    }

    public function image(string $id, int $page, Request $request, NutritionPlanImageService $images): BinaryFileResponse
    {
        try {
            $format = strtolower((string) $request->query('format', 'jpg'));
            $format = $format === 'jpeg' ? 'jpg' : $format;
            if (!in_array($format, ['png', 'jpg'], true)) {
                throw new \RuntimeException('Formato de imagen no soportado.', 422);
            }
            $file = $images->page($id, $page, $format);
            $mime = $format === 'png' ? 'image/png' : 'image/jpeg';
            $headers = [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ];

            return $request->boolean('download', false)
                ? response()->download($file['absolute_path'], $file['filename'], $headers)
                : response()->file($file['absolute_path'], array_merge($headers, [
                    'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
                ]));
        } catch (\Throwable $exception) {
            $code = (int) $exception->getCode();
            $statusCode = in_array($code, [403, 404, 409, 422, 503], true) ? $code : 500;
            abort($statusCode, $statusCode === 500 ? 'No fue posible generar la imagen del plan.' : $exception->getMessage());
        }
    }

    public function plans(Request $request, NutritionPlanEngineService $plans): JsonResponse
    {
        $data = $request->validate([
            'contact_id' => ['required', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        return $this->respond(fn (): array => $plans->listPlans((string) $data['contact_id'], (int) ($data['limit'] ?? 30)));
    }

    public function showPlan(string $id, NutritionPlanEngineService $plans): JsonResponse
    {
        return $this->respond(fn (): array => $plans->showPlan($id));
    }

    public function pdf(string $id, Request $request, NutritionPlanPdfService $pdf): BinaryFileResponse
    {
        $file = $pdf->generate($id);
        $headers = [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return $request->boolean('download', false)
            ? response()->download($file['absolute_path'], $file['filename'], $headers)
            : response()->file($file['absolute_path'], array_merge($headers, [
                'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
            ]));
    }

    public function shareInfo(string $id, NutritionPlanDeliveryService $delivery): JsonResponse
    {
        return $this->respond(fn (): array => $delivery->shareInfo($id));
    }

    public function recordShare(string $id, Request $request, NutritionPlanDeliveryService $delivery): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['nullable', 'in:WHATSAPP_APP,ANDROID_SHARE,WEB_SHARE,DOWNLOAD'],
        ]);
        return $this->respond(
            fn (): array => $delivery->recordShareStarted($id, (string) ($data['channel'] ?? 'WHATSAPP_APP')),
            'Compartición registrada.',
        );
    }

    public function sendWhatsapp(string $id, Request $request, NutritionPlanDeliveryService $delivery): JsonResponse
    {
        $data = $request->validate(['format' => ['nullable', 'in:pdf,images,both']]);
        return $this->respond(
            fn (): array => $delivery->sendDirect($id, (string) ($data['format'] ?? 'pdf')),
            'Plan enviado por WhatsApp.',
        );
    }

    public function sendWhatsappMobile(string $id, Request $request, NutritionPlanDeliveryService $delivery): JsonResponse
    {
        $data = $request->validate([
            'format' => ['required', 'in:pdf,images,both'],
            'whatsapp' => ['required', 'string', 'max:40'],
            'consent' => ['required', 'accepted'],
            'consent_text' => ['nullable', 'string', 'max:1000'],
        ]);
        return $this->respond(
            fn (): array => $delivery->sendDirect(
                $id,
                (string) $data['format'],
                (string) $data['whatsapp'],
                true,
                (string) ($data['consent_text'] ?? 'Autorizo recibir mi Plan Alimenticio Rápido RHINO por WhatsApp.'),
            ),
            'Plan enviado directamente al WhatsApp del prospecto.',
        );
    }

    public function testWhatsappDelivery(Request $request, QuickNutritionWhatsappTestService $test): JsonResponse
    {
        $data = $request->validate([
            'format' => ['required', 'in:pdf,images,both'],
            'whatsapp' => ['required', 'string', 'max:40'],
        ]);
        return $this->respond(
            fn (): array => $test->send((string) $data['whatsapp'], (string) $data['format']),
            'Prueba de WhatsApp enviada sin guardar datos en CRM.',
        );
    }

    public function markDelivery(string $id, Request $request, NutritionPlanEngineService $plans): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:NOT_SENT,SENT,DELIVERED,READ']]);
        return $this->respond(fn (): array => $plans->markDelivery($id, (string) $data['status']), 'Estado de entrega actualizado.');
    }

    public function feedback(string $id, Request $request, NutritionEngineService $service): JsonResponse
    {
        $data = $request->validate([
            'adherence_rating' => ['nullable', 'integer', 'min:0', 'max:100'],
            'favorite_pill_ids' => ['nullable', 'array', 'max:100'],
            'favorite_pill_ids.*' => ['string', 'max:100'],
            'rejected_pill_ids' => ['nullable', 'array', 'max:100'],
            'rejected_pill_ids.*' => ['string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        return $this->respond(fn (): array => $service->feedback($id, $data), 'Seguimiento del plan registrado.');
    }

    public function templates(Request $request, NutritionTemplateService $templates): JsonResponse
    {
        return $this->respond(fn (): array => $templates->list($request->only(['status', 'search', 'limit'])));
    }

    public function storeTemplate(Request $request, NutritionTemplateService $templates): JsonResponse
    {
        $data = $request->validate($this->templateRules());
        return $this->respond(fn (): array => $templates->create($data), 'Plantilla creada como borrador.', 201);
    }

    public function showTemplate(string $id, NutritionTemplateService $templates): JsonResponse
    {
        return $this->respond(fn (): array => $templates->show($id));
    }

    public function updateTemplate(string $id, Request $request, NutritionTemplateService $templates): JsonResponse
    {
        $data = $request->validate($this->templateRules(true));
        return $this->respond(fn (): array => $templates->update($id, $data), 'Plantilla actualizada; requiere activación.');
    }

    public function activateTemplate(string $id, NutritionTemplateService $templates): JsonResponse
    {
        return $this->respond(fn (): array => $templates->activate($id), 'Plantilla activada.');
    }

    public function archiveTemplate(string $id, NutritionTemplateService $templates): JsonResponse
    {
        return $this->respond(fn (): array => $templates->archive($id), 'Plantilla archivada.');
    }

    public function duplicateTemplate(string $id, NutritionTemplateService $templates): JsonResponse
    {
        return $this->respond(fn (): array => $templates->duplicate($id), 'Plantilla duplicada como borrador.', 201);
    }

    public function deleteTemplate(string $id, NutritionTemplateService $templates): JsonResponse
    {
        return $this->respond(fn (): array => $templates->delete($id), 'Plantilla retirada de la biblioteca.');
    }

    public function pills(Request $request, NutritionPillService $pills): JsonResponse
    {
        return $this->respond(fn (): array => $pills->list($request->only([
            'meal_type', 'status', 'source', 'search', 'limit',
        ])));
    }

    public function storePill(Request $request, NutritionPillService $pills): JsonResponse
    {
        $data = $request->validate($this->pillRules());
        return $this->respond(fn (): array => $pills->create($data), 'Píldora creada como borrador.', 201);
    }

    public function showPill(string $id, NutritionPillService $pills): JsonResponse
    {
        return $this->respond(fn (): array => $pills->show($id));
    }

    public function updatePill(string $id, Request $request, NutritionPillService $pills): JsonResponse
    {
        $data = $request->validate($this->pillRules(true));
        return $this->respond(fn (): array => $pills->update($id, $data), 'Píldora actualizada; requiere aprobación.');
    }

    public function approvePill(string $id, NutritionPillService $pills): JsonResponse
    {
        return $this->respond(fn (): array => $pills->approve($id), 'Píldora aprobada.');
    }

    public function archivePill(string $id, NutritionPillService $pills): JsonResponse
    {
        return $this->respond(fn (): array => $pills->archive($id), 'Comida archivada.');
    }

    public function duplicatePill(string $id, NutritionPillService $pills): JsonResponse
    {
        return $this->respond(fn (): array => $pills->duplicate($id), 'Comida duplicada como borrador.', 201);
    }

    public function deletePill(string $id, NutritionPillService $pills): JsonResponse
    {
        return $this->respond(fn (): array => $pills->delete($id), 'Comida retirada de la biblioteca.');
    }

    public function aiBatches(Request $request, NutritionAiLibraryService $ai): JsonResponse
    {
        return $this->respond(fn (): array => $ai->listBatches((int) $request->query('limit', 50)));
    }

    public function createAiBatch(Request $request, NutritionAiLibraryService $ai): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'meal_type' => ['required', 'in:BREAKFAST,SNACK,LUNCH,DINNER,BEVERAGE,COMPONENT'],
            'count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'style' => ['nullable', 'string', 'max:500'],
            'budget_level' => ['nullable', 'in:LOW,MEDIUM,HIGH'],
            'max_prep_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'compatible_goals' => ['nullable', 'array', 'max:10'],
            'compatible_goals.*' => ['string', 'max:40'],
            'dietary_patterns' => ['nullable', 'array', 'max:10'],
            'dietary_patterns.*' => ['string', 'max:40'],
            'include_ingredients' => ['nullable', 'string', 'max:2000'],
            'exclude_ingredients' => ['nullable', 'string', 'max:2000'],
        ]);
        return $this->respond(fn (): array => $ai->createBatch($data), 'Prompt estructurado creado.', 201);
    }

    public function showAiBatch(string $id, NutritionAiLibraryService $ai): JsonResponse
    {
        return $this->respond(fn (): array => $ai->showBatch($id));
    }

    public function importAiResponse(string $id, Request $request, NutritionAiLibraryService $ai): JsonResponse
    {
        $data = $request->validate(['response' => ['required']]);
        return $this->respond(fn (): array => $ai->importResponse($id, $data['response']), 'Respuesta validada y convertida en propuestas.');
    }

    public function approveAiProposal(string $id, string $proposalId, NutritionAiLibraryService $ai): JsonResponse
    {
        return $this->respond(fn (): array => $ai->approveProposal($id, $proposalId), 'Propuesta incorporada a la biblioteca.');
    }

    public function rejectAiProposal(string $id, string $proposalId, NutritionAiLibraryService $ai): JsonResponse
    {
        return $this->respond(fn (): array => $ai->rejectProposal($id, $proposalId), 'Propuesta rechazada.');
    }

    /** @return array<string,array<int,mixed>> */
    private function templateRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return [
            'code' => ['nullable', 'string', 'max:80'],
            'name' => [$required, 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:4000'],
            'compatible_goals' => ['nullable', 'array', 'max:10'],
            'compatible_goals.*' => ['string', 'max:40'],
            'dietary_patterns' => ['nullable', 'array', 'max:10'],
            'dietary_patterns.*' => ['string', 'max:40'],
            'rules' => ['nullable', 'array'],
            'document' => ['nullable', 'array'],
        ];
    }

    /** @return array<string,array<int,mixed>> */
    private function pillRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return [
            'code' => ['nullable', 'string', 'max:80'],
            'meal_type' => [$required, 'in:BREAKFAST,SNACK,LUNCH,DINNER,BEVERAGE,COMPONENT'],
            'name' => [$required, 'string', 'max:180'],
            'emoji' => ['nullable', 'string', 'max:16'],
            'description' => ['nullable', 'string', 'max:4000'],
            'portion_text' => [$required, 'string', 'max:500'],
            'ingredients' => [$required, 'array', 'min:1', 'max:80'],
            'ingredients.*.name' => ['nullable', 'string', 'max:180'],
            'ingredients.*.display' => ['required', 'string', 'max:300'],
            'preparation' => [$required, 'string', 'max:6000'],
            'food_groups' => ['nullable', 'array', 'max:20'],
            'food_groups.*' => ['string', 'max:60'],
            'allergens' => ['nullable', 'array', 'max:20'],
            'allergens.*' => ['string', 'max:60'],
            'tags' => ['nullable', 'array', 'max:30'],
            'tags.*' => ['string', 'max:60'],
            'compatible_goals' => ['nullable', 'array', 'max:20'],
            'compatible_goals.*' => ['string', 'max:60'],
            'dietary_patterns' => ['nullable', 'array', 'max:20'],
            'dietary_patterns.*' => ['string', 'max:60'],
            'excluded_flags' => ['nullable', 'array', 'max:30'],
            'excluded_flags.*' => ['string', 'max:100'],
            'budget_level' => ['nullable', 'in:LOW,MEDIUM,HIGH'],
            'prep_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'kcal_est' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'protein_g' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'carbs_g' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'fat_g' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'fiber_g' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'sodium_mg' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    private function respond(callable $callback, string $message = 'Success', int $status = 200): JsonResponse
    {
        try {
            return $this->successResponse($callback(), $message, $status);
        } catch (\Throwable $exception) {
            $code = (int) $exception->getCode();
            $statusCode = in_array($code, [403, 404, 409, 422, 503], true) ? $code : 500;
            if ($statusCode === 500) report($exception);
            return $this->errorResponse(
                $statusCode === 500 ? 'No fue posible completar la operación nutricional.' : $exception->getMessage(),
                $statusCode === 500 ? 'NUTRITION_ENGINE_ERROR' : 'REQUEST_FAILED',
                $statusCode,
            );
        }
    }
}
