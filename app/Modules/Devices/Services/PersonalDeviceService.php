<?php

namespace App\Modules\Devices\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Campaigns\Services\CampaignAccessService;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PersonalDeviceService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly CampaignAccessService $campaignAccess,
    ) {}

    public function mine(): array
    {
        return DB::table('personal_devices')
            ->where('owner_user_id', $this->context->userId)
            ->orderByRaw("CASE status WHEN 'IN_EVENT' THEN 0 WHEN 'AVAILABLE' THEN 1 ELSE 2 END")
            ->orderBy('device_type')
            ->orderBy('display_name')
            ->get()
            ->map(fn (object $row): array => $this->deviceArray($row))
            ->all();
    }

    public function store(array $data): array
    {
        $partnerId = $this->actualPartnerId();
        $type = strtoupper((string) $data['device_type']);
        $hardware = $this->normalizeHardware((string) ($data['hardware_identifier'] ?? ''));
        $linkedDeviceId = null;
        if ($type === 'SCALE' && $hardware !== '') {
            $linked = DB::table('devices')
                ->whereNull('deleted_at')
                ->where(function ($query) use ($hardware): void {
                    $query->whereRaw('UPPER(mac_address) = ?', [$hardware])
                        ->orWhereRaw('UPPER(id) = ?', [$hardware]);
                })
                ->first();
            if ($linked !== null) $linkedDeviceId = (string) $linked->id;
        }

        $existing = DB::table('personal_devices')
            ->where('owner_user_id', $this->context->userId)
            ->where('device_type', $type)
            ->when($hardware !== '', fn ($q) => $q->whereRaw('UPPER(hardware_identifier) = ?', [$hardware]))
            ->first();
        if ($existing !== null) {
            DB::table('personal_devices')->where('id', $existing->id)->update([
                'display_name' => trim((string) ($data['display_name'] ?? $existing->display_name)),
                'model' => trim((string) ($data['model'] ?? $existing->model ?? '')) ?: null,
                'linked_device_id' => $linkedDeviceId ?? $existing->linked_device_id,
                'last_validated_at' => now(),
                'updated_at' => now(),
            ]);
            return $this->deviceArray(DB::table('personal_devices')->where('id', $existing->id)->first());
        }

        $id = 'PDV_'.strtoupper((string) Str::ulid());
        DB::table('personal_devices')->insert([
            'id' => $id,
            'owner_user_id' => $this->context->userId,
            'owner_partner_id' => $partnerId,
            'device_type' => $type,
            'linked_device_id' => $linkedDeviceId,
            'display_name' => trim((string) ($data['display_name'] ?? '')) ?: ($type === 'SCALE' ? 'Báscula Rhino' : 'Impresora Tickets'),
            'hardware_identifier' => $hardware !== '' ? $hardware : null,
            'model' => trim((string) ($data['model'] ?? '')) ?: null,
            'status' => 'AVAILABLE',
            'metadata' => SqlValue::encode((array) ($data['metadata'] ?? [])),
            'last_validated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $this->deviceArray(DB::table('personal_devices')->where('id', $id)->first());
    }

    public function remove(string $id): array
    {
        $device = DB::table('personal_devices')->where('id', $id)->where('owner_user_id', $this->context->userId)->first();
        if ($device === null) throw new \RuntimeException('Dispositivo personal no encontrado.', 404);
        if (DB::table('campaign_device_availability')->where('personal_device_id', $id)->where('status', 'ACTIVE')->exists()) {
            throw new \RuntimeException('Finaliza primero el evento donde este dispositivo está disponible.', 409);
        }
        DB::table('personal_devices')->where('id', $id)->update(['status' => 'REMOVED', 'updated_at' => now()]);
        return ['id' => $id, 'status' => 'REMOVED'];
    }

    public function forCampaign(string $campaignId): array
    {
        $this->campaignAccess->campaign($campaignId);
        return DB::table('campaign_device_availability as a')
            ->join('personal_devices as d', 'd.id', '=', 'a.personal_device_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.owner_user_id')
            ->where('a.campaign_id', $campaignId)
            ->where('a.status', 'ACTIVE')
            ->select('a.*', 'd.device_type', 'd.display_name', 'd.hardware_identifier', 'd.model', 'd.linked_device_id', 'u.name as owner_name')
            ->orderBy('u.name')->orderBy('d.device_type')->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'personal_device_id' => (string) $row->personal_device_id,
                'device_type' => (string) $row->device_type,
                'display_name' => (string) $row->display_name,
                'hardware_identifier' => $row->hardware_identifier,
                'model' => $row->model,
                'linked_device_id' => $row->linked_device_id,
                'owner_user_id' => (string) $row->owner_user_id,
                'owner_name' => $row->owner_name ?: 'Socio RHINO',
                'status' => 'AVAILABLE_IN_EVENT',
                'available_at' => $row->available_at,
            ])->all();
    }

    public function shareForCampaign(string $campaignId, array $personalDeviceIds): array
    {
        $campaign = $this->campaignAccess->campaign($campaignId, true);
        if ((string) $campaign->status !== 'ACTIVE') throw new \RuntimeException('Sólo se pueden compartir dispositivos en un evento activo.', 409);
        $ids = array_values(array_unique(array_filter(array_map('strval', $personalDeviceIds))));
        foreach ($ids as $id) {
            $device = DB::table('personal_devices')->where('id', $id)->where('owner_user_id', $this->context->userId)->lockForUpdate()->first();
            if ($device === null || (string) $device->status === 'REMOVED') throw new \RuntimeException('Uno de los dispositivos seleccionados no te pertenece.', 422);
            $conflict = DB::table('campaign_device_availability as a')
                ->join('campaigns as c', 'c.id', '=', 'a.campaign_id')
                ->where('a.personal_device_id', $id)->where('a.status', 'ACTIVE')
                ->where('a.campaign_id', '<>', $campaignId)->where('c.status', 'ACTIVE')->whereNull('c.deleted_at')->value('c.name');
            if ($conflict !== null) throw new \RuntimeException("Este dispositivo ya está disponible en el evento {$conflict}.", 409);
            DB::table('campaign_device_availability')->updateOrInsert(
                ['campaign_id' => $campaignId, 'personal_device_id' => $id],
                [
                    'owner_user_id' => $this->context->userId,
                    'owner_partner_id' => $device->owner_partner_id,
                    'status' => 'ACTIVE',
                    'available_at' => now(),
                    'released_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            DB::table('personal_devices')->where('id', $id)->update(['status' => 'IN_EVENT', 'updated_at' => now()]);
        }
        return $this->forCampaign($campaignId);
    }

    public function releaseCampaign(string $campaignId): void
    {
        $ids = DB::table('campaign_device_availability')->where('campaign_id', $campaignId)->where('status', 'ACTIVE')->pluck('personal_device_id');
        DB::table('campaign_device_availability')->where('campaign_id', $campaignId)->where('status', 'ACTIVE')->update([
            'status' => 'RELEASED', 'released_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($ids as $id) {
            $stillUsed = DB::table('campaign_device_availability as a')
                ->join('campaigns as c', 'c.id', '=', 'a.campaign_id')
                ->where('a.personal_device_id', $id)->where('a.status', 'ACTIVE')->where('c.status', 'ACTIVE')->exists();
            if (!$stillUsed) DB::table('personal_devices')->where('id', $id)->update(['status' => 'AVAILABLE', 'updated_at' => now()]);
        }
    }

    private function actualPartnerId(): ?string
    {
        $partner = DB::table('users')->where('id', $this->context->userId)->whereNull('deleted_at')->value('partner_id');
        return $partner !== null ? (string) $partner : $this->context->partnerId;
    }

    private function normalizeHardware(string $value): string
    {
        $value = strtoupper(trim($value));
        if (preg_match('/^[0-9A-F]{12}$/', str_replace(':', '', $value)) === 1) {
            $hex = str_replace(':', '', $value);
            return implode(':', str_split($hex, 2));
        }
        return $value;
    }

    private function deviceArray(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'device_type' => (string) $row->device_type,
            'display_name' => (string) $row->display_name,
            'hardware_identifier' => $row->hardware_identifier,
            'model' => $row->model,
            'status' => (string) $row->status,
            'linked_device_id' => $row->linked_device_id,
            'owner_user_id' => (string) $row->owner_user_id,
            'owner_partner_id' => $row->owner_partner_id,
            'last_validated_at' => $row->last_validated_at,
        ];
    }
}
