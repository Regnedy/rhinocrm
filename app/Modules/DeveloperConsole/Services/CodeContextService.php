<?php

namespace App\Modules\DeveloperConsole\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class CodeContextService
{
    public function __construct(private readonly SensitiveDataRedactor $redactor) {}

    /** @return array<int,array{path:string,size:int,modified_at:string}> */
    public function files(?string $search = null, int $limit = 150): array
    {
        $needle = $search !== null ? mb_strtolower(trim($search)) : '';
        $result = [];
        foreach ((array) config('developer_console.source_roots', []) as $root) {
            $rootPath = base_path((string) $root);
            if (!is_dir($rootPath)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || !$this->allowedExtension($file->getExtension())) {
                    continue;
                }
                $relative = $this->relativePath($file->getPathname());
                if ($needle !== '' && !str_contains(mb_strtolower($relative), $needle)) {
                    continue;
                }
                $result[] = [
                    'path' => $relative,
                    'size' => $file->getSize(),
                    'modified_at' => date(DATE_ATOM, $file->getMTime()),
                ];
                if (count($result) >= max(1, min(500, $limit))) {
                    break 2;
                }
            }
        }

        usort($result, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        return $result;
    }

    /** @return array{path:string,content:string,size:int,truncated:bool} */
    public function read(string $relativePath): array
    {
        $path = $this->resolve($relativePath);
        $maxBytes = (int) config('developer_console.max_source_file_bytes', 131072);
        $size = (int) (filesize($path) ?: 0);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('No fue posible leer el archivo.', 500);
        }
        try {
            $content = fread($handle, $maxBytes + 1);
        } finally {
            fclose($handle);
        }
        if ($content === false) {
            throw new \RuntimeException('No fue posible leer el archivo.', 500);
        }
        $truncated = strlen($content) > $maxBytes;
        if ($truncated) {
            $content = substr($content, 0, $maxBytes);
        }

        return [
            'path' => $this->relativePath($path),
            'content' => $this->redactor->redact($content, false),
            'size' => $size,
            'truncated' => $truncated,
        ];
    }

    /** @param array<int,string> $paths @return array<int,array{path:string,content:string,size:int,truncated:bool}> */
    public function readMany(array $paths): array
    {
        $limit = (int) config('developer_console.max_source_files', 8);
        $unique = array_slice(array_values(array_unique(array_map('strval', $paths))), 0, $limit);
        return array_map(fn (string $path): array => $this->read($path), $unique);
    }

    private function resolve(string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            throw new \InvalidArgumentException('Ruta de archivo inválida.');
        }
        $candidate = realpath(base_path($relativePath));
        if ($candidate === false || !is_file($candidate)) {
            throw new \RuntimeException('Archivo no encontrado.', 404);
        }
        if (!$this->allowedExtension(pathinfo($candidate, PATHINFO_EXTENSION))) {
            throw new \RuntimeException('Tipo de archivo no autorizado.', 403);
        }

        foreach ((array) config('developer_console.source_roots', []) as $root) {
            $rootPath = realpath(base_path((string) $root));
            if ($rootPath !== false && ($candidate === $rootPath || str_starts_with($candidate, $rootPath.DIRECTORY_SEPARATOR))) {
                return $candidate;
            }
        }

        throw new \RuntimeException('La ruta solicitada no está autorizada.', 403);
    }

    private function allowedExtension(string $extension): bool
    {
        return in_array(mb_strtolower($extension), (array) config('developer_console.source_extensions', []), true);
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        return str_replace('\\', '/', str_starts_with($path, $base) ? substr($path, strlen($base)) : $path);
    }
}
