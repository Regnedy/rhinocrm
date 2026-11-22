<?php

namespace App\Modules\CRM\Repositories;

use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\CRM\Contracts\ContactRepositoryInterface;
use App\Modules\CRM\Domain\Entities\Contact;
use App\Modules\CRM\Repositories\Mappers\ContactMapper;
use App\Support\Database\GuardsTenant;
use App\Support\Database\TeamAccessScope;
use Illuminate\Support\Facades\DB;

final class SqlContactRepository implements ContactRepositoryInterface
{
    use GuardsTenant;

    public function __construct(private readonly UserContextDTO $context) {}

    public function find(string $id): ?Contact
    {
        $row = DB::table('contacts')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $this->guardTenant((string) $row->partner_id);
        if ($this->context->role === 'ADVISOR' && $row->assigned_advisor_id !== $this->context->userId) {
            throw new TenantViolationException($this->context->userId, (string) $row->partner_id);
        }
        if (!(new TeamAccessScope($this->context))->canAccessContact($row)) {
            throw new TenantViolationException($this->context->userId, (string) $row->partner_id);
        }

        return ContactMapper::fromRow($row);
    }

    public function findByWhatsappAndPartner(string $whatsapp, string $partnerId): ?Contact
    {
        $this->guardTenant($partnerId);
        $row = DB::table('contacts')
            ->where('partner_id', $partnerId)
            ->where('whatsapp_normalized', $whatsapp)
            ->whereNull('deleted_at')
            ->first();
        return $row ? ContactMapper::fromRow($row) : null;
    }

    public function save(Contact $contact): void
    {
        $this->guardTenant($contact->getPartnerId());
        DB::table('contacts')->updateOrInsert(['id' => $contact->getId()], ContactMapper::toRow($contact));
    }

    public function getPipeline(?string $salesStage = null, ?string $assignedAdvisorId = null, int $limit = 100): array
    {
        $partnerId = $this->context->partnerId;
        if ($partnerId === null) {
            throw new \RuntimeException('Para consultar el pipeline se requiere partner_id.', 403);
        }
        $query = DB::table('contacts')
            ->where('partner_id', $partnerId)
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at');

        if ($salesStage !== null && $salesStage !== '') {
            $query->where('sales_stage', $salesStage);
        }
        if ($this->context->role === 'ADVISOR') {
            $query->where('assigned_advisor_id', $this->context->userId);
        } elseif ($this->context->role === 'TEAM_LEADER') {
            (new TeamAccessScope($this->context))->applyToContacts($query);
            if ($assignedAdvisorId !== null && $assignedAdvisorId !== '') {
                $query->where('assigned_advisor_id', $assignedAdvisorId);
            }
        } elseif ($assignedAdvisorId !== null && $assignedAdvisorId !== '') {
            $query->where('assigned_advisor_id', $assignedAdvisorId);
        }

        return $query->orderByDesc('updated_at')
            ->limit(min(max($limit, 1), 200))
            ->get()
            ->map(fn (object $row): Contact => ContactMapper::fromRow($row))
            ->all();
    }
}
