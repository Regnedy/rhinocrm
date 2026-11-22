<?php

namespace App\Modules\Partners\Services;

use Illuminate\Support\Facades\DB;

final class PartnerCredentialVerificationService
{
    public function verify(string $publicCode): array
    {
        $credential = DB::table('partner_credentials as c')
            ->join('partners as p', 'p.id', '=', 'c.partner_id')
            ->leftJoin('partner_ranks as r', 'r.id', '=', 'p.current_rank_id')
            ->where('c.public_code', $publicCode)
            ->whereNull('p.deleted_at')
            ->select(
                'c.*',
                'p.partner_number as current_partner_number',
                'p.business_name as current_partner_name',
                'p.status as partner_status',
                'r.name as current_rank_name',
            )
            ->first();

        if ($credential === null) {
            throw new \RuntimeException('Credencial no encontrada.', 404);
        }

        $expired = $credential->expires_at !== null && now()->greaterThanOrEqualTo($credential->expires_at);
        $valid = (string) $credential->status === 'ACTIVE'
            && (string) $credential->partner_status === 'ACTIVE'
            && !$expired;

        return [
            'valid' => $valid,
            'credential_number' => (string) $credential->credential_number,
            'credential_status' => $expired ? 'EXPIRED' : (string) $credential->status,
            'partner_status' => (string) $credential->partner_status,
            'partner_number' => (string) $credential->current_partner_number,
            'partner_name' => (string) $credential->current_partner_name,
            'rank_name' => $credential->current_rank_name !== null ? (string) $credential->current_rank_name : null,
            'issued_at' => $credential->issued_at,
            'expires_at' => $credential->expires_at,
        ];
    }
}
