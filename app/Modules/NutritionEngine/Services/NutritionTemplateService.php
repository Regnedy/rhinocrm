<?php

namespace App\Modules\NutritionEngine\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\NutritionEngine\Support\NutritionDocumentTemplate;
use App\Support\Database\SqlValue;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NutritionTemplateService
{
    private const GOALS = ['GENERAL_WELLNESS', 'MAINTAIN', 'REDUCE', 'GAIN'];
    private const DIETARY_PATTERNS = ['OMNIVORE', 'VEGETARIAN', 'VEGAN'];
    private const BUDGET_LEVELS = ['LOW', 'MEDIUM', 'HIGH'];
    private const STATUSES = ['DRAFT', 'ACTIVE', 'ARCHIVED'];

    public function __construct(private readonly UserContextDTO $context) {}

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function list(array $filters = []): array
    {
        $partnerId = $this->partnerId();
        $limit = min(200, max(1, (int) ($filters['limit'] ?? 100)));
        $query = DB::table('nutrition_templates')
            ->where(function (Builder $scope) use ($partnerId): void {
                $scope->where('scope_key', 'GLOBAL')->orWhere('partner_id', $partnerId);
            });

        $status = strtoupper(trim((string) ($filters['status'] ?? 'ACTIVE')));
        if ($status !== 'ALL') {
            $query->where('status', in_array($status, self::STATUSES, true) ? $status : 'ACTIVE');
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $scope) use ($search): void {
                $like = '%'.$search.'%';
                $scope->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('description', 'like', $like);
            });
        }

        $total = (clone $query)->count();
        $items = $query
            ->orderByRaw("FIELD(status,'ACTIVE','DRAFT','ARCHIVED')")
            ->orderByRaw('CASE WHEN partner_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => $this->serialize($row))
            ->all();

        return ['items' => $items, 'count' => count($items), 'total' => $total, 'limit' => $limit];
    }

    /** @return array<string,mixed> */
    public function show(string $id): array
    {
        return $this->serialize($this->authorized($id));
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function create(array $data): array
    {
        $partnerId = $this->partnerId();
        $payload = $this->normalizePayload($data);
        $this->assertValid($payload);
        $this->assertUniqueName($partnerId, (string) $payload['name']);

        $id = 'NTP_'.strtoupper((string) Str::ulid());
        $code = $this->uniqueCode($partnerId, (string) ($payload['code'] ?? $payload['name']));

        DB::transaction(function () use ($id, $partnerId, $code, $payload): void {
            DB::table('nutrition_templates')->insert(array_merge($this->databaseValues($payload), [
                'id' => $id,
                'scope_key' => 'PARTNER:'.$partnerId,
                'partner_id' => $partnerId,
                'code' => $code,
                'status' => 'DRAFT',
                'current_version' => 1,
                'created_by_user_id' => $this->context->userId,
                'approved_by_user_id' => null,
                'approved_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $this->writeVersion($id, 1, $payload, 'Creación de plantilla.');
        }, 3);

        return $this->show($id);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function update(string $id, array $data): array
    {
        $template = $this->authorized($id, true);
        $current = $this->serialize($template);
        $payload = $this->normalizePayload(array_merge($current, $data));
        $this->assertValid($payload);
        $this->assertUniqueName($this->partnerId(), (string) $payload['name'], $id);
        $version = (int) $template->current_version + 1;

        DB::transaction(function () use ($id, $payload, $version): void {
            DB::table('nutrition_templates')->where('id', $id)->update(array_merge($this->databaseValues($payload), [
                'status' => 'DRAFT',
                'current_version' => $version,
                'approved_by_user_id' => null,
                'approved_at' => null,
                'updated_at' => now(),
            ]));
            $this->writeVersion($id, $version, $payload, 'Edición; requiere activación.');
        }, 3);

        return $this->show($id);
    }

    /** @return array<string,mixed> */
    public function activate(string $id): array
    {
        $template = $this->authorized($id, true);
        $this->assertValid($this->normalizePayload($this->serialize($template)));
        DB::table('nutrition_templates')->where('id', $id)->update([
            'status' => 'ACTIVE',
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
        DB::table('nutrition_templates')->where('id', $id)->update([
            'status' => 'ARCHIVED',
            'updated_at' => now(),
        ]);
        return $this->show($id);
    }

    /** @return array<string,mixed> */
    public function duplicate(string $id): array
    {
        $source = $this->serialize($this->authorized($id));
        unset($source['id'], $source['scope_key'], $source['partner_id'], $source['status'], $source['current_version'], $source['editable'], $source['approved_by_user_id'], $source['approved_at'], $source['created_at'], $source['updated_at']);
        $source['name'] = $this->uniqueCopyName((string) $source['name']);
        $source['code'] = null;
        return $this->create($source);
    }

    /** @return array<string,mixed> */
    public function delete(string $id): array
    {
        $this->authorized($id, true);
        $referenced = DB::table('nutrition_plan_versions')->where('template_id', $id)->exists()
            || DB::table('nutrition_engine_runs')->where('template_id', $id)->exists();

        if ($referenced) {
            $template = $this->archive($id);
            $template['deletion_mode'] = 'ARCHIVED';
            return $template;
        }

        DB::transaction(function () use ($id): void {
            if (DB::getSchemaBuilder()->hasTable('nutrition_template_versions')) {
                DB::table('nutrition_template_versions')->where('template_id', $id)->delete();
            }
            DB::table('nutrition_templates')->where('id', $id)->delete();
        }, 3);

        return ['id' => $id, 'deletion_mode' => 'DELETED'];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function normalizePayload(array $data): array
    {
        $rules = is_array($data['rules'] ?? null) ? $data['rules'] : [];
        if (is_array($data['document'] ?? null)) {
            $rules['document'] = $data['document'];
        }
        $rules['document'] = NutritionDocumentTemplate::normalize($rules['document'] ?? []);

        $rules['days'] = 7;
        $rules['default_meals_per_day'] = $this->integer($rules['default_meals_per_day'] ?? null) ?? 4;
        $rules['max_same_pill_per_week'] = $this->integer($rules['max_same_pill_per_week'] ?? null) ?? 1;
        $rules['max_prep_minutes'] = $this->integer($rules['max_prep_minutes'] ?? null);
        $rules['required_dietary_pattern'] = $this->nullableUpper($rules['required_dietary_pattern'] ?? null);
        $rules['prefer_tags'] = $this->stringArray($rules['prefer_tags'] ?? []);
        $rules['prefer_food_groups'] = $this->stringArray($rules['prefer_food_groups'] ?? []);
        $rules['prefer_budget_levels'] = $this->stringArray($rules['prefer_budget_levels'] ?? []);
        $rules['portion_strategy'] = $this->nullableUpper($rules['portion_strategy'] ?? 'BASE') ?? 'BASE';
        $rules['macro_percentages'] = $this->macroPercentages($rules['macro_percentages'] ?? []);

        return [
            'code' => $this->nullable($data['code'] ?? null),
            'name' => trim((string) ($data['name'] ?? '')),
            'description' => $this->nullable($data['description'] ?? null),
            'compatible_goals' => $this->stringArray($data['compatible_goals'] ?? self::GOALS),
            'dietary_patterns' => $this->stringArray($data['dietary_patterns'] ?? self::DIETARY_PATTERNS),
            'rules' => $rules,
            'document' => $rules['document'],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function assertValid(array $payload): void
    {
        $errors = [];
        $name = trim((string) ($payload['name'] ?? ''));
        if (mb_strlen($name) < 4) $errors[] = 'El nombre de la plantilla es obligatorio.';

        foreach (($payload['compatible_goals'] ?? []) as $goal) {
            if (!in_array($goal, self::GOALS, true)) $errors[] = 'Objetivo compatible no válido: '.$goal.'.';
        }
        foreach (($payload['dietary_patterns'] ?? []) as $pattern) {
            if (!in_array($pattern, self::DIETARY_PATTERNS, true)) $errors[] = 'Patrón alimentario no válido: '.$pattern.'.';
        }

        $rules = is_array($payload['rules'] ?? null) ? $payload['rules'] : [];
        $meals = (int) ($rules['default_meals_per_day'] ?? 0);
        if ($meals < 3 || $meals > 5) $errors[] = 'Las comidas por día deben estar entre 3 y 5.';
        $repeats = (int) ($rules['max_same_pill_per_week'] ?? 0);
        if ($repeats < 1 || $repeats > 7) $errors[] = 'Las repeticiones máximas deben estar entre 1 y 7.';
        if (($rules['max_prep_minutes'] ?? null) !== null && ((int) $rules['max_prep_minutes'] < 1 || (int) $rules['max_prep_minutes'] > 480)) {
            $errors[] = 'El tiempo máximo de preparación no es válido.';
        }
        if (($rules['required_dietary_pattern'] ?? null) !== null && !in_array($rules['required_dietary_pattern'], self::DIETARY_PATTERNS, true)) {
            $errors[] = 'El patrón obligatorio no es válido.';
        }
        foreach (($rules['prefer_budget_levels'] ?? []) as $budget) {
            if (!in_array($budget, self::BUDGET_LEVELS, true)) $errors[] = 'Nivel de presupuesto no válido: '.$budget.'.';
        }

        if ($errors !== []) throw new \RuntimeException(implode(' ', array_values(array_unique($errors))), 422);
    }

    /** @return array<string,mixed> */
    private function serialize(object $row): array
    {
        $rules = SqlValue::json($row->rules ?? null);
        $document = NutritionDocumentTemplate::normalize($rules['document'] ?? []);
        $rules['document'] = $document;

        return [
            'id' => (string) $row->id,
            'scope_key' => (string) $row->scope_key,
            'partner_id' => $row->partner_id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'description' => $row->description,
            'status' => (string) $row->status,
            'compatible_goals' => SqlValue::json($row->compatible_goals ?? null),
            'dietary_patterns' => SqlValue::json($row->dietary_patterns ?? null),
            'rules' => $rules,
            'document' => $document,
            'current_version' => (int) $row->current_version,
            'approved_by_user_id' => $row->approved_by_user_id,
            'approved_at' => $row->approved_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'editable' => $row->partner_id !== null && (string) $row->partner_id === $this->partnerId(),
        ];
    }

    private function authorized(string $id, bool $write = false): object
    {
        $row = DB::table('nutrition_templates')->where('id', $id)->first();
        if ($row === null) throw new \RuntimeException('Plantilla alimentaria no encontrada.', 404);
        $partnerId = $this->partnerId();
        if ((string) $row->scope_key !== 'GLOBAL' && (string) $row->partner_id !== $partnerId) {
            throw new \RuntimeException('Plantilla fuera de tu organización.', 403);
        }
        if ($write && $row->partner_id === null) {
            throw new \RuntimeException('Las plantillas globales son de solo lectura. Duplica la plantilla para personalizarla.', 403);
        }
        return $row;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function databaseValues(array $payload): array
    {
        return [
            'name' => $payload['name'],
            'description' => $payload['description'],
            'compatible_goals' => SqlValue::encode($payload['compatible_goals']),
            'dietary_patterns' => SqlValue::encode($payload['dietary_patterns']),
            'rules' => SqlValue::encode($payload['rules']),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function writeVersion(string $templateId, int $version, array $payload, string $note): void
    {
        if (!DB::getSchemaBuilder()->hasTable('nutrition_template_versions')) return;
        DB::table('nutrition_template_versions')->insert([
            'template_id' => $templateId,
            'version_no' => $version,
            'snapshot' => SqlValue::encode($payload),
            'created_by_user_id' => $this->context->userId,
            'change_note' => $note,
            'created_at' => now(),
        ]);
    }

    private function assertUniqueName(string $partnerId, string $name, ?string $exceptId = null): void
    {
        $query = DB::table('nutrition_templates')
            ->where('partner_id', $partnerId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name, 'UTF-8')])
            ->where('status', '!=', 'ARCHIVED');
        if ($exceptId !== null) $query->where('id', '!=', $exceptId);
        if ($query->exists()) throw new \RuntimeException('Ya existe una plantilla con un nombre equivalente.', 409);
    }

    private function uniqueCode(string $partnerId, string $value): string
    {
        $base = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', Str::ascii($value)), '_'));
        $base = substr($base !== '' ? $base : 'TEMPLATE', 0, 60);
        $candidate = $base;
        $counter = 2;
        while (DB::table('nutrition_templates')->where('scope_key', 'PARTNER:'.$partnerId)->where('code', $candidate)->exists()) {
            $candidate = substr($base, 0, 55).'_'.$counter;
            $counter++;
        }
        return $candidate;
    }

    private function uniqueCopyName(string $name): string
    {
        $base = 'Copia de '.$name;
        $candidate = $base;
        $counter = 2;
        while (DB::table('nutrition_templates')->where('partner_id', $this->partnerId())->where('name', $candidate)->where('status', '!=', 'ARCHIVED')->exists()) {
            $candidate = $base.' '.$counter;
            $counter++;
        }
        return mb_substr($candidate, 0, 180);
    }

    /** @return array<string,int> */
    private function macroPercentages(mixed $value): array
    {
        $data = is_array($value) ? $value : [];
        $protein = max(10, min(50, (int) ($data['protein'] ?? 30)));
        $carbs = max(10, min(70, (int) ($data['carbs'] ?? 40)));
        $fat = max(10, min(50, (int) ($data['fat'] ?? 30)));
        $sum = $protein + $carbs + $fat;
        if ($sum !== 100) return ['protein' => 30, 'carbs' => 40, 'fat' => 30];
        return compact('protein', 'carbs', 'fat');
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

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function nullableUpper(mixed $value): ?string
    {
        $text = $this->nullable($value);
        return $text === null ? null : strtoupper($text);
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function partnerId(): string
    {
        return $this->context->partnerId ?? throw new \RuntimeException('El usuario no pertenece a un socio.', 403);
    }
}
