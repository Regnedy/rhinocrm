<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Devices\Services\PersonalDeviceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CampaignQrJoinService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly CampaignAccessService $access,
        private readonly CampaignActivityService $activity,
        private readonly PersonalDeviceService $personalDevices,
    ) {}

    public function create(string $campaignId, int $ttlMinutes = 480, int $maxUses = 100): array
    {
        $campaign = $this->access->campaign($campaignId, true);
        $this->access->assertManage($campaign);
        if (in_array((string) $campaign->status, ['CLOSED', 'CANCELLED'], true)) {
            throw new \RuntimeException('El evento ya no acepta colaboradores.', 409);
        }

        $ttlMinutes = min(max($ttlMinutes, 15), 1440);
        $maxUses = min(max($maxUses, 1), 500);
        $plainToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $plainToken);
        $id = 'EJT_'.strtoupper((string) Str::ulid());
        $expiresAt = now()->addMinutes($ttlMinutes);
        if ($campaign->ends_at !== null && now()->parse((string) $campaign->ends_at)->lt($expiresAt)) {
            $expiresAt = now()->parse((string) $campaign->ends_at);
        }
        if ($expiresAt->lte(now())) throw new \RuntimeException('El evento ya terminó.', 409);

        // Un QR nuevo revoca los anteriores para que el líder pueda cortar circulación no deseada.
        DB::table('campaign_join_tokens')->where('campaign_id', $campaignId)->whereNull('revoked_at')->update([
            'revoked_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('campaign_join_tokens')->insert([
            'id' => $id,
            'campaign_id' => $campaignId,
            'campaign_partner_id' => (string) $campaign->partner_id,
            'token_hash' => $tokenHash,
            'created_by_user_id' => $this->context->userId,
            'uses_count' => 0,
            'max_uses' => $maxUses,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->activity->record($campaignId, (string) $campaign->partner_id, 'PARTY_QR_CREATED', null, [
            'join_token_id' => $id, 'expires_at' => $expiresAt->toAtomString(), 'max_uses' => $maxUses,
        ]);

        return [
            'campaign_id' => $campaignId,
            'token' => $plainToken,
            'join_uri' => 'rhino://event/join?token='.rawurlencode($plainToken),
            'expires_at' => $expiresAt->toAtomString(),
            'max_uses' => $maxUses,
        ];
    }

    public function preview(string $plainToken): array
    {
        [$token, $campaign] = $this->resolve($plainToken, false);
        return [
            'campaign_id' => (string) $campaign->id,
            'name' => (string) $campaign->name,
            'type' => (string) $campaign->type,
            'venue_name' => $campaign->venue_name,
            'starts_at' => $campaign->starts_at,
            'ends_at' => $campaign->ends_at,
            'status' => (string) $campaign->status,
            'organizer_partner_name' => (string) (DB::table('partners')->where('id', $campaign->partner_id)->value('business_name') ?? 'RHINO'),
            'organizer_name' => (string) (DB::table('users')->where('id', $campaign->created_by_user_id)->value('name') ?? 'Organizador RHINO'),
            'my_devices' => $this->personalDevices->mine(),
            'expires_at' => $token->expires_at,
        ];
    }

    public function accept(string $plainToken, array $personalDeviceIds = []): array
    {
        return DB::transaction(function () use ($plainToken, $personalDeviceIds): array {
            [$token, $campaign] = $this->resolve($plainToken, true);
            $user = DB::table('users')->where('id', $this->context->userId)->where('status', 'ACTIVE')->whereNull('deleted_at')->first();
            if ($user === null) throw new \RuntimeException('Usuario no disponible para unirse al evento.', 403);
            if ((string) $user->role === 'SUPER_ADMIN') throw new \RuntimeException('El administrador global no se agrega al Party.', 422);

            $existingMember = DB::table('campaign_members')->where('campaign_id', $campaign->id)->where('user_id', $user->id)->first();
            if ($existingMember !== null && (string) $existingMember->status === 'ACTIVE') {
                $devices = $personalDeviceIds !== [] ? $this->personalDevices->shareForCampaign((string) $campaign->id, $personalDeviceIds) : $this->personalDevices->forCampaign((string) $campaign->id);
                return ['campaign_id' => (string) $campaign->id, 'status' => 'ACTIVE', 'already_joined' => true, 'available_devices' => $devices];
            }

            $invitationId = 'EVI_'.strtoupper((string) Str::ulid());
            $existingInvitation = DB::table('campaign_invitations')->where('campaign_id', $campaign->id)->where('invited_user_id', $user->id)->first();
            $values = [
                'campaign_partner_id' => (string) $campaign->partner_id,
                'invited_partner_id' => $user->partner_id,
                'invited_email' => (string) $user->email,
                'invited_name' => (string) $user->name,
                'member_role' => 'COLLABORATOR',
                'source' => 'QR',
                'status' => 'ACCEPTED',
                'invited_by_user_id' => (string) $token->created_by_user_id,
                'responded_at' => now(),
                'accepted_at' => now(),
                'declined_at' => null,
                'revoked_at' => null,
                'updated_at' => now(),
            ];
            if ($existingInvitation === null) {
                DB::table('campaign_invitations')->insert(array_merge($values, [
                    'id' => $invitationId,
                    'campaign_id' => (string) $campaign->id,
                    'invited_user_id' => (string) $user->id,
                    'created_at' => now(),
                ]));
            } else {
                $invitationId = (string) $existingInvitation->id;
                DB::table('campaign_invitations')->where('id', $invitationId)->update($values);
            }

            DB::table('campaign_members')->updateOrInsert(
                ['campaign_id' => (string) $campaign->id, 'user_id' => (string) $user->id],
                [
                    'partner_id' => (string) $campaign->partner_id,
                    'member_partner_id' => $user->partner_id,
                    'member_role' => 'COLLABORATOR',
                    'invitation_id' => $invitationId,
                    'invited_by_user_id' => (string) $token->created_by_user_id,
                    'status' => 'ACTIVE',
                    'joined_at' => now(),
                    'accepted_at' => now(),
                    'left_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            DB::table('campaign_join_tokens')->where('id', $token->id)->update([
                'uses_count' => DB::raw('uses_count + 1'), 'updated_at' => now(),
            ]);
            $devices = $personalDeviceIds !== []
                ? $this->personalDevices->shareForCampaign((string) $campaign->id, $personalDeviceIds)
                : $this->personalDevices->forCampaign((string) $campaign->id);
            $this->activity->record((string) $campaign->id, (string) $campaign->partner_id, 'PARTY_JOINED_BY_QR', null, [
                'user_id' => (string) $user->id, 'member_partner_id' => $user->partner_id,
                'personal_device_ids' => array_values($personalDeviceIds),
            ]);
            return ['campaign_id' => (string) $campaign->id, 'status' => 'ACTIVE', 'already_joined' => false, 'available_devices' => $devices];
        }, 3);
    }

    public function revoke(string $campaignId): array
    {
        $campaign = $this->access->campaign($campaignId, true);
        $this->access->assertManage($campaign);
        $count = DB::table('campaign_join_tokens')->where('campaign_id', $campaignId)->whereNull('revoked_at')->update([
            'revoked_at' => now(), 'updated_at' => now(),
        ]);
        $this->activity->record($campaignId, (string) $campaign->partner_id, 'PARTY_QR_REVOKED', null, ['count' => $count]);
        return ['campaign_id' => $campaignId, 'revoked' => $count];
    }

    private function resolve(string $plainToken, bool $lock): array
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '' || strlen($plainToken) > 200) throw new \RuntimeException('Código QR no válido.', 422);
        $query = DB::table('campaign_join_tokens')->where('token_hash', hash('sha256', $plainToken));
        if ($lock) $query->lockForUpdate();
        $token = $query->first();
        if ($token === null || $token->revoked_at !== null) throw new \RuntimeException('Este QR ya no está activo.', 404);
        if (now()->greaterThanOrEqualTo($token->expires_at)) throw new \RuntimeException('Este QR expiró.', 409);
        if ((int) $token->uses_count >= (int) $token->max_uses) throw new \RuntimeException('Este QR alcanzó su límite de accesos.', 409);
        $campaign = DB::table('campaigns')->where('id', $token->campaign_id)->whereNull('deleted_at')->first();
        if ($campaign === null || in_array((string) $campaign->status, ['CLOSED', 'CANCELLED'], true)) {
            throw new \RuntimeException('El evento ya no acepta colaboradores.', 409);
        }
        return [$token, $campaign];
    }
}
