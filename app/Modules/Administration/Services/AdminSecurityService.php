<?php

namespace App\Modules\Administration\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\Auth\Services\ApiSessionService;
use App\Modules\Auth\Services\HybridIdentityService;
use App\Modules\IoT\Services\DeviceSecretCipher;
use App\Modules\Movements\Services\MovementService;
use Illuminate\Support\Facades\DB;

final class AdminSecurityService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly ApiSessionService $sessions,
        private readonly HybridIdentityService $identities,
        private readonly DeviceSecretCipher $deviceSecrets,
        private readonly SecurityAuditService $audit,
        private readonly MovementService $movements,
    ) {}

    public function partners(): array
    {
        $this->requireSuperAdmin();

        $primary = static function (string $column): \Closure {
            return static fn ($q) => $q->from('users as pu')
                ->select('pu.'.$column)
                ->whereColumn('pu.partner_id', 'p.id')
                ->where('pu.role', 'PARTNER_ADMIN')
                ->whereNull('pu.deleted_at')
                ->orderByDesc('pu.is_primary_partner_admin')
                ->orderByRaw("CASE WHEN pu.status = 'ACTIVE' THEN 0 ELSE 1 END")
                ->orderBy('pu.created_at')
                ->limit(1);
        };

        $rows = DB::table('partners as p')
            ->leftJoin('plans as pl', 'pl.id', '=', 'p.plan_id')
            ->leftJoin('partner_ranks as pr', 'pr.id', '=', 'p.current_rank_id')
            ->leftJoin('partners as sp', 'sp.id', '=', 'p.sponsor_partner_id')
            ->whereNull('p.deleted_at')
            ->select(
                'p.*',
                'pl.name as plan_name',
                'pr.name as rank_name',
                'pr.code as rank_code',
                'sp.partner_number as sponsor_partner_number',
                'sp.business_name as sponsor_partner_name',
            )
            ->selectSub(fn ($q) => $q->from('users')->selectRaw('COUNT(*)')->whereColumn('partner_id', 'p.id')->whereNull('deleted_at'), 'users_count')
            ->selectSub(fn ($q) => $q->from('devices')->selectRaw('COUNT(*)')->whereColumn('partner_id', 'p.id')->whereNull('deleted_at'), 'devices_count')
            ->selectSub($primary('id'), 'primary_admin_id')
            ->selectSub($primary('name'), 'primary_admin_name')
            ->selectSub($primary('email'), 'primary_admin_email')
            ->selectSub($primary('status'), 'primary_admin_status')
            ->selectSub($primary('auth_provider'), 'primary_admin_auth_provider')
            ->selectSub($primary('firebase_uid'), 'primary_admin_firebase_uid')
            ->selectSub($primary('firebase_email_verified_at'), 'primary_admin_firebase_email_verified_at')
            ->selectSub($primary('firebase_verification_sent_at'), 'primary_admin_firebase_verification_sent_at')
            ->selectSub($primary('firebase_verification_last_error'), 'primary_admin_firebase_verification_last_error')
            ->selectSub($primary('last_login_at'), 'primary_admin_last_login_at')
            ->orderBy('p.business_name')
            ->get();

        return $rows->map(function (object $row): object {
            if ($row->primary_admin_id === null) {
                $row->primary_access_status = 'MISSING';
                $row->firebase_verification_status = 'MISSING';
                return $row;
            }

            $provider = strtoupper((string) ($row->primary_admin_auth_provider ?? 'LOCAL'));
            $row->primary_access_status = (string) ($row->primary_admin_status ?? 'ACTIVE');
            $row->firebase_verification_status = $provider === 'LOCAL'
                ? 'NOT_APPLICABLE'
                : ($row->primary_admin_firebase_email_verified_at !== null
                    ? 'VERIFIED'
                    : (!empty($row->primary_admin_firebase_uid) ? 'PENDING' : 'MISSING'));

            return $row;
        })->all();
    }

    public function suspendPartner(string $partnerId, string $reason): array
    {
        $this->requireSuperAdmin();
        $partner = $this->partner($partnerId);

        $movement = DB::transaction(function () use ($partnerId, $reason, $partner): array {
            DB::table('partners')->where('id', $partnerId)->lockForUpdate()->first();
            DB::table('partners')->where('id', $partnerId)->update([
                'status' => 'SUSPENDED',
                'suspended_reason' => $reason,
                'suspended_at' => now(),
                'suspended_by' => $this->context->userId,
                'updated_at' => now(),
            ]);
            $this->sessions->revokeForPartner(
                $partnerId,
                $this->context->userId,
                'Socio suspendido: '.$reason,
            );
            $this->audit->record(
                'PARTNER',
                $partnerId,
                'SUSPEND',
                $partnerId,
                $reason,
                ['business_name' => $partner->business_name],
            );

            return $this->movements->record(
                '901',
                $partnerId,
                'PARTNER',
                $partnerId,
                [
                    'business_name' => $partner->business_name,
                    'partner_number' => $partner->partner_number ?? null,
                ],
                $reason,
            );
        }, 3);

        return ['partner_id' => $partnerId, 'status' => 'SUSPENDED', 'movement' => $movement];
    }

    public function activatePartner(string $partnerId): array
    {
        $this->requireSuperAdmin();
        $partner = $this->partner($partnerId);

        $movement = DB::transaction(function () use ($partnerId, $partner): array {
            DB::table('partners')->where('id', $partnerId)->lockForUpdate()->first();
            DB::table('partners')->where('id', $partnerId)->update([
                'status' => 'ACTIVE',
                'suspended_reason' => null,
                'suspended_at' => null,
                'suspended_by' => null,
                'updated_at' => now(),
            ]);
            $this->audit->record(
                'PARTNER',
                $partnerId,
                'ACTIVATE',
                $partnerId,
                null,
                ['business_name' => $partner->business_name],
            );

            return $this->movements->record(
                '902',
                $partnerId,
                'PARTNER',
                $partnerId,
                [
                    'business_name' => $partner->business_name,
                    'partner_number' => $partner->partner_number ?? null,
                ],
            );
        }, 3);

        return ['partner_id' => $partnerId, 'status' => 'ACTIVE', 'movement' => $movement];
    }

    public function users(?string $partnerId = null): array
    {
        $scope = $this->authorizedPartner($partnerId);
        return DB::table('users')->where('partner_id', $scope)->whereNull('deleted_at')
            ->select('id', 'partner_id', 'location_id', 'role', 'name', 'email', 'auth_provider', 'status', 'last_login_at', 'created_at')
            ->orderBy('name')->get()->all();
    }

    public function suspendUser(string $userId, string $reason): array
    {
        $user = $this->authorizedUser($userId);
        DB::transaction(function () use ($user, $reason): void {
            DB::table('users')->where('id', $user->id)->update([
                'status' => 'SUSPENDED', 'suspended_at' => now(), 'suspended_by' => $this->context->userId,
                'suspension_reason' => $reason, 'updated_at' => now(),
            ]);
            $this->sessions->revokeForUser((string) $user->id, $this->context->userId, $reason);
        }, 3);
        if (!empty($user->firebase_uid)) {
            $this->identities->suspendFirebase((string) $user->firebase_uid);
        }
        $this->audit->record('USER', (string) $user->id, 'SUSPEND', (string) $user->partner_id, $reason);
        return ['user_id' => $user->id, 'status' => 'SUSPENDED'];
    }

    public function activateUser(string $userId): array
    {
        $user = $this->authorizedUser($userId);
        DB::table('users')->where('id', $user->id)->update([
            'status' => 'ACTIVE', 'suspended_at' => null, 'suspended_by' => null,
            'suspension_reason' => null, 'updated_at' => now(),
        ]);
        if (!empty($user->firebase_uid)) {
            $this->identities->activateFirebase((string) $user->firebase_uid);
        }
        $this->audit->record('USER', (string) $user->id, 'ACTIVATE', (string) $user->partner_id);
        return ['user_id' => $user->id, 'status' => 'ACTIVE'];
    }

    public function revokeUserSessions(string $userId, string $reason): array
    {
        $user = $this->authorizedUser($userId);
        $count = $this->sessions->revokeForUser($userId, $this->context->userId, $reason);
        if (!empty($user->firebase_uid)) {
            $this->identities->suspendFirebase((string) $user->firebase_uid);
            $this->identities->activateFirebase((string) $user->firebase_uid);
        }
        $this->audit->record('USER', $userId, 'REVOKE_SESSIONS', (string) $user->partner_id, $reason, ['count' => $count]);
        return ['user_id' => $userId, 'revoked_sessions' => $count];
    }

    public function sessionList(?string $partnerId = null, ?string $userId = null): array
    {
        $scope = $this->authorizedPartner($partnerId);
        $query = DB::table('api_sessions as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.partner_id', $scope)->select('s.*', 'u.name as user_name', 'u.email as user_email');
        if ($userId !== null && $userId !== '') {
            $this->authorizedUser($userId);
            $query->where('s.user_id', $userId);
        }
        return $query->orderByDesc('s.last_seen_at')->limit(500)->get()->all();
    }

    public function revokeSession(string $sessionId, string $reason): array
    {
        $session = DB::table('api_sessions')->where('id', $sessionId)->first();
        if ($session === null) throw new \RuntimeException('Sesión no encontrada.', 404);
        $this->assertPartnerAccess($session->partner_id !== null ? (string) $session->partner_id : null);
        DB::table('api_sessions')->where('id', $sessionId)->whereNull('revoked_at')->update([
            'revoked_at' => now(), 'revoked_by' => $this->context->userId,
            'revocation_reason' => $reason, 'updated_at' => now(),
        ]);
        $this->audit->record('SESSION', $sessionId, 'REVOKE', $session->partner_id, $reason, ['user_id' => $session->user_id]);
        return ['session_id' => $sessionId, 'status' => 'REVOKED'];
    }

    public function devices(?string $partnerId = null): array
    {
        $scope = $this->authorizedPartner($partnerId);
        return DB::table('devices as d')->leftJoin('locations as l', 'l.id', '=', 'd.location_id')
            ->where('d.partner_id', $scope)->whereNull('d.deleted_at')
            ->select('d.id', 'd.mac_address', 'd.partner_id', 'd.location_id', 'l.name as location_name', 'd.status', 'd.total_measurements', 'd.last_measurement_at', 'd.created_at')
            ->orderByDesc('d.created_at')->get()->all();
    }

    public function deviceOverview(?string $partnerId = null): array
    {
        $scope = $this->authorizedPartner($partnerId);
        $sessions = DB::table('api_sessions as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.partner_id', $scope)
            ->select(
                's.id',
                's.user_id',
                'u.name as user_name',
                'u.email as user_email',
                's.device_name',
                's.device_fingerprint_hash',
                's.platform',
                's.app_version',
                's.ip_address',
                's.user_agent',
                's.last_seen_at',
                's.expires_at',
                's.revoked_at',
                's.created_at',
            )
            ->orderByDesc('s.last_seen_at')
            ->limit(1000)
            ->get();

        $groups = [];
        foreach ($sessions as $session) {
            $identity = trim((string) ($session->device_fingerprint_hash ?? ''));
            if ($identity === '') {
                $identity = hash('sha256', implode('|', [
                    (string) $session->user_id,
                    (string) $session->platform,
                    (string) $session->device_name,
                    (string) $session->user_agent,
                ]));
            }
            $key = 'ACCESS-'.substr($identity, 0, 24);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'id' => $key,
                    'device_type' => 'ACCESS',
                    'device_name' => $session->device_name ?: $this->browserLabel((string) $session->user_agent),
                    'platform' => (string) $session->platform,
                    'app_version' => $session->app_version !== null ? (string) $session->app_version : null,
                    'browser' => $this->browserLabel((string) $session->user_agent),
                    'operating_system' => $this->operatingSystem((string) $session->user_agent),
                    'latest_ip' => $session->ip_address !== null ? (string) $session->ip_address : null,
                    'first_seen_at' => $session->created_at,
                    'last_seen_at' => $session->last_seen_at,
                    'sessions_count' => 0,
                    'active_sessions' => 0,
                    'users' => [],
                    'status' => 'INACTIVE',
                ];
            }
            $groups[$key]['sessions_count']++;
            $active = $session->revoked_at === null && now()->lessThan($session->expires_at);
            if ($active) {
                $groups[$key]['active_sessions']++;
                $groups[$key]['status'] = 'ACTIVE';
            }
            $groups[$key]['latest_ip'] = $groups[$key]['latest_ip'] ?? $session->ip_address;
            $groups[$key]['first_seen_at'] = min(
                strtotime((string) $groups[$key]['first_seen_at']),
                strtotime((string) $session->created_at),
            ) === strtotime((string) $session->created_at)
                ? $session->created_at
                : $groups[$key]['first_seen_at'];
            if ($session->last_seen_at !== null && (
                $groups[$key]['last_seen_at'] === null
                || strtotime((string) $session->last_seen_at) > strtotime((string) $groups[$key]['last_seen_at'])
            )) {
                $groups[$key]['last_seen_at'] = $session->last_seen_at;
                $groups[$key]['latest_ip'] = $session->ip_address;
            }
            $groups[$key]['users'][(string) $session->user_id] = [
                'id' => (string) $session->user_id,
                'name' => (string) $session->user_name,
                'email' => (string) $session->user_email,
            ];
        }

        $accessDevices = array_values(array_map(function (array $group): array {
            $group['users'] = array_values($group['users']);
            return $group;
        }, $groups));
        usort($accessDevices, fn (array $a, array $b): int => strcmp((string) ($b['last_seen_at'] ?? ''), (string) ($a['last_seen_at'] ?? '')));

        return [
            'access_devices' => $accessDevices,
            'scales' => $this->devices($scope),
        ];
    }

    private function browserLabel(string $userAgent): string
    {
        $ua = strtolower($userAgent);
        return match (true) {
            str_contains($ua, 'edg/') => 'Microsoft Edge',
            str_contains($ua, 'chrome/') && !str_contains($ua, 'edg/') => 'Google Chrome',
            str_contains($ua, 'firefox/') => 'Mozilla Firefox',
            str_contains($ua, 'safari/') && !str_contains($ua, 'chrome/') => 'Safari',
            str_contains($ua, 'okhttp') => 'Aplicación Android',
            $userAgent === '' => 'Dispositivo no identificado',
            default => 'Navegador o aplicación',
        };
    }

    private function operatingSystem(string $userAgent): string
    {
        $ua = strtolower($userAgent);
        return match (true) {
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'iphone') || str_contains($ua, 'ipad') => 'iOS/iPadOS',
            str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') => 'macOS',
            str_contains($ua, 'linux') => 'Linux',
            default => 'No identificado',
        };
    }

    public function suspendDevice(string $deviceId, string $reason): array
    {
        $device = $this->authorizedDevice($deviceId);
        DB::table('devices')->where('id', $deviceId)->update([
            'status' => 'SUSPENDED', 'suspended_at' => now(), 'suspended_by' => $this->context->userId,
            'suspension_reason' => $reason, 'updated_at' => now(),
        ]);
        $this->audit->record('DEVICE', $deviceId, 'SUSPEND', (string) $device->partner_id, $reason, ['mac' => $device->mac_address]);
        $movement = $this->movements->record('803', (string) $device->partner_id, 'DEVICE', $deviceId, ['mac_address' => $device->mac_address], $reason);
        return ['device_id' => $deviceId, 'status' => 'SUSPENDED', 'movement' => $movement];
    }

    public function activateDevice(string $deviceId): array
    {
        $device = $this->authorizedDevice($deviceId);
        DB::table('devices')->where('id', $deviceId)->update([
            'status' => 'ACTIVE', 'suspended_at' => null, 'suspended_by' => null,
            'suspension_reason' => null, 'revoked_at' => null, 'updated_at' => now(),
        ]);
        $this->audit->record('DEVICE', $deviceId, 'ACTIVATE', (string) $device->partner_id);
        $movement = $this->movements->record('802', (string) $device->partner_id, 'DEVICE', $deviceId, ['mac_address' => $device->mac_address]);
        return ['device_id' => $deviceId, 'status' => 'ACTIVE', 'movement' => $movement];
    }

    public function revokeDevice(string $deviceId, string $reason): array
    {
        $device = $this->authorizedDevice($deviceId);
        DB::table('devices')->where('id', $deviceId)->update([
            'status' => 'REVOKED', 'revoked_at' => now(), 'suspension_reason' => $reason,
            'suspended_by' => $this->context->userId, 'updated_at' => now(),
        ]);
        $this->audit->record('DEVICE', $deviceId, 'REVOKE', (string) $device->partner_id, $reason);
        $movement = $this->movements->record('803', (string) $device->partner_id, 'DEVICE', $deviceId, ['mac_address' => $device->mac_address], $reason);
        return ['device_id' => $deviceId, 'status' => 'REVOKED', 'movement' => $movement];
    }

    public function rotateDeviceSecret(string $deviceId): array
    {
        $device = $this->authorizedDevice($deviceId);
        $secret = bin2hex(random_bytes(32));
        DB::table('devices')->where('id', $deviceId)->update(['device_token' => $this->deviceSecrets->encrypt($secret), 'updated_at' => now()]);
        $this->audit->record('DEVICE', $deviceId, 'ROTATE_SECRET', (string) $device->partner_id);
        return ['device_id' => $deviceId, 'device_secret' => $secret];
    }

    public function blockContact(string $contactId, string $reason): array
    {
        $contact = $this->authorizedContact($contactId);
        DB::table('contacts')->where('id', $contactId)->update([
            'status' => 'BLOCKED', 'blocked_at' => now(), 'blocked_by' => $this->context->userId,
            'block_reason' => $reason, 'updated_at' => now(),
        ]);
        DB::table('tasks')->where('contact_id', $contactId)->where('status', 'PENDING')->update(['status' => 'CANCELED', 'canceled_at' => now(), 'updated_at' => now()]);
        $this->audit->record('CONTACT', $contactId, 'BLOCK', (string) $contact->partner_id, $reason);
        return ['contact_id' => $contactId, 'status' => 'BLOCKED'];
    }

    public function activateContact(string $contactId): array
    {
        $contact = $this->authorizedContact($contactId);
        DB::table('contacts')->where('id', $contactId)->update([
            'status' => 'ACTIVE', 'blocked_at' => null, 'blocked_by' => null, 'block_reason' => null, 'updated_at' => now(),
        ]);
        $this->audit->record('CONTACT', $contactId, 'ACTIVATE', (string) $contact->partner_id);
        return ['contact_id' => $contactId, 'status' => 'ACTIVE'];
    }

    public function auditLogs(?string $partnerId = null): array
    {
        $scope = $this->context->isSuperAdmin() ? $partnerId : $this->context->partnerId;
        $query = DB::table('security_audit_logs')->orderByDesc('created_at');
        if ($scope !== null && $scope !== '') $query->where('partner_id', $scope);
        elseif (!$this->context->isSuperAdmin()) throw new \RuntimeException('Contexto sin socio.', 403);
        return $query->limit(500)->get()->all();
    }

    private function requireSuperAdmin(): void { if (!$this->context->isSuperAdmin()) throw new \RuntimeException('Solo SUPER_ADMIN puede realizar esta operación.', 403); }
    private function partner(string $id): object { return DB::table('partners')->where('id', $id)->whereNull('deleted_at')->first() ?? throw new \RuntimeException('Socio no encontrado.', 404); }
    private function authorizedPartner(?string $requested): string
    {
        if ($this->context->isSuperAdmin()) {
            if ($requested === null || $requested === '') throw new \InvalidArgumentException('partner_id es obligatorio para SUPER_ADMIN.');
            $this->partner($requested); return $requested;
        }
        $partnerId = $this->context->partnerId ?? throw new \RuntimeException('Contexto sin socio.', 403);
        if ($requested !== null && $requested !== '' && $requested !== $partnerId) throw new TenantViolationException($this->context->userId, $requested);
        return $partnerId;
    }
    private function assertPartnerAccess(?string $partnerId): void
    {
        if ($this->context->isSuperAdmin()) return;
        if ($partnerId === null || $partnerId !== $this->context->partnerId) throw new TenantViolationException($this->context->userId, (string) $partnerId);
    }
    private function authorizedUser(string $id): object
    {
        $row = DB::table('users')->where('id', $id)->whereNull('deleted_at')->first() ?? throw new \RuntimeException('Usuario no encontrado.', 404);
        $this->assertPartnerAccess($row->partner_id !== null ? (string) $row->partner_id : null);
        if ((string) $row->role === 'SUPER_ADMIN' && !$this->context->isSuperAdmin()) throw new \RuntimeException('No puedes modificar al administrador global.', 403);
        return $row;
    }
    private function authorizedDevice(string $id): object
    {
        $row = DB::table('devices')->where('id', $id)->whereNull('deleted_at')->first() ?? throw new \RuntimeException('Báscula no encontrada.', 404);
        $this->assertPartnerAccess((string) $row->partner_id); return $row;
    }
    private function authorizedContact(string $id): object
    {
        $row = DB::table('contacts')->where('id', $id)->whereNull('deleted_at')->first() ?? throw new \RuntimeException('Cliente no encontrado.', 404);
        $this->assertPartnerAccess((string) $row->partner_id); return $row;
    }
}
