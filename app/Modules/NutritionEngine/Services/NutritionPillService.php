<?php

namespace App\Modules\NutritionEngine\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Support\Database\SqlValue;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NutritionPillService
{
    private const MEAL_TYPES = ['BREAKFAST', 'SNACK', 'LUNCH', 'DINNER', 'BEVERAGE', 'COMPONENT'];
    private const FOOD_GROUPS = ['VEGETABLES', 'FRUIT', 'CEREALS_TUBERS', 'LEGUMES', 'ANIMAL_PROTEIN', 'DAIRY', 'HEALTHY_FATS', 'OTHER'];
    private const ALLERGENS = ['DAIRY', 'EGG', 'PEANUT', 'TREE_NUTS', 'GLUTEN', 'FISH', 'SHELLFISH', 'SOY'];
    private const GOALS = ['GENERAL_WELLNESS', 'MAINTAIN', 'REDUCE', 'GAIN'];
    private const DIETARY_PATTERNS = ['OMNIVORE', 'VEGETARIAN', 'VEGAN'];
    private const BUDGET_LEVELS = ['LOW', 'MEDIUM', 'HIGH'];

    public function __construct(private readonly UserContextDTO $context) {}

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function list(array $filters): array
    {
        $partnerId = $this->context->partnerId;
        $limit = min(300, max(1, (int) ($filters['limit'] ?? 100)));
        $query = DB::table('nutrition_pills');

        if ($this->context->isSuperAdmin()) {
            $query->where(function (Builder $scope) use ($partnerId): void {
                $scope->where('scope_key', 'GLOBAL');
                if ($partnerId !== null) {
                    $scope->orWhere('partner_id', $partnerId);
                }
            });
        } else {
            $partnerId = $this->partnerId();
            $query->where(function (Builder $scope) use ($partnerId): void {
                $scope->where('scope_key', 'GLOBAL')
                    ->orWhere('partner_id', $partnerId);
            });
        }

        if ($this->nullable($filters['meal_type'] ?? null) !== null) {
            $query->where('meal_type', strtoupper((string) $filters['meal_type']));
        }
        if ($this->nullable($filters['status'] ?? null) !== null) {
            $query->where('status', strtoupper((string) $filters['status']));
        }
        if ($this->nullable($filters['source'] ?? null) !== null) {
            $query->where('source', strtoupper((string) $filters['source']));
        }
        if ($this->nullable($filters['search'] ?? null) !== null) {
            $search = '%'.trim((string) $filters['search']).'%';
            $query->where(function (Builder $scope) use ($search): void {
                $scope->where('name', 'like', $search)
                    ->orWhere('code', 'like', $search)
                    ->orWhere('portion_text', 'like', $search);
            });
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByRaw("FIELD(status,'APPROVED','DRAFT','ARCHIVED','REJECTED')")
            ->orderBy('meal_type')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => $this->serialize($row))
            ->all();

        return ['items' => $rows, 'count' => count($rows), 'total' => $total, 'limit' => $limit];
    }

