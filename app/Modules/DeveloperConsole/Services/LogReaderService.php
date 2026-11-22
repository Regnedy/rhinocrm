<?php

namespace App\Modules\DeveloperConsole\Services;

use Illuminate\Support\Str;

final class LogReaderService
{
    public function __construct(private readonly SensitiveDataRedactor $redactor) {}

    /** @return array<int, array{key:string,label:string,size:int,modified_at:?string}> */
    public function files(): array
    {
        $candidates = [];
        $this->appendCandidate($candidates, 'laravel', storage_path('logs/laravel.log'), 'Laravel');

        foreach ((array) glob(storage_path('logs/laravel-*.log')) as $path) {
            $this->appendCandidate($candidates, 'laravel:'.basename($path), $path, 'Laravel '.basename($path));
        }
        foreach ((array) glob(storage_path('logs/security/*.log')) as $path) {
            $this->appendCandidate($candidates, 'security:'.basename($path), $path, 'Seguridad '.basename($path));
        }

        usort($candidates, static fn (array $a, array $b): int => strcmp((string) $b['modified_at'], (string) $a['modified_at']));

        return $candidates;
    }

    /** @return array{file:string,entries:array<int,array<string,mixed>>,truncated:bool,read_bytes:int} */
    public function read(string $key, int $lines = 250, ?string $level = null, ?string $search = null): array
    {
        $file = $this->resolve($key);
        if ($file === null || !is_file($file)) {
            return ['file' => $key, 'entries' => [], 'truncated' => false, 'read_bytes' => 0];
        }

        $maxLines = (int) config('developer_console.max_log_lines', 1000);
        $requestedLines = max(1, min($maxLines, $lines));
        $maxBytes = (int) config('developer_console.max_log_bytes', 2097152);
        [$content, $readBytes, $truncated] = $this->tail($file, $requestedLines * 8, $maxBytes);
        $entries = $this->parseEntries($content);

        $level = $level !== null && $level !== '' ? strtoupper(trim($level)) : null;
        $search = $search !== null && trim($search) !== '' ? mb_strtolower(trim($search)) : null;
        $entries = array_values(array_filter($entries, static function (array $entry) use ($level, $search): bool {
            if ($level !== null && strtoupper((string) ($entry['level'] ?? '')) !== $level) {
                return false;
            }
            if ($search !== null && !str_contains(mb_strtolower((string) ($entry['raw'] ?? '')), $search)) {
                return false;
            }
            return true;
        }));

        $entries = array_slice($entries, -$requestedLines);
        foreach ($entries as &$entry) {
            $entry['raw'] = $this->redactor->redact((string) $entry['raw']);
            $entry['message'] = $this->redactor->redact((string) $entry['message']);
        }
        unset($entry);

        return [
            'file' => $key,
            'entries' => array_reverse($entries),
            'truncated' => $truncated,
            'read_bytes' => $readBytes,
        ];
    }

    public function context(string $key, int $lines): string
    {
        $result = $this->read($key, $lines);
        $chunks = array_map(static fn (array $entry): string => (string) $entry['raw'], array_reverse($result['entries']));
        return implode("\n", $chunks);
    }

    private function resolve(string $key): ?string
    {
        if ($key === 'laravel') {
            return storage_path('logs/laravel.log');
        }

        if (str_starts_with($key, 'laravel:')) {
            $name = basename(substr($key, strlen('laravel:')));
            if (!preg_match('/^laravel-[A-Za-z0-9_.-]+\.log$/', $name)) {
                return null;
            }
            return storage_path('logs/'.$name);
        }

        if (str_starts_with($key, 'security:')) {
            $name = basename(substr($key, strlen('security:')));
            if (!preg_match('/^[A-Za-z0-9_.-]+\.log$/', $name)) {
                return null;
            }
            return storage_path('logs/security/'.$name);
        }

        return null;
    }

    /** @return array{0:string,1:int,2:bool} */
    private function tail(string $path, int $lines, int $maxBytes): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['', 0, false];
        }

        try {
            $size = (int) (filesize($path) ?: 0);
            if ($size === 0) {
                return ['', 0, false];
            }

            $position = $size;
            $buffer = '';
            $lineCount = 0;
            $chunkSize = 8192;
            while ($position > 0 && $lineCount <= $lines && strlen($buffer) < $maxBytes) {
                $read = min($chunkSize, $position, $maxBytes - strlen($buffer));
                if ($read <= 0) {
                    break;
                }
                $position -= $read;
                fseek($handle, $position);
                $chunk = fread($handle, $read);
                if ($chunk === false) {
                    break;
                }
                $buffer = $chunk.$buffer;
                $lineCount = substr_count($buffer, "\n");
            }

            $parts = preg_split('/\R/', $buffer) ?: [];
            if ($position > 0 && $parts !== []) {
                array_shift($parts);
            }
            $selected = array_slice($parts, -$lines);

            return [implode("\n", $selected), strlen($buffer), $position > 0];
        } finally {
            fclose($handle);
        }
    }

    /** @return array<int,array{timestamp:?string,environment:?string,level:string,message:string,raw:string}> */
    private function parseEntries(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        $lines = preg_split('/\R/', $content) ?: [];
        $entries = [];
        $current = null;
        $pattern = '/^\[(?<timestamp>[^\]]+)\]\s+(?<environment>[A-Za-z0-9_.-]+)\.(?<level>[A-Z]+):\s*(?<message>.*)$/';

        foreach ($lines as $line) {
            if (preg_match($pattern, $line, $match) === 1) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = [
                    'timestamp' => $match['timestamp'],
                    'environment' => $match['environment'],
                    'level' => strtoupper($match['level']),
                    'message' => $match['message'],
                    'raw' => $line,
                ];
                continue;
            }

            if ($current === null) {
                $current = ['timestamp' => null, 'environment' => null, 'level' => 'UNKNOWN', 'message' => $line, 'raw' => $line];
            } else {
                $current['raw'] .= "\n".$line;
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        return $entries;
    }

    /** @param array<int,array<string,mixed>> $candidates */
    private function appendCandidate(array &$candidates, string $key, string $path, string $label): void
    {
        if (!is_file($path)) {
            return;
        }
        $modified = filemtime($path);
        $candidates[] = [
            'key' => $key,
            'label' => $label,
            'size' => (int) (filesize($path) ?: 0),
            'modified_at' => $modified !== false ? date(DATE_ATOM, $modified) : null,
        ];
    }
}
