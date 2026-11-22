<?php

namespace App\Modules\NutritionEngine\Support;

final class NutritionDocumentTemplate
{
    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'title_template' => 'Plan Alimenticio para {name} 🍏',
            'section_titles' => [
                'summary' => '1. Resumen Personal',
                'targets' => '2. Metas Clave: Calorías y Macros',
                'portions' => '3. Guía Rápida de Porciones',
                'hydration' => '4. Hidratación y Hábitos',
                'limits' => '5. Alimentos y Hábitos a Limitar',
                'shopping' => '6. Lista de Compras Inteligente',
                'weekly' => '7. Tu Plan Semanal Flexible',
                'benefits' => '8. ¿Qué me Aporta?',
                'recipes' => '9. Recetario Rápido y Consejos de Cocina',
                'cravings' => '10. Estrategias ante Antojos',
                'follow_up' => '11. Recomendaciones de Seguimiento',
            ],
            'golden_rules_title' => 'Reglas de Oro',
            'golden_rules' => [
                'Prioriza alimentos frescos, verduras, leguminosas y cereales integrales.',
                'Usa agua simple como bebida principal y limita bebidas azucaradas.',
                'Distribuye tus comidas de acuerdo con tu rutina y señales de hambre y saciedad.',
            ],
            'flexibility_note' => 'Este es un menú guía. Puedes intercambiar comidas del mismo tipo, respetando las porciones y restricciones indicadas.',
            'hydration_text' => 'Agua simple e infusiones sin azúcar como bebidas principales. Ajusta la cantidad al clima, actividad, tolerancia y recomendaciones médicas personales.',
            'cooking_intro' => 'Prefiere preparaciones a la plancha, al vapor, hervidas u horneadas. Sazona con hierbas y especias y modera sal, azúcar y grasas añadidas.',
            'follow_up_notice' => 'Este documento es una orientación general y no sustituye una consulta con un profesional de salud o nutrición.',
            'follow_up_items' => [
                'Mantén horarios realistas y registra qué comidas fueron más fáciles de seguir.',
                'Ajusta variedad y presentación antes de reducir más las porciones.',
                'Solicita revisión profesional ante síntomas, embarazo, lactancia, alergias graves, enfermedad o medicamentos.',
            ],
            'craving_support_intro' => 'Si aparece un antojo fuera de tu plan, evita decisiones impulsivas y usa una alternativa sencilla que puedas sostener.',
            'craving_support_items' => [
                'Toma agua simple o una infusión sin azúcar y espera unos minutos antes de decidir.',
                'Si persiste el hambre, elige una colación prevista en tu plan o una opción equivalente compatible con tus restricciones.',
                'Si los antojos son frecuentes, intensos o difíciles de manejar, registra cuándo aparecen y coméntalo con un profesional de salud o nutrición.',
            ],
            'foods_to_limit' => [
                [
                    'title' => 'Bebidas Azucaradas',
                    'emoji' => '🥤',
                    'items' => ['Refrescos y bebidas endulzadas', 'Jugos industrializados', 'Bebidas energéticas', 'Cafés o tés con jarabes'],
                ],
                [
                    'title' => 'Snacks y Ultraprocesados',
                    'emoji' => '🥨',
                    'items' => ['Frituras', 'Galletas, pasteles y pan dulce', 'Dulces con alto contenido de azúcar', 'Comida rápida frecuente'],
                ],
                [
                    'title' => 'Otros Alimentos y Hábitos',
                    'emoji' => '🍔',
                    'items' => ['Embutidos grasosos', 'Aderezos cremosos en exceso', 'Alimentos empanizados o fritos', 'Exceso de sal y alcohol'],
                ],
            ],
            'benefit_cards' => [
                ['title' => 'Avena', 'emoji' => '🌾', 'text' => 'Aporta fibra soluble y puede favorecer la saciedad.'],
                ['title' => 'Leguminosas', 'emoji' => '🫘', 'text' => 'Aportan proteína vegetal, fibra y energía de absorción gradual.'],
                ['title' => 'Huevo', 'emoji' => '🥚', 'text' => 'Fuente práctica de proteína completa y versátil.'],
                ['title' => 'Pescado', 'emoji' => '🐟', 'text' => 'Aporta proteína y grasas insaturadas según la variedad.'],
                ['title' => 'Hierbas y cítricos', 'emoji' => '🍋', 'text' => 'Ayudan a dar sabor sin depender de exceso de sal.'],
                ['title' => 'Verduras de hoja verde', 'emoji' => '🥬', 'text' => 'Aportan vitaminas, minerales y volumen con pocas calorías.'],
            ],
            'show_risk_bar' => true,
            'show_macros' => true,
            'show_follow_up' => true,
            'show_limits' => true,
            'show_benefits' => true,
            'show_recipes' => true,
            'show_shopping_list' => true,
            'show_craving_support' => true,
            'footer_note' => 'Documento generado por RHINO CRM. Las mediciones de bioimpedancia son estimaciones y pueden variar según hidratación y condiciones de medición.',
        ];
    }

    /** @return array<string,mixed> */
    public static function normalize(mixed $value): array
    {
        $input = is_array($value) ? $value : [];
        $document = self::merge(self::defaults(), $input);

        $document['title_template'] = self::text($document['title_template'] ?? null, 'Plan Alimenticio para {name} 🍏', 180);
        $document['golden_rules_title'] = self::text($document['golden_rules_title'] ?? null, 'Reglas de Oro', 120);
        $document['flexibility_note'] = self::text($document['flexibility_note'] ?? null, '', 1200);
        $document['hydration_text'] = self::text($document['hydration_text'] ?? null, '', 2000);
        $document['cooking_intro'] = self::text($document['cooking_intro'] ?? null, '', 2000);
        $document['follow_up_notice'] = self::text($document['follow_up_notice'] ?? null, '', 2000);
        $document['craving_support_intro'] = self::text($document['craving_support_intro'] ?? null, '', 1600);
        $document['footer_note'] = self::text($document['footer_note'] ?? null, '', 2000);

        $document['golden_rules'] = self::stringList($document['golden_rules'] ?? [], 12, 500);
        $document['follow_up_items'] = self::stringList($document['follow_up_items'] ?? [], 12, 500);
        $document['craving_support_items'] = self::stringList($document['craving_support_items'] ?? [], 8, 500);
        $document['foods_to_limit'] = self::groupList($document['foods_to_limit'] ?? []);
        $document['benefit_cards'] = self::benefitList($document['benefit_cards'] ?? []);

        $titles = is_array($document['section_titles'] ?? null) ? $document['section_titles'] : [];
        foreach (self::defaults()['section_titles'] as $key => $fallback) {
            $titles[$key] = self::text($titles[$key] ?? null, $fallback, 180);
        }
        $document['section_titles'] = $titles;

        foreach (['show_risk_bar', 'show_macros', 'show_follow_up', 'show_limits', 'show_benefits', 'show_recipes', 'show_shopping_list', 'show_craving_support'] as $key) {
            $document[$key] = filter_var($document[$key] ?? false, FILTER_VALIDATE_BOOL);
        }

        return $document;
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $override @return array<string,mixed> */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && self::isAssoc($value) && self::isAssoc($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private static function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    /** @return array<int,string> */
    private static function stringList(mixed $value, int $maxItems, int $maxLength): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r?\n+/', $value) ?: [];
        }
        if (!is_array($value)) return [];

        $items = [];
        foreach ($value as $item) {
            $text = self::text($item, '', $maxLength);
            if ($text !== '') $items[] = $text;
            if (count($items) >= $maxItems) break;
        }
        return array_values(array_unique($items));
    }

    /** @return array<int,array{title:string,emoji:string,items:array<int,string>}> */
    private static function groupList(mixed $value): array
    {
        if (!is_array($value)) return [];
        $groups = [];
        foreach ($value as $group) {
            if (!is_array($group)) continue;
            $title = self::text($group['title'] ?? null, '', 120);
            $items = self::stringList($group['items'] ?? [], 12, 300);
            if ($title === '' || $items === []) continue;
            $groups[] = [
                'title' => $title,
                'emoji' => self::text($group['emoji'] ?? null, '', 16),
                'items' => $items,
            ];
            if (count($groups) >= 8) break;
        }
        return $groups;
    }

    /** @return array<int,array{title:string,emoji:string,text:string}> */
    private static function benefitList(mixed $value): array
    {
        if (!is_array($value)) return [];
        $cards = [];
        foreach ($value as $card) {
            if (!is_array($card)) continue;
            $title = self::text($card['title'] ?? null, '', 100);
            $text = self::text($card['text'] ?? null, '', 500);
            if ($title === '' || $text === '') continue;
            $cards[] = [
                'title' => $title,
                'emoji' => self::text($card['emoji'] ?? null, '', 16),
                'text' => $text,
            ];
            if (count($cards) >= 12) break;
        }
        return $cards;
    }

    private static function text(mixed $value, string $fallback, int $maxLength): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') $text = $fallback;
        return mb_substr($text, 0, $maxLength);
    }
}