    /** @return array<string,mixed> */
    public function show(string $id): array
    {
        return $this->serialize($this->authorized($id));
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function create(array $data, string $source = 'MANUAL', ?string $sourceReference = null): array
    {
        $isGlobal = $this->context->isSuperAdmin();
        $partnerId = $isGlobal ? null : $this->partnerId();
        $scopeKey = $isGlobal ? 'GLOBAL' : 'PARTNER:'.$partnerId;
        $normalized = $this->normalizePayload($data);
        $errors = $this->validatePayload($normalized);
        if ($errors !== []) {
            throw new \RuntimeException(implode(' ', $errors), 422);
        }

        $duplicate = DB::table('nutrition_pills')
            ->where(function (Builder $scope) use ($isGlobal, $partnerId): void {
                if ($isGlobal) {
                    $scope->where('scope_key', 'GLOBAL');
                    return;
                }
                $scope->where('scope_key', 'GLOBAL')->orWhere('partner_id', $partnerId);
            })
            ->whereRaw('LOWER(name) = ?', [$this->lower((string) $normalized['name'])])
            ->where('status', '!=', 'ARCHIVED')
            ->first();
        if ($duplicate !== null) {
            throw new \RuntimeException('Ya existe una píldora con un nombre equivalente.', 409);
        }

        $id = 'NPI_'.strtoupper((string) Str::ulid());
        $code = $this->uniqueCode($scopeKey, (string) ($normalized['code'] ?? $normalized['name']));
        $values = $this->databaseValues($normalized);

        DB::transaction(function () use ($id, $scopeKey, $partnerId, $code, $values, $source, $sourceReference, $normalized): void {
            DB::table('nutrition_pills')->insert(array_merge($values, [
                'id' => $id,
                'scope_key' => $scopeKey,
                'partner_id' => $partnerId,
                'code' => $code,
                'status' => 'DRAFT',
                'source' => strtoupper($source),
                'source_reference' => $sourceReference,
                'current_version' => 1,
                'created_by_user_id' => $this->context->userId,
                'approved_by_user_id' => null,
                'approved_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $this->writeVersion($id, 1, $normalized, strtoupper($source), 'Creación de píldora.');
        }, 3);

        return $this->show($id);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function update(string $id, array $data): array
    {
        $pill = $this->authorized($id, true);
        $current = $this->serialize($pill);
        $merged = array_merge($current, $data);
        $normalized = $this->normalizePayload($merged);
        $errors = $this->validatePayload($normalized);
        if ($errors !== []) {
            throw new \RuntimeException(implode(' ', $errors), 422);
        }

        $version = (int) $pill->current_version + 1;
        DB::transaction(function () use ($id, $pill, $normalized, $version): void {
            DB::table('nutrition_pills')->where('id', $id)->update(array_merge(
                $this->databaseValues($normalized),
                [
                    'status' => (string) $pill->status === 'ARCHIVED' ? 'DRAFT' : 'DRAFT',
                    'current_version' => $version,
                    'approved_by_user_id' => null,
                    'approved_at' => null,
                    'updated_at' => now(),
                ],
            ));
            $this->writeVersion($id, $version, $normalized, 'MANUAL', 'Edición; requiere nueva aprobación.');
        }, 3);

        return $this->show($id);
    }

    /** @return array<string,mixed> */
    public function approve(string $id): array
    {
        $pill = $this->authorized($id, true);
        $payload = $this->serialize($pill);
        $errors = $this->validatePayload($payload);
        if ($errors !== []) {
            throw new \RuntimeException('No puede aprobarse: '.implode(' ', $errors), 422);
        }

        DB::table('nutrition_pills')->where('id', $id)->update([
            'status' => 'APPROVED',
            'approved_by_user_id' => $this->context->userId,
            'approved_at' => now(),
            'updated_at' => now(),
        ]);
        return $this->show($id);
    }

    /** @return array<string,mixed> */
    public function archive(string $id): array
    {
        $this->authorized($id, true);
        DB::table('nutrition_pills')->where('id', $id)->update([
            'status' => 'ARCHIVED',
            'updated_at' => now(),
        ]);
        return $this->show($id);
    }

    /** @return array<string,mixed> */
    public function duplicate(string $id): array
    {
        $source = $this->serialize($this->authorized($id));
        foreach (['id', 'scope_key', 'partner_id', 'status', 'source', 'source_reference', 'current_version', 'approved_by_user_id', 'approved_at', 'created_at', 'updated_at', 'editable'] as $field) {
            unset($source[$field]);
        }
        $source['name'] = $this->uniqueCopyName((string) $source['name']);
        $source['code'] = null;

        return $this->create($source, 'DUPLICATE', $id);
    }

    /** @return array<string,mixed> */
    public function delete(string $id): array
    {
        $this->authorized($id, true);
        $referenced = DB::table('nutrition_plan_items')->where('pill_id', $id)->exists();
        if ($referenced) {
            $pill = $this->archive($id);
            $pill['deletion_mode'] = 'ARCHIVED';
            return $pill;
        }

        DB::transaction(function () use ($id): void {
            if (DB::getSchemaBuilder()->hasTable('nutrition_ai_proposals')) {
                DB::table('nutrition_ai_proposals')->where('pill_id', $id)->update(['pill_id' => null]);
            }
            DB::table('nutrition_pill_versions')->where('pill_id', $id)->delete();
            DB::table('nutrition_pills')->where('id', $id)->delete();
        }, 3);

        return ['id' => $id, 'deletion_mode' => 'DELETED'];
    }

    /** @return array<int,array<string,mixed>> */
    public function availableForEngine(string $partnerId): array
    {
        return DB::table('nutrition_pills')
            ->where('status', 'APPROVED')
            ->where(function (Builder $scope) use ($partnerId): void {
                $scope->where('scope_key', 'GLOBAL')->orWhere('partner_id', $partnerId);
            })
            ->orderByRaw("CASE WHEN partner_id IS NULL THEN 1 ELSE 0 END")
            ->orderBy('name')
            ->get()
            ->map(fn (object $row): array => $this->serialize($row))
            ->all();
    }

    /** @param array<string,mixed> $payload @return array<int,string> */
    public function validatePayload(array $payload): array
    {
        $errors = [];
        $name = trim((string) ($payload['name'] ?? ''));
        $mealType = strtoupper(trim((string) ($payload['meal_type'] ?? '')));
        $ingredients = $payload['ingredients'] ?? [];
        $portion = trim((string) ($payload['portion_text'] ?? ''));
        $preparation = trim((string) ($payload['preparation'] ?? ''));

        if ($name === '' || $this->length($name) < 4) $errors[] = 'El nombre es obligatorio.';
        if (!in_array($mealType, self::MEAL_TYPES, true)) $errors[] = 'El tipo de comida no es válido.';
        if (!is_array($ingredients) || count($ingredients) < 1) $errors[] = 'Debe incluir al menos un ingrediente.';
        if ($portion === '') $errors[] = 'Debe indicar una porción base.';
        if ($preparation === '') $errors[] = 'Debe indicar una preparación.';

        $text = $this->lower($name.' '.$portion.' '.$preparation.' '.json_encode($ingredients, JSON_UNESCAPED_UNICODE));
        $forbidden = [
            'cura ', 'curar ', 'trata la diabetes', 'tratar la diabetes', 'detox', 'desintoxica',
            'ganoderma', 'medicamento', 'pastilla', 'insulina', 'quema grasa', 'acelera el metabolismo',
            'creatina', 'proteína en polvo', 'proteina en polvo', 'suplemento obligatorio',
        ];
        foreach ($forbidden as $term) {
            if (str_contains($text, $term)) {
                $errors[] = 'Contiene una afirmación, suplemento o término no permitido: '.$term.'.';
            }
        }

        $prep = $payload['prep_minutes'] ?? null;
        if ($prep !== null && (!is_numeric($prep) || (int) $prep < 1 || (int) $prep > 480)) {
            $errors[] = 'El tiempo de preparación debe estar entre 1 y 480 minutos.';
        }
        foreach ([
            'food_groups' => self::FOOD_GROUPS,
            'allergens' => self::ALLERGENS,
            'compatible_goals' => self::GOALS,
            'dietary_patterns' => self::DIETARY_PATTERNS,
        ] as $field => $allowed) {
            $values = is_array($payload[$field] ?? null) ? $payload[$field] : [];
            $unknown = array_values(array_diff($values, $allowed));
            if ($unknown !== []) {
                $errors[] = 'El campo '.$field.' contiene valores no permitidos: '.implode(', ', $unknown).'.';
            }
        }
        $budget = strtoupper(trim((string) ($payload['budget_level'] ?? '')));
        if ($budget !== '' && !in_array($budget, self::BUDGET_LEVELS, true)) {
            $errors[] = 'El nivel de presupuesto no es válido.';
        }

        $limits = [
            'kcal_est' => 10000.0,
            'protein_g' => 1000.0,
            'carbs_g' => 2000.0,
            'fat_g' => 1000.0,
            'fiber_g' => 500.0,
            'sodium_mg' => 50000.0,
        ];
        foreach ($limits as $field => $maximum) {
            if (($payload[$field] ?? null) === null) continue;
            if (!is_numeric($payload[$field]) || (float) $payload[$field] < 0 || (float) $payload[$field] > $maximum) {
                $errors[] = 'El valor '.$field.' está fuera del rango permitido.';
            }
        }

        return array_values(array_unique($errors));
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function normalizePayload(array $data): array
    {
        $nutrition = is_array($data['nutrition_estimate'] ?? null) ? $data['nutrition_estimate'] : [];
        $ingredients = is_array($data['ingredients'] ?? null) ? $data['ingredients'] : [];
        $normalizedIngredients = [];
        foreach ($ingredients as $ingredient) {
            if (is_string($ingredient)) {
                if (trim($ingredient) !== '') $normalizedIngredients[] = ['name' => trim($ingredient), 'display' => trim($ingredient)];
                continue;
            }
            if (!is_array($ingredient)) continue;
            $display = trim((string) ($ingredient['display'] ?? $ingredient['name'] ?? ''));
            if ($display === '') continue;
            $normalizedIngredients[] = [
                'name' => trim((string) ($ingredient['name'] ?? $display)),
                'display' => $display,
            ];
        }

        return [
            'code' => $this->nullable($data['code'] ?? null),
            'meal_type' => strtoupper(trim((string) ($data['meal_type'] ?? ''))),
            'name' => trim((string) ($data['name'] ?? '')),
            'emoji' => $this->nullable($data['emoji'] ?? null),
            'description' => $this->nullable($data['description'] ?? null),
            'portion_text' => $this->nullable($data['portion_text'] ?? null),
            'ingredients' => $normalizedIngredients,
            'preparation' => $this->nullable($data['preparation'] ?? null),
            'food_groups' => $this->stringArray($data['food_groups'] ?? []),
            'allergens' => $this->stringArray($data['allergens'] ?? []),
            'tags' => $this->stringArray($data['tags'] ?? []),
            'compatible_goals' => $this->stringArray($data['compatible_goals'] ?? ['GENERAL_WELLNESS', 'MAINTAIN']),
            'dietary_patterns' => $this->stringArray($data['dietary_patterns'] ?? ['OMNIVORE']),
            'excluded_flags' => $this->stringArray($data['excluded_flags'] ?? []),
            'budget_level' => $this->nullable($data['budget_level'] ?? null),
            'prep_minutes' => is_numeric($data['prep_minutes'] ?? null) ? (int) $data['prep_minutes'] : null,
            'kcal_est' => $this->number($data['kcal_est'] ?? $nutrition['kcal'] ?? null),
            'protein_g' => $this->number($data['protein_g'] ?? $nutrition['protein_g'] ?? null),
            'carbs_g' => $this->number($data['carbs_g'] ?? $nutrition['carbs_g'] ?? null),
            'fat_g' => $this->number($data['fat_g'] ?? $nutrition['fat_g'] ?? null),
            'fiber_g' => $this->number($data['fiber_g'] ?? $nutrition['fiber_g'] ?? null),
            'sodium_mg' => $this->number($data['sodium_mg'] ?? $nutrition['sodium_mg'] ?? null),
        ];
    }

    /** @return array<string,mixed> */
    private function serialize(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'scope_key' => (string) $row->scope_key,
            'partner_id' => $row->partner_id,
            'code' => (string) $row->code,
            'meal_type' => (string) $row->meal_type,
            'name' => (string) $row->name,
            'emoji' => $row->emoji,
            'description' => $row->description,
            'portion_text' => $row->portion_text,
            'ingredients' => SqlValue::json($row->ingredients ?? null),
            'preparation' => $row->preparation,
            'food_groups' => SqlValue::json($row->food_groups ?? null),
            'allergens' => SqlValue::json($row->allergens ?? null),
            'tags' => SqlValue::json($row->tags ?? null),
            'compatible_goals' => SqlValue::json($row->compatible_goals ?? null),
            'dietary_patterns' => SqlValue::json($row->dietary_patterns ?? null),
            'excluded_flags' => SqlValue::json($row->excluded_flags ?? null),
            'budget_level' => $row->budget_level,
            'prep_minutes' => $row->prep_minutes !== null ? (int) $row->prep_minutes : null,
            'kcal_est' => $this->number($row->kcal_est ?? null),
            'protein_g' => $this->number($row->protein_g ?? null),
            'carbs_g' => $this->number($row->carbs_g ?? null),
            'fat_g' => $this->number($row->fat_g ?? null),
            'fiber_g' => $this->number($row->fiber_g ?? null),
            'sodium_mg' => $this->number($row->sodium_mg ?? null),
            'status' => (string) $row->status,
            'source' => (string) $row->source,
            'source_reference' => $row->source_reference,
            'current_version' => (int) $row->current_version,
            'approved_by_user_id' => $row->approved_by_user_id,
            'approved_at' => $row->approved_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'editable' => $this->context->isSuperAdmin()
                || ($row->partner_id !== null && (string) $row->partner_id === $this->partnerId()),
        ];
    }

    private function authorized(string $id, bool $write = false): object
    {
        $row = DB::table('nutrition_pills')->where('id', $id)->first();
        if ($row === null) throw new \RuntimeException('Píldora alimentaria no encontrada.', 404);
        if (!$this->context->isSuperAdmin()) {
            $partnerId = $this->partnerId();
            if ((string) $row->scope_key !== 'GLOBAL' && (string) $row->partner_id !== $partnerId) {
                throw new \RuntimeException('Píldora fuera de tu organización.', 403);
            }
            if ($write && $row->partner_id === null) {
                throw new \RuntimeException('La biblioteca global es de solo lectura para los socios.', 403);
            }
        }
        return $row;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function databaseValues(array $payload): array
    {
        return [
            'meal_type' => $payload['meal_type'],
            'name' => $payload['name'],
            'emoji' => $payload['emoji'],
            'description' => $payload['description'],
            'portion_text' => $payload['portion_text'],
            'ingredients' => SqlValue::encode($payload['ingredients']),
            'preparation' => $payload['preparation'],
            'food_groups' => SqlValue::encode($payload['food_groups']),
            'allergens' => SqlValue::encode($payload['allergens']),
            'tags' => SqlValue::encode($payload['tags']),
            'compatible_goals' => SqlValue::encode($payload['compatible_goals']),
            'dietary_patterns' => SqlValue::encode($payload['dietary_patterns']),
            'excluded_flags' => SqlValue::encode($payload['excluded_flags']),
            'budget_level' => $payload['budget_level'],
            'prep_minutes' => $payload['prep_minutes'],
            'kcal_est' => $payload['kcal_est'],
            'protein_g' => $payload['protein_g'],
            'carbs_g' => $payload['carbs_g'],
            'fat_g' => $payload['fat_g'],
            'fiber_g' => $payload['fiber_g'],
            'sodium_mg' => $payload['sodium_mg'],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function writeVersion(string $pillId, int $version, array $payload, string $source, string $note): void
    {
        DB::table('nutrition_pill_versions')->insert([
            'pill_id' => $pillId,
            'version_no' => $version,
            'snapshot' => SqlValue::encode($payload),
            'source' => $source,
            'created_by_user_id' => $this->context->userId,
            'change_note' => $note,
            'created_at' => now(),
        ]);
    }

    private function uniqueCopyName(string $name): string
    {
        $base = 'Copia de '.$name;
        $candidate = $base;
        $counter = 2;
        $scopeKey = $this->context->isSuperAdmin() ? 'GLOBAL' : 'PARTNER:'.$this->partnerId();
        while (DB::table('nutrition_pills')->where('scope_key', $scopeKey)->where('name', $candidate)->where('status', '!=', 'ARCHIVED')->exists()) {
            $candidate = $base.' '.$counter;
            $counter++;
        }
        return mb_substr($candidate, 0, 180);
    }

    private function uniqueCode(string $scopeKey, string $value): string
    {
        $base = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $this->ascii($value)), '_'));
        $base = substr($base !== '' ? $base : 'PILL', 0, 60);
        $candidate = $base;
        $counter = 2;
        while (DB::table('nutrition_pills')->where('scope_key', $scopeKey)->where('code', $candidate)->exists()) {
            $candidate = substr($base, 0, 55).'_'.$counter;
            $counter++;
        }
        return $candidate;
    }

    /** @return array<int,string> */
    private function stringArray(mixed $value): array
    {
        if (is_string($value)) $value = preg_split('/[,\n]+/', $value) ?: [];
        if (!is_array($value)) return [];
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => strtoupper(trim((string) $item)),
            $value,
        ), static fn (string $item): bool => $item !== '')));
    }

    private function partnerId(): string
    {
        return $this->context->partnerId
            ?? throw new \RuntimeException('El usuario no pertenece a un socio.', 403);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function ascii(string $value): string
    {
        if (function_exists('transliterator_transliterate')) {
            return (string) transliterator_transliterate('Any-Latin; Latin-ASCII;', $value);
        }
        return Str::ascii($value);
    }
}
