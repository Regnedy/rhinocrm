<?php

namespace App\Console\Commands;

use App\Support\Database\SqlValue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SeedNutritionLibraryCommand extends Command
{
    protected $signature = 'rhino:nutrition-seed-library
        {--force : Actualiza también registros BUILTIN existentes}
        {--activate-drafts : Activa las píldoras marcadas como borrador en el archivo fuente}';

    protected $description = 'Instala o actualiza la biblioteca base y las plantillas del motor nutricional RHINO.';

    public function handle(): int
    {
        if (!Schema::hasTable('nutrition_pills') || !Schema::hasTable('nutrition_templates')) {
            $this->error('Primero ejecuta php artisan migrate --force.');
            return self::FAILURE;
        }

        $path = database_path('data/nutrition_library_v1.json');
        if (!is_file($path)) {
            $this->error('No existe database/data/nutrition_library_v1.json.');
            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $libraryVersion = trim((string) ($payload['library_version'] ?? ''));
        if ($libraryVersion === '') {
            $this->error('La biblioteca nutricional no declara library_version.');
            return self::FAILURE;
        }
        $pills = is_array($payload['pills'] ?? null) ? $payload['pills'] : [];
        $templates = is_array($payload['templates'] ?? null) ? $payload['templates'] : [];
        $force = (bool) $this->option('force');
        $activateDrafts = (bool) $this->option('activate-drafts');
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'templates' => 0];

        DB::transaction(function () use ($pills, $templates, $libraryVersion, $force, $activateDrafts, &$stats): void {
            foreach ($pills as $item) {
                if (!is_array($item) || trim((string) ($item['code'] ?? '')) === '') {
                    continue;
                }

                $code = strtoupper(trim((string) $item['code']));
                $existing = DB::table('nutrition_pills')
                    ->where('scope_key', 'GLOBAL')
                    ->where('code', $code)
                    ->first();

                if ($existing !== null && !str_starts_with((string) $existing->source, 'BUILTIN_')) {
                    $stats['skipped']++;
                    continue;
                }

                if ($existing !== null && !$force) {
                    $stats['skipped']++;
                    continue;
                }

                $nutrition = is_array($item['nutrition_estimate'] ?? null)
                    ? $item['nutrition_estimate']
                    : [];
                $status = strtoupper((string) ($item['status'] ?? 'DRAFT'));
                if ($activateDrafts && $status === 'DRAFT') {
                    $status = 'APPROVED';
                }
                $builtinSource = strtoupper(trim((string) ($item['source'] ?? 'BUILTIN_V1')));
                if (!preg_match('/^BUILTIN_V[0-9]+$/', $builtinSource)) {
                    $builtinSource = 'BUILTIN_V1';
                }

                $id = $existing?->id ?? 'NPI_'.strtoupper(substr(hash('sha256', 'GLOBAL|'.$code), 0, 32));
                $version = $existing !== null ? ((int) $existing->current_version + 1) : 1;
                $values = [
                    'scope_key' => 'GLOBAL',
                    'partner_id' => null,
                    'code' => $code,
                    'meal_type' => strtoupper((string) ($item['meal_type'] ?? 'OTHER')),
                    'name' => trim((string) ($item['name'] ?? $code)),
                    'emoji' => $this->nullable($item['emoji'] ?? null),
                    'description' => $this->nullable($item['description'] ?? null),
                    'portion_text' => $this->nullable($item['portion_text'] ?? null),
                    'ingredients' => SqlValue::encode(is_array($item['ingredients'] ?? null) ? $item['ingredients'] : []),
                    'preparation' => $this->nullable($item['preparation'] ?? null),
                    'food_groups' => SqlValue::encode(is_array($item['food_groups'] ?? null) ? $item['food_groups'] : []),
                    'allergens' => SqlValue::encode(is_array($item['allergens'] ?? null) ? $item['allergens'] : []),
                    'tags' => SqlValue::encode(is_array($item['tags'] ?? null) ? $item['tags'] : []),
                    'compatible_goals' => SqlValue::encode(is_array($item['compatible_goals'] ?? null) ? $item['compatible_goals'] : []),
                    'dietary_patterns' => SqlValue::encode(is_array($item['dietary_patterns'] ?? null) ? $item['dietary_patterns'] : ['OMNIVORE']),
                    'excluded_flags' => SqlValue::encode(is_array($item['excluded_flags'] ?? null) ? $item['excluded_flags'] : []),
                    'budget_level' => $this->nullable($item['budget_level'] ?? null),
                    'prep_minutes' => is_numeric($item['prep_minutes'] ?? null) ? (int) $item['prep_minutes'] : null,
                    'kcal_est' => $this->number($nutrition['kcal'] ?? null),
                    'protein_g' => $this->number($nutrition['protein_g'] ?? null),
                    'carbs_g' => $this->number($nutrition['carbs_g'] ?? null),
                    'fat_g' => $this->number($nutrition['fat_g'] ?? null),
                    'fiber_g' => $this->number($nutrition['fiber_g'] ?? null),
                    'sodium_mg' => $this->number($nutrition['sodium_mg'] ?? null),
                    'status' => $status,
                    'source' => $builtinSource,
                    'source_reference' => $this->nullable($item['source_reference'] ?? null),
                    'current_version' => $version,
                    'approved_by_user_id' => $status === 'APPROVED' ? 'SYSTEM' : null,
                    'approved_at' => $status === 'APPROVED' ? now() : null,
                    'updated_at' => now(),
                ];

                if ($existing === null) {
                    DB::table('nutrition_pills')->insert(array_merge($values, [
                        'id' => $id,
                        'created_by_user_id' => 'SYSTEM',
                        'created_at' => now(),
                    ]));
                    $stats['created']++;
                } else {
                    DB::table('nutrition_pills')->where('id', $id)->update($values);
                    $stats['updated']++;
                }

                DB::table('nutrition_pill_versions')->updateOrInsert(
                    ['pill_id' => $id, 'version_no' => $version],
                    [
                        'snapshot' => SqlValue::encode(array_merge($item, [
                            'status' => $status,
                            'library_version' => $libraryVersion,
                        ])),
                        'source' => $builtinSource,
                        'created_by_user_id' => 'SYSTEM',
                        'change_note' => 'Carga de biblioteca base RHINO.',
                        'created_at' => now(),
                    ],
                );
            }

            foreach ($templates as $item) {
                if (!is_array($item) || trim((string) ($item['code'] ?? '')) === '') {
                    continue;
                }
                $code = strtoupper(trim((string) $item['code']));
                $existing = DB::table('nutrition_templates')
                    ->where('scope_key', 'GLOBAL')
                    ->where('code', $code)
                    ->first();
                if ($existing !== null && !$force) {
                    continue;
                }
                $id = $existing?->id ?? 'NTP_'.strtoupper(substr(hash('sha256', 'GLOBAL|'.$code), 0, 32));
                $values = [
                    'scope_key' => 'GLOBAL',
                    'partner_id' => null,
                    'code' => $code,
                    'name' => trim((string) ($item['name'] ?? $code)),
                    'description' => $this->nullable($item['description'] ?? null),
                    'status' => strtoupper((string) ($item['status'] ?? 'ACTIVE')),
                    'compatible_goals' => SqlValue::encode(is_array($item['compatible_goals'] ?? null) ? $item['compatible_goals'] : []),
                    'dietary_patterns' => SqlValue::encode(is_array($item['dietary_patterns'] ?? null) ? $item['dietary_patterns'] : []),
                    'rules' => SqlValue::encode(is_array($item['rules'] ?? null) ? $item['rules'] : []),
                    'current_version' => $existing !== null ? ((int) $existing->current_version + 1) : 1,
                    'approved_by_user_id' => 'SYSTEM',
                    'approved_at' => now(),
                    'updated_at' => now(),
                ];
                if ($existing === null) {
                    DB::table('nutrition_templates')->insert(array_merge($values, [
                        'id' => $id,
                        'created_by_user_id' => 'SYSTEM',
                        'created_at' => now(),
                    ]));
                } else {
                    DB::table('nutrition_templates')->where('id', $id)->update($values);
                }
                $stats['templates']++;
            }
        }, 3);

        $this->info(sprintf(
            'Biblioteca instalada. Píldoras creadas: %d; actualizadas: %d; omitidas: %d; plantillas procesadas: %d.',
            $stats['created'],
            $stats['updated'],
            $stats['skipped'],
            $stats['templates'],
        ));
        $this->line('Usa --force únicamente para aplicar una nueva revisión de la biblioteca BUILTIN.');

        return self::SUCCESS;
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
}
