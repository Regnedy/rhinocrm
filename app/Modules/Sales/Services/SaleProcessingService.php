<?php

namespace App\Modules\Sales\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\Movements\Services\MovementService;
use App\Modules\Sales\Contracts\ProductRepositoryInterface;
use App\Modules\Sales\Contracts\SaleRepositoryInterface;
use App\Modules\Sales\Domain\Entities\Sale;
use App\Modules\Sales\Domain\Entities\SaleItem;
use App\Support\Database\SqlValue;
use App\Support\Database\TeamAccessScope;
use Illuminate\Support\Facades\DB;

final class SaleProcessingService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly SaleRepositoryInterface $sales,
        private readonly UserContextDTO $context,
        private readonly MovementService $movements,
    ) {}

    public function processSale(string $contactId, string $locationId, string $paymentMethod, array $cart, string $idempotencyKey): Sale
    {
        $partnerId = $this->context->partnerId ?? throw new \RuntimeException('Contexto sin partner.', 403);
        if (trim($idempotencyKey) === '') {
            throw new \InvalidArgumentException('X-Idempotency-Key es obligatorio.');
        }
        if (trim($locationId) === '') {
            throw new \InvalidArgumentException('location_id es obligatorio.');
        }

        $cart = $this->aggregateCart($cart);
        $catalog = $this->products->findMany(array_keys($cart));
        if (count($catalog) !== count($cart)) {
            throw new \RuntimeException('Uno o más productos no existen.', 422);
        }

        $keyHash = hash('sha256', $partnerId.'|sale|'.$idempotencyKey);
        $saleId = 'SAL_'.strtoupper(substr($keyHash, 0, 22));
        $items = [];
        $currency = null;
        $revenue = 0;
        $profit = 0;
        foreach ($cart as $productId => $quantity) {
            $product = $catalog[$productId];
            if (!$product->isActive() || $product->getPartnerId() !== $partnerId) {
                throw new \RuntimeException('Producto inválido.', 422);
            }
            $currency ??= $product->getCurrency();
            if ($currency !== $product->getCurrency()) {
                throw new \RuntimeException('No se pueden mezclar monedas.', 422);
            }
            $item = SaleItem::createFromProduct(
                $saleId,
                'SLI_'.strtoupper(substr(hash('sha256', $saleId.'|'.$productId), 0, 20)),
                $product,
                $quantity,
            );
            $items[] = $item;
            $revenue += $item->subtotalRevenueMinor;
            $profit += $item->subtotalProfitMinor;
        }

        DB::transaction(function () use ($partnerId, $contactId, $locationId, $paymentMethod, $idempotencyKey, $keyHash, $saleId, $items, $currency, $revenue, $profit): void {
            $processed = DB::table('command_idempotency')->where('key_hash', $keyHash)->lockForUpdate()->first();
            if ($processed !== null || DB::table('sales')->where('id', $saleId)->exists()) {
                return;
            }

            $contact = DB::table('contacts')->where('id', $contactId)->whereNull('deleted_at')->lockForUpdate()->first();
            $location = DB::table('locations')->where('id', $locationId)->whereNull('deleted_at')->first();
            if ($contact === null) {
                throw new \RuntimeException('Contacto no encontrado.', 404);
            }
            if ((string) $contact->partner_id !== $partnerId) {
                throw new TenantViolationException($this->context->userId, (string) $contact->partner_id);
            }
            if ((string) $contact->status !== 'ACTIVE') {
                throw new \RuntimeException('El cliente está bloqueado.', 403);
            }
            if ($this->context->role === 'ADVISOR' && (string) ($contact->assigned_advisor_id ?? '') !== $this->context->userId) {
                throw new \RuntimeException('El contacto no está asignado a este asesor.', 403);
            }
            $teamScope = new TeamAccessScope($this->context);
            if (!$teamScope->canAccessContact($contact)) {
                throw new \RuntimeException('El contacto está fuera del equipo autorizado.', 403);
            }
            if ($location === null) {
                throw new \RuntimeException('Sucursal no encontrada.', 404);
            }
            if ((string) $location->partner_id !== $partnerId) {
                throw new TenantViolationException($this->context->userId, (string) $location->partner_id);
            }
            if ((string) $location->status !== 'ACTIVE') {
                throw new \RuntimeException('La sucursal está suspendida.', 403);
            }

            $teamId = $teamScope->primaryTeamForUser($this->context->userId);
            if ($teamId === null && $this->context->role === 'TEAM_LEADER') {
                $teamId = $teamScope->teamIds()[0] ?? null;
            }
            $source = SqlValue::json($contact->contact_source ?? null);
            $originCampaignId = $contact->origin_campaign_id !== null ? (string) $contact->origin_campaign_id : DB::table('campaign_contacts')->where('contact_id', $contactId)->where('partner_id', $partnerId)->orderByDesc('last_seen_at')->value('campaign_id');
            $originCampaignId = $originCampaignId !== null ? (string) $originCampaignId : null;
            $saleType = (string) $contact->lifecycle_stage === 'CUSTOMER' ? 'RECURRING' : 'ACQUISITION';
            $closedAt = now();

            foreach ($items as $item) {
                $balance = DB::table('inventory_balances')
                    ->where('partner_id', $partnerId)
                    ->where('location_id', $locationId)
                    ->where('product_id', $item->productId)
                    ->lockForUpdate()
                    ->first();
                if ((int) ($balance->quantity ?? 0) < $item->quantity) {
                    throw new \RuntimeException("Stock insuficiente para {$item->productNameSnapshot}.", 409);
                }
            }

            DB::table('sales')->insert([
                'id' => $saleId,
                'partner_id' => $partnerId,
                'contact_id' => $contactId,
                'advisor_id' => $this->context->userId,
                'team_id' => $teamId,
                'location_id' => $locationId,
                'sale_type' => $saleType,
                'status' => 'COMPLETED',
                'payment_method' => $paymentMethod,
                'total_revenue_minor' => $revenue,
                'total_profit_minor' => $profit,
                'currency_snapshot' => $currency ?? 'MXN',
                'origin_device_id' => $source['origin_device_id'] ?? null,
                'origin_campaign_id' => $originCampaignId,
                'idempotency_key' => $idempotencyKey,
                'closed_at' => $closedAt,
                'created_at' => $closedAt,
                'updated_at' => $closedAt,
            ]);

            foreach ($items as $item) {
                DB::table('sale_items')->insert([
                    'id' => $item->id,
                    'sale_id' => $saleId,
                    'partner_id' => $partnerId,
                    'product_id' => $item->productId,
                    'quantity' => $item->quantity,
                    'product_name_snapshot' => $item->productNameSnapshot,
                    'unit_price_minor_snapshot' => $item->unitPriceMinorSnapshot,
                    'unit_cost_minor_snapshot' => $item->unitCostMinorSnapshot,
                    'subtotal_revenue_minor' => $item->subtotalRevenueMinor,
                    'subtotal_profit_minor' => $item->subtotalProfitMinor,
                ]);
                DB::table('inventory_balances')
                    ->where('partner_id', $partnerId)
                    ->where('location_id', $locationId)
                    ->where('product_id', $item->productId)
                    ->decrement('quantity', $item->quantity, ['updated_at' => now()]);
                DB::table('inventory_transactions')->insert([
                    'id' => 'INV_'.$item->id,
                    'partner_id' => $partnerId,
                    'location_id' => $locationId,
                    'product_id' => $item->productId,
                    'transaction_type' => 'SALE_OUT',
                    'quantity_change' => -$item->quantity,
                    'reference_id' => $saleId,
                    'reason' => 'Venta completada',
                    'actor_id' => $this->context->userId,
                    'created_at' => now(),
                ]);
            }

            DB::table('financial_transactions')->insert([
                'id' => 'FIN_'.$saleId,
                'partner_id' => $partnerId,
                'sale_id' => $saleId,
                'type' => 'SALE_REVENUE',
                'amount_minor' => $revenue,
                'profit_minor' => $profit,
                'currency' => $currency ?? 'MXN',
                'created_at' => now(),
            ]);

            $eventId = 'EVT_SALE_'.$saleId;
            DB::table('event_outbox')->insert([
                'id' => $eventId,
                'event_type' => 'SALE_COMPLETED',
                'event_version' => 1,
                'partner_id' => $partnerId,
                'payload' => SqlValue::encode([
                    'sale_id' => $saleId,
                    'contact_id' => $contactId,
                    'advisor_id' => $this->context->userId,
                    'origin_device_id' => $source['origin_device_id'] ?? null,
                    'origin_campaign_id' => $originCampaignId,
                    'sale_type' => $saleType,
                    'total_revenue_minor' => $revenue,
                    'total_profit_minor' => $profit,
                    'currency' => $currency ?? 'MXN',
                    'closed_at' => $closedAt->toAtomString(),
                    'items' => array_map(fn (SaleItem $item): array => [
                        'product_id' => $item->productId,
                        'quantity' => $item->quantity,
                        'revenue_minor' => $item->subtotalRevenueMinor,
                        'profit_minor' => $item->subtotalProfitMinor,
                    ], $items),
                ]),
                'status' => 'PENDING', 'attempt_count' => 0, 'available_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('command_idempotency')->insert([
                'key_hash' => $keyHash,
                'partner_id' => $partnerId,
                'type' => 'CREATE_SALE',
                'resource_id' => $saleId,
                'event_id' => $eventId,
                'created_at' => now(),
            ]);
        }, 3);

        if (!DB::table('business_movements')
            ->where('movement_code', '401')
            ->where('target_type', 'SALE')
            ->where('target_id', $saleId)
            ->exists()) {
            $this->movements->record(
                '401',
                $partnerId,
                'SALE',
                $saleId,
                [
                    'contact_id' => $contactId,
                    'location_id' => $locationId,
                    'payment_method' => $paymentMethod,
                    'total_revenue_minor' => $revenue,
                    'total_profit_minor' => $profit,
                    'currency' => $currency ?? 'MXN',
                    'item_count' => count($items),
                ],
                'Venta completada',
                'SALE',
                $saleId,
            );
        }

        return $this->sales->find($saleId) ?? throw new \RuntimeException('Venta no disponible después del commit.');
    }

    public function cancelSale(string $saleId, string $reason): void
    {
        $sale = $this->sales->find($saleId) ?? throw new \RuntimeException('Venta no encontrada.', 404);
        $items = $this->sales->findItems($saleId);
        $keyHash = hash('sha256', $sale->getPartnerId().'|refund|'.$saleId);

        DB::transaction(function () use ($sale, $items, $saleId, $reason, $keyHash): void {
            if (DB::table('command_idempotency')->where('key_hash', $keyHash)->lockForUpdate()->exists()) {
                return;
            }
            $row = DB::table('sales')->where('id', $saleId)->lockForUpdate()->first();
            if ($row === null) {
                throw new \RuntimeException('Venta no encontrada.', 404);
            }
            if ((string) $row->partner_id !== $this->context->partnerId) {
                throw new TenantViolationException($this->context->userId, (string) $row->partner_id);
            }
            if ((string) $row->status !== 'COMPLETED') {
                throw new \RuntimeException('La venta no puede reembolsarse.', 409);
            }

            DB::table('sales')->where('id', $saleId)->update([
                'status' => 'REFUNDED', 'refund_reason' => $reason, 'refunded_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($items as $item) {
                $balance = DB::table('inventory_balances')
                    ->where('partner_id', $sale->getPartnerId())
                    ->where('location_id', $sale->getLocationId())
                    ->where('product_id', $item->productId)
                    ->lockForUpdate()->first();
                DB::table('inventory_balances')->updateOrInsert(
                    ['partner_id' => $sale->getPartnerId(), 'location_id' => $sale->getLocationId(), 'product_id' => $item->productId],
                    ['quantity' => (int) ($balance->quantity ?? 0) + $item->quantity, 'created_at' => $balance?->created_at ?? now(), 'updated_at' => now()],
                );
                DB::table('inventory_transactions')->insertOrIgnore([
                    'id' => 'INV_REF_'.$item->id,
                    'partner_id' => $sale->getPartnerId(),
                    'location_id' => $sale->getLocationId(),
                    'product_id' => $item->productId,
                    'transaction_type' => 'REFUND_IN',
                    'quantity_change' => $item->quantity,
                    'reference_id' => $saleId,
                    'reason' => $reason,
                    'actor_id' => $this->context->userId,
                    'created_at' => now(),
                ]);
            }
            DB::table('financial_transactions')->insertOrIgnore([
                'id' => 'FIN_REF_'.$saleId,
                'partner_id' => $sale->getPartnerId(),
                'sale_id' => $saleId,
                'type' => 'REFUND_OUTFLOW',
                'amount_minor' => -$sale->getTotalRevenueMinor(),
                'profit_minor' => -$sale->getTotalProfitMinor(),
                'currency' => $sale->getCurrencySnapshot(),
                'created_at' => now(),
            ]);

            $eventId = 'EVT_REFUND_'.$saleId;
            DB::table('event_outbox')->insertOrIgnore([
                'id' => $eventId,
                'event_type' => 'SALE_REFUNDED',
                'event_version' => 1,
                'partner_id' => $sale->getPartnerId(),
                'payload' => SqlValue::encode([
                    'sale_id' => $saleId,
                    'contact_id' => $sale->getContactId(),
                    'advisor_id' => $sale->getAdvisorId(),
                    'origin_device_id' => $sale->getOriginDeviceId(),
                    'total_revenue_minor' => $sale->getTotalRevenueMinor(),
                    'total_profit_minor' => $sale->getTotalProfitMinor(),
                    'currency' => $sale->getCurrencySnapshot(),
                    'refunded_at' => now()->toAtomString(),
                    'items' => array_map(fn (SaleItem $item): array => [
                        'product_id' => $item->productId,
                        'quantity' => $item->quantity,
                        'revenue_minor' => $item->subtotalRevenueMinor,
                        'profit_minor' => $item->subtotalProfitMinor,
                    ], $items),
                ]),
                'status' => 'PENDING', 'attempt_count' => 0, 'available_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('command_idempotency')->insert([
                'key_hash' => $keyHash,
                'partner_id' => $sale->getPartnerId(),
                'type' => 'REFUND_SALE',
                'resource_id' => $saleId,
                'event_id' => $eventId,
                'created_at' => now(),
            ]);
        }, 3);

        if (!DB::table('business_movements')
            ->where('movement_code', '402')
            ->where('target_type', 'SALE')
            ->where('target_id', $saleId)
            ->exists()) {
            $this->movements->record(
                '402',
                $sale->getPartnerId(),
                'SALE',
                $saleId,
                [
                    'contact_id' => $sale->getContactId(),
                    'location_id' => $sale->getLocationId(),
                    'total_revenue_minor' => $sale->getTotalRevenueMinor(),
                    'total_profit_minor' => $sale->getTotalProfitMinor(),
                    'currency' => $sale->getCurrencySnapshot(),
                ],
                $reason,
                'SALE',
                $saleId,
            );
        }
    }

    /** @return array<string,int> */
    private function aggregateCart(array $cart): array
    {
        $aggregated = [];
        foreach ($cart as $row) {
            $productId = trim((string) ($row['product_id'] ?? ''));
            $quantity = (int) ($row['quantity'] ?? 0);
            if ($productId === '' || $quantity < 1) {
                throw new \InvalidArgumentException('Carrito inválido.');
            }
            $aggregated[$productId] = ($aggregated[$productId] ?? 0) + $quantity;
            if ($aggregated[$productId] > 1000) {
                throw new \InvalidArgumentException('La cantidad acumulada de un producto supera el máximo permitido.');
            }
        }
        if ($aggregated === []) {
            throw new \InvalidArgumentException('El carrito no puede estar vacío.');
        }
        return $aggregated;
    }
}
