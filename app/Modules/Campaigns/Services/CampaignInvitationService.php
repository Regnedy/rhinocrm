<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CampaignInvitationService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly CampaignAccessService $access,
        private readonly CampaignActivityService $activity,
    ) {}

    public function inbox(): array
    {
        return DB::table('campaign_invitations as invitation')
            ->join('campaigns as campaign', 'campaign.id', '=', 'invitation.campaign_id')
            ->leftJoin('users as inviter', 'inviter.id', '=', 'invitation.invited_by_user_id')
            ->leftJoin('partners as partner', 'partner.id', '=', 'invitation.campaign_partner_id')
            ->where('invitation.invited_user_id', $this->context->userId)
            ->whereIn('invitation.status', ['PENDING', 'ACCEPTED'])
            ->whereNull('campaign.deleted_at')
            ->select(
                'invitation.*', 'campaign.name as campaign_name', 'campaign.type as campaign_type',
                'campaign.starts_at', 'campaign.ends_at', 'campaign.venue_name', 'campaign.status as campaign_status',
                'inviter.name as inviter_name', 'partner.business_name as organizer_partner_name'
            )
            ->orderByRaw("CASE invitation.status WHEN 'PENDING' THEN 0 ELSE 1 END")
            ->orderBy('campaign.starts_at')
            ->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    public function forCampaign(string $campaignId): array
    {
        $campaign = $this->access->campaign($campaignId);
        if (!$this->access->canManage($campaign) && !$this->context->isSuperAdmin()) return [];

        return DB::table('campaign_invitations as invitation')
            ->leftJoin('users as invited', 'invited.id', '=', 'invitation.invited_user_id')
            ->leftJoin('partners as partner', 'partner.id', '=', 'invitation.invited_partner_id')
            ->where('invitation.campaign_id', $campaignId)
            ->select('invitation.*', 'invited.name as user_name', 'invited.email as user_email', 'partner.business_name as partner_name')
            ->orderByDesc('invitation.created_at')
            ->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    public function invite(string $campaignId, string $email): array
    {
        $campaign = $this->access->campaign($campaignId, true);
        $this->access->assertManage($campaign);
        if (in_array((string) $campaign->status, ['CLOSED', 'CANCELLED'], true)) {
            throw new \RuntimeException('No se pueden enviar invitaciones a un evento cerrado.', 409);
        }

        $normalized = strtolower(trim($email));
        $user = DB::table('users')
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at')
            ->first();
        if ($user === null) throw new \RuntimeException('No existe un usuario activo de Rhino CRM con ese correo.', 404);
        if ((string) $user->id === $this->context->userId) throw new \RuntimeException('El organizador ya pertenece al evento.', 422);
        if ((string) $user->role === 'SUPER_ADMIN') throw new \RuntimeException('El administrador global no se agrega como colaborador.', 422);

        $id = 'EVI_'.strtoupper((string) Str::ulid());
        $existing = DB::table('campaign_invitations')
            ->where('campaign_id', $campaignId)
            ->where('invited_user_id', (string) $user->id)
            ->first();
        if ($existing !== null && (string) $existing->status === 'ACCEPTED') {
            throw new \RuntimeException('Esta persona ya aceptó el evento.', 409);
        }

        DB::transaction(function () use ($existing, $id, $campaign, $user, $normalized): void {
            $values = [
                'campaign_partner_id' => (string) $campaign->partner_id,
                'invited_partner_id' => $user->partner_id,
                'invited_email' => $normalized,
                'invited_name' => $user->name,
                'member_role' => 'COLLABORATOR',
                'status' => 'PENDING',
                'invited_by_user_id' => $this->context->userId,
                'responded_at' => null,
                'accepted_at' => null,
                'declined_at' => null,
                'revoked_at' => null,
                'updated_at' => now(),
            ];
            if ($existing === null) {
                DB::table('campaign_invitations')->insert(array_merge($values, [
                    'id' => $id,
                    'campaign_id' => (string) $campaign->id,
                    'invited_user_id' => (string) $user->id,
                    'created_at' => now(),
                ]));
            } else {
                $id = (string) $existing->id;
                DB::table('campaign_invitations')->where('id', $id)->update($values);
            }
            DB::table('campaign_members')->where('campaign_id', (string) $campaign->id)->where('user_id', (string) $user->id)->update([
                'status' => 'INACTIVE', 'left_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);

        $this->activity->record((string) $campaign->id, (string) $campaign->partner_id, 'INVITATION_SENT', null, [
            'invited_user_id' => (string) $user->id,
            'invited_email' => $normalized,
        ]);
        return $this->forCampaign($campaignId);
    }

    public function respond(string $invitationId, string $response): array
    {
        $response = strtoupper(trim($response));
        if (!in_array($response, ['ACCEPTED', 'DECLINED'], true)) {
            throw new \RuntimeException('Respuesta de invitación no válida.', 422);
        }

        return DB::transaction(function () use ($invitationId, $response): array {
            $invitation = DB::table('campaign_invitations')->where('id', $invitationId)->lockForUpdate()->first();
            if ($invitation === null || (string) $invitation->invited_user_id !== $this->context->userId) {
                throw new \RuntimeException('Invitación no encontrada.', 404);
            }
            if ((string) $invitation->status !== 'PENDING') {
                throw new \RuntimeException('Esta invitación ya fue respondida o revocada.', 409);
            }
            $campaign = DB::table('campaigns')->where('id', $invitation->campaign_id)->whereNull('deleted_at')->first();
            if ($campaign === null || in_array((string) $campaign->status, ['CLOSED', 'CANCELLED'], true)) {
                throw new \RuntimeException('El evento ya no acepta colaboradores.', 409);
            }

            DB::table('campaign_invitations')->where('id', $invitationId)->update([
                'status' => $response,
                'responded_at' => now(),
                'accepted_at' => $response === 'ACCEPTED' ? now() : null,
                'declined_at' => $response === 'DECLINED' ? now() : null,
                'updated_at' => now(),
            ]);

            if ($response === 'ACCEPTED') {
                DB::table('campaign_members')->updateOrInsert(
                    ['campaign_id' => (string) $campaign->id, 'user_id' => $this->context->userId],
                    [
                        'partner_id' => (string) $campaign->partner_id,
                        'member_partner_id' => $invitation->invited_partner_id,
                        'member_role' => 'COLLABORATOR',
                        'invitation_id' => $invitationId,
                        'invited_by_user_id' => $invitation->invited_by_user_id,
                        'status' => 'ACTIVE',
                        'joined_at' => now(),
                        'accepted_at' => now(),
                        'left_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }

            $this->activity->record((string) $campaign->id, (string) $campaign->partner_id, 'INVITATION_'.$response, null, [
                'invitation_id' => $invitationId,
            ]);
            return ['invitation_id' => $invitationId, 'status' => $response, 'campaign_id' => (string) $campaign->id];
        }, 3);
    }

    public function revoke(string $campaignId, string $invitationId): array
    {
        $campaign = $this->access->campaign($campaignId, true);
        $this->access->assertManage($campaign);
        $invitation = DB::table('campaign_invitations')->where('id', $invitationId)->where('campaign_id', $campaignId)->first();
        if ($invitation === null) throw new \RuntimeException('Invitación no encontrada.', 404);

        DB::transaction(function () use ($invitation, $campaignId): void {
            DB::table('campaign_invitations')->where('id', $invitation->id)->update([
                'status' => 'REVOKED', 'revoked_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('campaign_members')->where('campaign_id', $campaignId)->where('user_id', $invitation->invited_user_id)->update([
                'status' => 'INACTIVE', 'left_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
        $this->activity->record($campaignId, (string) $campaign->partner_id, 'INVITATION_REVOKED', null, [
            'invited_user_id' => (string) $invitation->invited_user_id,
        ]);
        return $this->forCampaign($campaignId);
    }
}
