<?php

namespace App\Modules\NutritionEngine\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NutritionAiLibraryService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly NutritionPillService $pills,
    ) {}

    /** @param array<string,mixed> $criteria @return array<string,mixed> */
    public function createBatch(array $criteria): array
    {
        $partnerId = $this->partnerId();
        $count = min(50, max(1, (int) ($criteria['count'] ?? 10)));
        $criteria['count'] = $count;
        $id = 'NAI_'.strtoupper((string) Str::ulid());
        $title = trim((string) ($criteria['title'] ?? 'Nueva colección alimentaria'));
        $prompt = $this->buildPrompt($criteria);

        DB::table('nutrition_ai_batches')->insert([
            'id' => $id,
            'partner_id' => $partnerId,
            'requested_by_user_id' => $this->context->userId,
            'title' => $title,
            'status' => 'PROMPT_READY',
            'provider' => 'MANUAL_JSON',
            'model' => null,
            'requested_count' => $count,
            'criteria' => SqlValue::encode($criteria),
            'prompt_text' => $prompt,
            'raw_response' => null,
            'validation_summary' => null,
            'last_error' => null,
            'processed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->showBatch($id);
    }

    /** @return array<int,array<string,mixed>> */
    public function listBatches(int $limit = 50): array
    {
        return DB::table('nutrition_ai_batches')
            ->where('partner_id', $this->partnerId())
            ->orderByDesc('created_at')
            ->limit(min(100, max(1, $limit)))
            ->get()
            ->map(fn (object $row): array => $this->batchArray($row))
            ->all();
    }

    /** @return array<string,mixed> */
    public function showBatch(string $id): array
    {
        $row = $this->authorizedBatch($id);
        $result = $this->batchArray($row);
        $result['proposals'] = DB::table('nutrition_ai_proposals')
            ->where('batch_id', $id)
            ->where('partner_id', $this->partnerId())
            ->orderBy('created_at')
            ->get()
            ->map(fn (object $proposal): array => $this->proposalArray($proposal))
            ->all();
        return $result;
    }

    /** @return array<string,mixed> */
    public function importResponse(string $id, mixed $response): array
    {
        $batch = $this->authorizedBatch($id);
        $decoded = $this->decodeResponse($response);
        $items = $decoded['pills'] ?? $decoded['proposals'] ?? $decoded;
        if (!is_array($items)) {
            throw new \RuntimeException('La respuesta debe ser un arreglo JSON o contener la propiedad pills.', 422);
        }

        if (DB::table('nutrition_ai_proposals')->where('batch_id', $batch->id)->where('status', 'APPROVED')->exists()) {
            throw new \RuntimeException('El lote ya contiene propuestas aprobadas. Crea un lote nuevo para conservar la auditoría.', 409);
        }

        $created = 0;
        $valid = 0;
        $invalid = 0;
        DB::transaction(function () use ($batch, $items, $response, &$created, &$valid, &$invalid): void {
            DB::table('nutrition_ai_proposals')
                ->where('batch_id', $batch->id)
                ->whereIn('status', ['VALIDATED', 'INVALID', 'DRAFT', 'REJECTED'])
                ->update(['status' => 'SUPERSEDED', 'updated_at' => now()]);
            foreach (array_slice(array_values($items), 0, (int) $batch->requested_count) as $item) {
                if (!is_array($item)) continue;
                $normalized = $this->pills->normalizePayload($item);
                $errors = $this->pills->validatePayload($normalized);
                $status = $errors === [] ? 'VALIDATED' : 'INVALID';
                DB::table('nutrition_ai_proposals')->insert([
                    'id' => 'NAP_'.strtoupper((string) Str::ulid()),
                    'batch_id' => $batch->id,
                    'partner_id' => $batch->partner_id,
                    'status' => $status,
                    'proposed_code' => $normalized['code'] ?? null,
                    'payload' => SqlValue::encode($normalized),
                    'validation_errors' => SqlValue::encode($errors),
                    'pill_id' => null,
                    'reviewed_by_user_id' => null,
                    'reviewed_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created++;
                $errors === [] ? $valid++ : $invalid++;
            }

            DB::table('nutrition_ai_batches')->where('id', $batch->id)->update([
                'status' => $valid > 0 ? 'REVIEW_REQUIRED' : 'VALIDATION_FAILED',
                'raw_response' => is_string($response)
                    ? $response
                    : json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'validation_summary' => SqlValue::encode([
                    'total' => $created,
                    'valid' => $valid,
                    'invalid' => $invalid,
                ]),
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);

        return $this->showBatch($id);
    }

    /** @return array<string,mixed> */
    public function approveProposal(string $batchId, string $proposalId): array
    {
        $this->authorizedBatch($batchId);

        return DB::transaction(function () use ($batchId, $proposalId): array {
            $batch = DB::table('nutrition_ai_batches')
                ->where('id', $batchId)
                ->where('partner_id', $this->partnerId())
                ->lockForUpdate()
                ->first();
            if ($batch === null) throw new \RuntimeException('Lote de generación no encontrado.', 404);

            $proposal = DB::table('nutrition_ai_proposals')
                ->where('id', $proposalId)
                ->where('batch_id', $batchId)
                ->where('partner_id', $this->partnerId())
                ->lockForUpdate()
                ->first();
            if ($proposal === null) throw new \RuntimeException('Propuesta no encontrada.', 404);
            if ((string) $proposal->status === 'APPROVED') {
                return [
                    'proposal' => $this->proposalArray($proposal),
                    'pill' => $this->pills->show((string) $proposal->pill_id),
                ];
            }
            if ((string) $proposal->status !== 'VALIDATED') {
                throw new \RuntimeException('Solo pueden aprobarse propuestas validadas.', 409);
            }
            $errors = SqlValue::json($proposal->validation_errors ?? null);
            if ($errors !== []) throw new \RuntimeException('Corrige la propuesta antes de aprobarla.', 422);

            $payload = SqlValue::json($proposal->payload ?? null);
            $pill = $this->pills->create($payload, 'AI_PROMPT', (string) $batch->id);
            $pill = $this->pills->approve((string) $pill['id']);

            DB::table('nutrition_ai_proposals')->where('id', $proposalId)->update([
                'status' => 'APPROVED',
                'pill_id' => $pill['id'],
                'reviewed_by_user_id' => $this->context->userId,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            $remaining = DB::table('nutrition_ai_proposals')
                ->where('batch_id', $batchId)
                ->whereIn('status', ['VALIDATED', 'DRAFT'])
                ->count();
            if ($remaining === 0) {
                DB::table('nutrition_ai_batches')->where('id', $batchId)->update([
                    'status' => 'COMPLETED',
                    'updated_at' => now(),
                ]);
            }

            $updated = DB::table('nutrition_ai_proposals')->where('id', $proposalId)->first();
            return ['proposal' => $this->proposalArray($updated), 'pill' => $pill];
        }, 3);
    }

    /** @return array<string,mixed> */
    public function rejectProposal(string $batchId, string $proposalId): array
    {
        $this->authorizedBatch($batchId);
        $updated = DB::table('nutrition_ai_proposals')
            ->where('id', $proposalId)
            ->where('batch_id', $batchId)
            ->where('partner_id', $this->partnerId())
            ->where('status', '!=', 'APPROVED')
            ->update([
                'status' => 'REJECTED',
                'reviewed_by_user_id' => $this->context->userId,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
        if ($updated < 1) throw new \RuntimeException('Propuesta no encontrada.', 404);
        return $this->showBatch($batchId);
    }

    /** @param array<string,mixed> $criteria */
    private function buildPrompt(array $criteria): string
    {
        $type = strtoupper(trim((string) ($criteria['meal_type'] ?? 'LUNCH')));
        $count = (int) ($criteria['count'] ?? 10);
        $style = trim((string) ($criteria['style'] ?? 'comida mexicana cotidiana'));
        $budget = strtoupper(trim((string) ($criteria['budget_level'] ?? 'LOW')));
        $minutes = (int) ($criteria['max_prep_minutes'] ?? 30);
        $goals = $this->csv($criteria['compatible_goals'] ?? ['GENERAL_WELLNESS', 'MAINTAIN']);
        $patterns = $this->csv($criteria['dietary_patterns'] ?? ['OMNIVORE']);
        $include = trim((string) ($criteria['include_ingredients'] ?? 'verduras, leguminosas, cereales y fuentes de proteína'));
        $exclude = trim((string) ($criteria['exclude_ingredients'] ?? 'suplementos, bebidas detox y productos milagro'));

        return <<<PROMPT
Actúa como editor de una biblioteca de comidas generales, no como médico. Genera {$count} propuestas de tipo {$type} para un motor alimentario autónomo.

Contexto y restricciones:
- Estilo culinario: {$style}.
- Presupuesto: {$budget}.
- Tiempo máximo de preparación: {$minutes} minutos.
- Objetivos compatibles: {$goals}.
- Patrones alimentarios: {$patterns}.
- Ingredientes a priorizar: {$include}.
- Excluir por completo: {$exclude}.
- No diagnostiques, no prometas curación, no prescribas suplementos, medicamentos, productos detox ni productos milagro.
- Cada propuesta debe ser una comida o colación utilizable por sí misma, con cantidades y preparación.
- No asignes compatibilidad con enfermedades. Las condiciones clínicas se revisan fuera de esta biblioteca.
- Declara todos los alérgenos evidentes usando únicamente: DAIRY, EGG, PEANUT, TREE_NUTS, GLUTEN, FISH, SHELLFISH, SOY.
- Si no cuentas con una estimación fiable de calorías o macronutrientes, usa null. No inventes valores.

Devuelve únicamente JSON válido, sin Markdown, con esta forma exacta:
{
  "pills": [
    {
      "code": "CODIGO_CORTO",
      "meal_type": "{$type}",
      "name": "Nombre de la preparación",
      "emoji": "",
      "description": "Descripción breve",
      "portion_text": "Cantidades de una porción",
      "ingredients": [
        {"name": "ingrediente normalizado", "display": "cantidad e ingrediente"}
      ],
      "preparation": "Pasos breves y concretos",
      "food_groups": ["VEGETABLES", "CEREALS_TUBERS"],
      "allergens": [],
      "tags": ["GENERAL"],
      "compatible_goals": ["GENERAL_WELLNESS", "MAINTAIN"],
      "dietary_patterns": ["OMNIVORE"],
      "excluded_flags": [],
      "budget_level": "{$budget}",
      "prep_minutes": {$minutes},
      "kcal_est": null,
      "protein_g": null,
      "carbs_g": null,
      "fat_g": null,
      "fiber_g": null,
      "sodium_mg": null
    }
  ]
}
PROMPT;
    }

    private function decodeResponse(mixed $response): array
    {
        if (is_array($response)) return $response;
        $text = trim((string) $response);
        if ($text === '') throw new \RuntimeException('Pega una respuesta JSON.', 422);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \RuntimeException('La respuesta no contiene JSON válido.', 422);
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function authorizedBatch(string $id): object
    {
        $row = DB::table('nutrition_ai_batches')
            ->where('id', $id)
            ->where('partner_id', $this->partnerId())
            ->first();
        if ($row === null) throw new \RuntimeException('Lote de generación no encontrado.', 404);
        return $row;
    }

    /** @return array<string,mixed> */
    private function batchArray(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'status' => (string) $row->status,
            'provider' => (string) $row->provider,
            'model' => $row->model,
            'requested_count' => (int) $row->requested_count,
            'criteria' => SqlValue::json($row->criteria ?? null),
            'prompt_text' => (string) $row->prompt_text,
            'validation_summary' => SqlValue::json($row->validation_summary ?? null),
            'processed_at' => $row->processed_at,
            'created_at' => $row->created_at,
        ];
    }

    /** @return array<string,mixed> */
    private function proposalArray(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'batch_id' => (string) $row->batch_id,
            'status' => (string) $row->status,
            'proposed_code' => $row->proposed_code,
            'payload' => SqlValue::json($row->payload ?? null),
            'validation_errors' => SqlValue::json($row->validation_errors ?? null),
            'pill_id' => $row->pill_id,
            'reviewed_by_user_id' => $row->reviewed_by_user_id,
            'reviewed_at' => $row->reviewed_at,
        ];
    }

    private function csv(mixed $value): string
    {
        if (is_string($value)) return $value;
        if (!is_array($value)) return '';
        return implode(', ', array_map('strval', $value));
    }

    private function partnerId(): string
    {
        return $this->context->partnerId
            ?? throw new \RuntimeException('El usuario no pertenece a un socio.', 403);
    }
}
