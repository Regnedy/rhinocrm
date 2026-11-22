<?php

namespace App\Support\Database;

use DateTimeImmutable;
use DateTimeInterface;

final class SqlValue
{
    public static function date(mixed $value, ?DateTimeImmutable $default = null): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return new DateTimeImmutable((string) $value);
    }

    /** @return array<mixed> */
    public static function json(mixed $value, array $default = []): array
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : $default;
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function timestamp(DateTimeInterface $value): string
    {
        return $value->format('Y-m-d H:i:s');
    }
}
