<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\Movements\Services\MovementService;
use Illuminate\Support\Facades\DB;

final class InventoryAdjustmentService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly MovementService $movements,
    ) {}

    /** @return array{transaction_id:string,quantity:int,already_processed:bool} */
    public function adjust(array $data, string $idempotencyKey): array
    {
        $partnerId = $this->context->partnerId ?? throw new \RuntimeException('Contexto sin partner.', 403);
        if (trim($idempotencyKey) === '') {
            throw new \InvalidArgumentException('X-Idempotency-Key es obligatorio.');
        }

        $locationId = trim((string) $data['location_id']);
        $productId = trim((string) $data['product_id']);
        $change = (int) $data['quantity_change'];
        $keyHash = hash('sha256', $partnerId.'|inventory|'.$idempotencyKey);
        $transactionId = 'INV_ADJ_'.strtoupper(substr($keyHash, 0, 22));

        $result = DB::transaction(function () use ($partnerId, $locationId, $productId, $change, $data, $keyHash, $transactionId): array {
            $processed = DB::table('command_idempotency')->where('key_hash', $keyHash)->lockForUpdate()->first();
            $balance = DB::table('inventory_balances')
                ->where('partner_id', $partnerId)
                ->where('location_id', $locationId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($processed !== null) {
                return [
                    'transaction_id' => (string) ($processed->resource_id ?? $transactionId),
                    'quantity' => (int) ($balance->quantity ?? 0),
                    'already_processed' => true,
                ];
            }

            $location = DB::table('locations')->where('id', $locationId)->whereNull('deleted_at')->first();
            $product = DB::table('products')->where('id', $productId)->whereNull('deleted_at')->first();
            if ($location === null || (string) $location->partner_id !== $partnerId) {
                throw new TenantViolationException($this->context->userId, $partnerId);
            }
            if ((string) $location->status !== 'ACTIVE') {
                throw new \RuntimeException('La sucursal está suspendida.', 403);
            }
            if ($product === null || (string) $product->partner_id !== $partnerId) {
                throw new TenantViolationException($this->context->userId, $partnerId);
            }
            if (!(bool) $product->active) {
                throw new \RuntimeException('El producto está inactivo.', 409);
            }

            $newQuantity = (int) ($balance->quantity ?? 0) + $change;
            if ($newQuantity < 0) {
                throw new \RuntimeException('El ajuste dejaría inventario negativo.', 409);
            }

            DB::table('inventory_balances')->updateOrInsert(
                ['partner_id' => $partnerId, 'location_id' => $locationId, 'product_id' => $productId],
                ['quantity' => $newQuantity, 'created_at' => $balance?->created_at ?? now(), 'updated_at' => now()],
            );
            DB::table('inventory_transactions')->insert([
                'id' => $transactionId,
                'partner_id' => $partnerId,
                'location_id' => $locationId,
                'product_id' => $productId,
                'transaction_type' => 'MANUAL_ADJUSTMENT',
                'quantity_change' => $change,
                'reference_id' => $data['reference_id'] ?? null,
                'reason' => (string) $data['reason'],
                'actor_id' => $this->context->userId,
                'created_at' => now(),
            ]);
            DB::table('command_idempotency')->insert([
                'key_hash' => $keyHash,
                'partner_id' => $partnerId,
                'type' => 'INVENTORY_ADJUSTMENT',
                'resource_id' => $transactionId,
                'command_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
                'created_at' => now(),
            ]);

            return ['transaction_id' => $transactionId, 'quantity' => $newQuantity, 'already_processed' => false];
        }, 3);

        if (!$result['already_processed']) {
            $code = $change > 0 ? '101' : '201';
            $result['movement'] = $this->movements->record(
                $code,
                $partnerId,
                'INVENTORY_TRANSACTION',
                $result['transaction_id'],
                [
                    'location_id' => $locationId,
                    'product_id' => $productId,
                    'quantity_change' => $change,
                    'balance_after' => $result['quantity'],
                    'reference_id' => $data['reference_id'] ?? null,
                ],
                (string) $data['reason'],
            );
        }

        return $result;
    }
}
