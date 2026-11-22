<?php

namespace App\Modules\Mobile\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Support\Database\SqlValue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class KioskUpdateService
{
    public const PACKAGE_NAME = 'com.activa7.rhinocrm';

    public function __construct(private readonly UserContextDTO $context) {}

    public function check(int $currentVersionCode): array
    {
        $latest = DB::table('kiosk_versions')
            ->where('status', 'ACTIVE')
            ->where('version_code', '>', $currentVersionCode)
            ->orderByDesc('version_code')->first();
        if ($latest === null) return ['update_available' => false, 'current_version_code' => $currentVersionCode, 'update' => null];
        $mandatory = DB::table('kiosk_versions')
            ->where('status', 'ACTIVE')->where('release_type', 'MANDATORY')
            ->where('version_code', '>', $currentVersionCode)->exists();
        return [
            'update_available' => true,
            'current_version_code' => $currentVersionCode,
            'update' => $this->versionArray($latest, $mandatory),
        ];
    }

    public function list(): array
    {
        $this->assertAdmin();
        return DB::table('kiosk_versions')->orderByDesc('version_code')->get()->map(fn (object $v) => $this->versionArray($v, (string) $v->release_type === 'MANDATORY'))->all();
    }

    public function publish(array $data, UploadedFile $apk): array
    {
        $this->assertAdmin();
        $versionCode = (int) $data['version_code'];
        if (DB::table('kiosk_versions')->where('version_code', $versionCode)->exists()) throw new \RuntimeException('Ese versionCode ya está registrado.', 409);
        $id = 'KVR_'.strtoupper((string) Str::ulid());
        $path = "kiosk/versions/{$versionCode}/rhino-kiosko-{$versionCode}.apk";
        Storage::disk('local')->put($path, file_get_contents($apk->getRealPath()));
        $stored = Storage::disk('local')->path($path);
        $sha = hash_file('sha256', $stored);
        DB::table('kiosk_versions')->insert([
            'id' => $id,
            'version_name' => trim((string) $data['version_name']),
            'version_code' => $versionCode,
            'package_name' => self::PACKAGE_NAME,
            'apk_path' => $path,
            'apk_sha256' => $sha,
            'signing_sha256' => !empty($data['signing_sha256']) ? strtolower(str_replace(':', '', trim((string) $data['signing_sha256']))) : null,
            'release_type' => strtoupper((string) ($data['release_type'] ?? 'RECOMMENDED')),
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'status' => 'ACTIVE',
            'published_at' => now(),
            'created_by_user_id' => $this->context->userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->versionArray(DB::table('kiosk_versions')->where('id', $id)->first(), (string) ($data['release_type'] ?? '') === 'MANDATORY');
    }

    public function revoke(string $id): array
    {
        $this->assertAdmin();
        $version = DB::table('kiosk_versions')->where('id', $id)->first();
        if ($version === null) throw new \RuntimeException('Versión no encontrada.', 404);
        DB::table('kiosk_versions')->where('id', $id)->update(['status' => 'REVOKED', 'updated_at' => now()]);
        return ['id' => $id, 'status' => 'REVOKED'];
    }

    public function downloadable(string $id): object
    {
        $version = DB::table('kiosk_versions')->where('id', $id)->where('status', 'ACTIVE')->first();
        if ($version === null) throw new \RuntimeException('Versión no disponible.', 404);
        $path = Storage::disk('local')->path((string) $version->apk_path);
        if (!is_file($path)) throw new \RuntimeException('El APK no está disponible en el servidor.', 404);
        if (!hash_equals((string) $version->apk_sha256, hash_file('sha256', $path))) throw new \RuntimeException('La integridad del APK almacenado no es válida.', 409);
        $version->absolute_path = $path;
        return $version;
    }

    public function recordInstallation(array $data): array
    {
        $id = 'KIN_'.strtoupper((string) Str::ulid());
        DB::table('kiosk_installations')->insert([
            'id' => $id,
            'kiosk_version_id' => $data['kiosk_version_id'] ?? null,
            'user_id' => $this->context->userId,
            'partner_id' => $this->context->partnerId,
            'device_fingerprint' => trim((string) ($data['device_fingerprint'] ?? '')) ?: null,
            'installed_version_code' => (int) $data['installed_version_code'],
            'event_type' => strtoupper((string) ($data['event_type'] ?? 'INSTALL_REQUESTED')),
            'app_version_name' => trim((string) ($data['app_version_name'] ?? '')) ?: null,
            'metadata' => SqlValue::encode((array) ($data['metadata'] ?? [])),
            'created_at' => now(),
        ]);
        return ['id' => $id, 'recorded' => true];
    }

    private function versionArray(object $v, bool $mandatory): array
    {
        return [
            'id' => (string) $v->id,
            'version_name' => (string) $v->version_name,
            'version_code' => (int) $v->version_code,
            'package_name' => (string) $v->package_name,
            'sha256' => (string) $v->apk_sha256,
            'signing_sha256' => $v->signing_sha256,
            'release_type' => (string) $v->release_type,
            'mandatory' => $mandatory,
            'notes' => $v->notes,
            'status' => (string) $v->status,
            'published_at' => $v->published_at,
            'download_path' => '/api/v1/kiosk/update/download/'.rawurlencode((string) $v->id),
        ];
    }

    private function assertAdmin(): void
    {
        if (!$this->context->isSuperAdmin()) throw new \RuntimeException('Solo SUPER ADMIN puede administrar versiones Kiosko.', 403);
    }
}
