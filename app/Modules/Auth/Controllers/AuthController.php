<?php

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class AuthController
{
    use ApiResponse;

    public function me(UserContextDTO $context): JsonResponse
    {
        $user = DB::table('users')->where('id', $context->userId)->first(['name', 'email', 'status']);
        $partner = $context->partnerId !== null
            ? DB::table('partners as p')
                ->leftJoin('partner_ranks as r', 'r.id', '=', 'p.current_rank_id')
                ->where('p.id', $context->partnerId)
                ->select('p.partner_number', 'p.business_name', 'p.sponsor_partner_id', 'r.name as rank_name')
                ->first()
            : null;

        return $this->successResponse([
            'user_id' => $context->userId,
            'partner_id' => $context->partnerId,
            'partner_number' => $partner?->partner_number,
            'partner_name' => $partner?->business_name,
            'partner_rank_name' => $partner?->rank_name,
            'sponsor_partner_id' => $partner?->sponsor_partner_id,
            'location_id' => $context->locationId,
            'role' => $context->role,
            'permissions' => $context->permissions,
            'name' => $user?->name,
            'email' => $user?->email,
            'status' => $user?->status,
        ]);
    }
}
