<?php

namespace App\Modules\Partners\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Services\UserAdminService;
use App\Modules\Movements\Services\MovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PartnerProvisioningService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly MovementService $movements,
        private readonly UserAdminService $users,
    ) {}

    public function create(array $data): array
    {
        if (!$this->context->isSuperAdmin()) {
            throw new \RuntimeException('Solo SUPER_ADMIN puede crear socios.', 403);
        }
        if (!DB::table('plans')->where('id', $data['plan_id'])->where('active', true)->exists()) {
            throw new \RuntimeException('Plan no encontrado.', 422);
        }

        $partnerNumber = trim((string) $data['partner_number']);
        if (DB::table('partners')->where('partner_number', $partnerNumber)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Ese Socio ID ya está registrado.', 409);
        }

        $adminEmail = mb_strtolower(trim((string) $data['admin_email']));
        if (DB::table('users')->whereRaw('LOWER(email) = ?', [$adminEmail])->exists()) {
            $owner = DB::table('users as u')
                ->leftJoin('partners as p', 'p.id', '=', 'u.partner_id')
                ->whereRaw('LOWER(u.email) = ?', [$adminEmail])
                ->select('p.business_name')
                ->first();
            $suffix = $owner?->business_name ? ' y pertenece a '.$owner->business_name : '';
            throw new \RuntimeException('El correo del administrador ya está registrado'.$suffix.'.', 409);
        }

        $sponsorId = isset($data['sponsor_partner_id']) && trim((string) $data['sponsor_partner_id']) !== ''
            ? trim((string) $data['sponsor_partner_id'])
            : null;
        if ($sponsorId !== null && !DB::table('partners')->where('id', $sponsorId)->where('status', 'ACTIVE')->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('El patrocinador no existe o está inactivo.', 422);
        }

        $rankId = isset($data['current_rank_id']) && trim((string) $data['current_rank_id']) !== ''
            ? trim((string) $data['current_rank_id'])
            : (string) DB::table('partner_ranks')->where('code', 'AFILIADO')->where('active', true)->value('id');
        if ($rankId === '' || !DB::table('partner_ranks')->where('id', $rankId)->where('active', true)->exists()) {
            throw new \RuntimeException('Rango inicial no válido.', 422);
        }

        $partnerId = 'PAR_'.strtoupper((string) Str::ulid());
        $locationId = 'LOC_'.strtoupper((string) Str::ulid());
        $dial = match (strtoupper((string) $data['country'])) {
            'MX', 'MEXICO', 'MÉXICO' => '52',
            'US', 'USA' => '1',
            default => (string) ($data['country_dial_code'] ?? '52'),
        };

        $movement = null;
        $primaryAccess = null;

        try {
            DB::transaction(function () use (
                $data,
                $partnerId,
                $partnerNumber,
                $locationId,
                $dial,
                $sponsorId,
                $rankId,
                &$movement,
                &$primaryAccess,
            ): void {
                DB::table('partners')->insert([
                    'id' => $partnerId,
                    'partner_number' => $partnerNumber,
                    'plan_id' => $data['plan_id'],
                    'sponsor_partner_id' => $sponsorId,
                    'current_rank_id' => $rankId,
                    'business_name' => trim((string) $data['business_name']),
                    'country' => $data['country'],
                    'country_dial_code' => $dial,
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('locations')->insert([
                    'id' => $locationId,
                    'partner_id' => $partnerId,
                    'name' => trim((string) ($data['location_name'] ?? 'Principal')) ?: 'Principal',
                    'city' => isset($data['city']) && trim((string) $data['city']) !== '' ? trim((string) $data['city']) : null,
                    'state' => isset($data['state']) && trim((string) $data['state']) !== '' ? trim((string) $data['state']) : null,
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                foreach (['teams', 'team_goals', 'campaigns', 'advanced_reports', 'mobile_sessions'] as $feature) {
                    DB::table('feature_flags')->insertOrIgnore([
                        'partner_id' => $partnerId,
                        'feature' => $feature,
                        'enabled' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                DB::table('feature_flags')->updateOrInsert(
                    ['partner_id' => $partnerId, 'feature' => 'team_mode'],
                    [
                        'enabled' => false,
                        'configuration' => json_encode(['mode' => 'INDIVIDUAL'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                $movement = $this->movements->record(
                    '541',
                    $partnerId,
                    'PARTNER',
                    $partnerId,
                    [
                        'partner_number' => $partnerNumber,
                        'business_name' => $data['business_name'],
                        'sponsor_partner_id' => $sponsorId,
                        'rank_id' => $rankId,
                    ],
                );

                DB::table('partner_rank_history')->insert([
                    'id' => 'PRH_'.strtoupper((string) Str::ulid()),
                    'partner_id' => $partnerId,
                    'previous_rank_id' => null,
                    'rank_id' => $rankId,
                    'movement_id' => $movement['id'],
                    'changed_by_user_id' => $this->context->userId,
                    'reason' => 'Rango inicial del socio.',
                    'effective_at' => now(),
                    'created_at' => now(),
                ]);

                if ($sponsorId !== null) {
                    $sponsorMovement = $this->movements->record(
                        '542',
                        $partnerId,
                        'PARTNER',
                        $partnerId,
                        ['sponsor_partner_id' => $sponsorId],
                        'Patrocinador asignado durante el registro.',
                    );
                    DB::table('partner_relationship_history')->insert([
                        'id' => 'PRL_'.strtoupper((string) Str::ulid()),
                        'partner_id' => $partnerId,
                        'previous_sponsor_partner_id' => null,
                        'sponsor_partner_id' => $sponsorId,
                        'movement_id' => $sponsorMovement['id'],
                        'changed_by_user_id' => $this->context->userId,
                        'reason' => 'Patrocinador asignado durante el registro.',
                        'effective_at' => now(),
                        'created_at' => now(),
                    ]);
                }

                // La cuenta principal se crea dentro de la misma transacción de MySQL.
                // Si Firebase o MySQL fallan, el socio no queda registrado a medias.
                $primaryAccess = $this->users->createPrimaryPartnerAdmin([
                    'name' => trim((string) $data['admin_name']),
                    'email' => mb_strtolower(trim((string) $data['admin_email'])),
                    'password' => (string) $data['admin_password'],
                    'role' => 'PARTNER_ADMIN',
                ], $partnerId, $locationId);
            }, 3);
        } catch (\Throwable $exception) {
            if (is_array($primaryAccess) && isset($primaryAccess['identity'])) {
                $this->users->discardIdentity($primaryAccess['identity']);
            }
            throw $exception;
        }

        $verification = $this->users->sendVerificationForUser($primaryAccess['user']->getId());

        return [
            'partner_id' => $partnerId,
            'partner_number' => $partnerNumber,
            'location_id' => $locationId,
            'movement' => $movement,
            'primary_access' => [
                'user_id' => $primaryAccess['user']->getId(),
                'name' => $primaryAccess['user']->getName(),
                'email' => $primaryAccess['user']->getEmail(),
                'verification' => $verification,
            ],
        ];
    }
}
