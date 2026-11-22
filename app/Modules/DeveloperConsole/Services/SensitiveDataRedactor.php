<?php

namespace App\Modules\DeveloperConsole\Services;

final class SensitiveDataRedactor
{
    public function redact(string $text, ?bool $redactPii = null): string
    {
        $patterns = [
            '/(Authorization\s*:\s*Bearer\s+)[^\s"\']+/i' => '$1[REDACTED]',
            '/\b(rhino_[A-Za-z0-9_.-]{16,})\b/' => '[RHINO_TOKEN_REDACTED]',
            '/\b(eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,})\b/' => '[JWT_REDACTED]',
            '/((?:password|password_hash|secret|token|api[_-]?key|private[_-]?key|client[_-]?secret|db_password)\s*[=:]\s*)[^\s,;\]}]+/i' => '$1[REDACTED]',
            '/("(?:password|password_hash|secret|token|api[_-]?key|private[_-]?key|client[_-]?secret|db_password)"\s*:\s*")[^"]*(")/i' => '$1[REDACTED]$2',
            '/(-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----).*?(-----END (?:RSA |EC |OPENSSH )?PRIVATE KEY-----)/s' => '$1\n[REDACTED]\n$2',
            '/\b(sk-[A-Za-z0-9_-]{12,})\b/' => '[OPENAI_KEY_REDACTED]',
        ];

        $redacted = preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
        $shouldRedactPii = $redactPii ?? (bool) config('developer_console.redact_pii', true);
        if ($shouldRedactPii) {
            $redacted = preg_replace_callback(
                '/\b([A-Z0-9._%+-]{1,3})[A-Z0-9._%+-]*@([A-Z0-9.-]+\.[A-Z]{2,})\b/i',
                static fn (array $match): string => $match[1].'***@'.$match[2],
                $redacted,
            ) ?? $redacted;
            $redacted = preg_replace('/(?<!\d)(?:\+?52\s*)?(\d{2,3})[\s.-]?\d{3,4}[\s.-]?\d{4}(?!\d)/', '$1***REDACTED', $redacted) ?? $redacted;
        }

        return $redacted;
    }
}
