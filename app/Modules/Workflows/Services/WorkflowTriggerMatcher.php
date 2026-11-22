<?php

namespace App\Modules\Workflows\Services;

final class WorkflowTriggerMatcher
{
    public function matches(array $conditions, array $context): bool
    {
        if ($conditions === []) {
            return true;
        }

        if (!array_is_list($conditions)) {
            foreach ($conditions as $field => $expected) {
                if ($this->value($context, (string) $field) != $expected) {
                    return false;
                }
            }

            return true;
        }

        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                return false;
            }

            $actual = $this->value($context, (string) ($condition['field'] ?? ''));
            $expected = $condition['value'] ?? null;
            $operator = strtolower((string) ($condition['operator'] ?? 'eq'));
            $matched = match ($operator) {
                'neq' => $actual != $expected,
                'gt' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
                'gte' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
                'lt' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
                'lte' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
                'in' => in_array($actual, (array) $expected, true),
                'contains' => is_array($actual)
                    ? in_array($expected, $actual, true)
                    : (is_string($actual) && is_string($expected) && str_contains($actual, $expected)),
                default => $actual == $expected,
            };

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    private function value(array $context, string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        $value = $context;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
