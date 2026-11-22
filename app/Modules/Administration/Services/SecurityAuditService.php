<?php

namespace App\Modules\Administration\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Support\Database\SqlValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SecurityAuditService
{
    public function __construct(private readonly UserContextDTO $context, private readonly Request $request) {}

    public function record(string $targetType, ?string $targetId, string $action, ?string $partnerId, ?string $reason = null, array $metadata = []): void
    {
        DB::table('security_audit_logs')->insert([
            'actor_user_id' => $this->context->userId,
            'partner_id' => $partnerId,
            'target_type' => strtoupper($targetType),
            'target_id' => $targetId,
            'action' => strtoupper($action),
            'reason' => $reason,
            'ip_address' => $this->request->ip(),
            'metadata' => $metadata !== [] ? SqlValue::encode($metadata) : null,
            'created_at' => now(),
        ]);
    }
}
