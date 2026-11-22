<?php

namespace App\Modules\Movements\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MovementService
{
    /**
     * @param array<string,mixed> $metadata
     * @return array{id:string,folio:string,movement_code:string,module:string}
     */
    public function record(
        string $movementCode,
        ?string $partnerId,
        string $targetType,
        ?string $targetId = null,
        array $metadata = [],
        ?string $reason = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $originalMovementId = null,
        string $status = 'POSTED',
    ): array {
        $type = DB::table('movement_types')->where('code', $movementCode)->where('active', true)->first();
        if ($type === null) {
            throw new \RuntimeException('Código de movimiento no configurado: '.$movementCode, 422);
        }

        $year = (int) now()->format('Y');

        return DB::transaction(function () use (
            $year,
            $movementCode,
            $partnerId,
            $targetType,
            $targetId,
            $metadata,
            $reason,
            $referenceType,
            $referenceId,
            $originalMovementId,
            $status,
            $type,
        ): array {
            DB::table('movement_sequences')->insertOrIgnore([
                'sequence_year' => $year,
                'last_number' => 0,
                'updated_at' => now(),
            ]);

            $sequence = DB::table('movement_sequences')
                ->where('sequence_year', $year)
                ->lockForUpdate()
                ->first();

            $next = ((int) ($sequence->last_number ?? 0)) + 1;
            DB::table('movement_sequences')->where('sequence_year', $year)->update([
                'last_number' => $next,
                'updated_at' => now(),
            ]);

            $id = 'MOV_'.strtoupper((string) Str::ulid());
            $folio = sprintf('RH-MOV-%d-%06d', $year, $next);
            $context = $this->context();
            $request = app()->bound('request') ? request() : null;
            $channel = $this->accessChannel(
                $request?->header('X-Rhino-Client'),
                $request?->userAgent(),
            );

            DB::table('business_movements')->insert([
                'id' => $id,
                'folio' => $folio,
                'movement_code' => $movementCode,
                'module' => (string) $type->module,
                'partner_id' => $partnerId,
                'actor_user_id' => $context?->userId,
                'target_type' => strtoupper(trim($targetType)),
                'target_id' => $targetId,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'original_movement_id' => $originalMovementId,
                'status' => strtoupper($status),
                'reason' => $reason,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'access_channel' => $channel,
                'metadata' => SqlValue::encode($metadata),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return [
                'id' => $id,
                'folio' => $folio,
                'movement_code' => $movementCode,
                'module' => (string) $type->module,
            ];
        }, 3);
    }

    private function context(): ?UserContextDTO
    {
        return app()->bound(UserContextDTO::class)
            ? app(UserContextDTO::class)
            : null;
    }

    private function accessChannel(?string $explicit, ?string $userAgent): string
    {
        $explicit = strtoupper(trim((string) $explicit));
        if ($explicit !== '') {
            return substr($explicit, 0, 40);
        }

        $ua = strtolower((string) $userAgent);
        if (str_contains($ua, 'okhttp') || str_contains($ua, 'android')) {
            return 'ANDROID_APP';
        }
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad')) {
            return 'IOS_WEB';
        }

        return 'WEB';
    }
}
