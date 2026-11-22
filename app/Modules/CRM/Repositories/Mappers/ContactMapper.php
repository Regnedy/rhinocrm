<?php

namespace App\Modules\CRM\Repositories\Mappers;

use App\Modules\CRM\Domain\Entities\Contact;
use App\Support\Database\SqlValue;
use DateTimeImmutable;

final class ContactMapper
{
    public static function fromRow(object|array $row): Contact
    {
        $data = is_array($row) ? $row : get_object_vars($row);

        return new Contact(
            id: (string) $data['id'],
            partnerId: (string) $data['partner_id'],
            whatsapp: (string) ($data['whatsapp_normalized'] ?? $data['whatsapp'] ?? ''),
            name: (string) $data['name'],
            age: isset($data['age']) ? (int) $data['age'] : null,
            gender: isset($data['gender']) ? (string) $data['gender'] : null,
            heightCm: isset($data['height_cm']) ? (float) $data['height_cm'] : null,
            profile: isset($data['profile']) ? (string) $data['profile'] : null,
            lifecycleStage: (string) ($data['lifecycle_stage'] ?? 'CONTACT'),
            salesStage: (string) ($data['sales_stage'] ?? 'NEW'),
            contactSource: SqlValue::json($data['contact_source'] ?? null),
            assignedAdvisorId: isset($data['assigned_advisor_id']) ? (string) $data['assigned_advisor_id'] : null,
            lossReason: isset($data['loss_reason']) ? (string) $data['loss_reason'] : null,
            metricsSummary: SqlValue::json($data['metrics_summary'] ?? null),
            totalMeasurements: (int) ($data['total_measurements'] ?? 0),
            lastMeasurementAt: SqlValue::date($data['last_measurement_at'] ?? null),
            createdAt: SqlValue::date($data['created_at'] ?? null, new DateTimeImmutable()) ?? new DateTimeImmutable(),
            updatedAt: SqlValue::date($data['updated_at'] ?? null),
            deletedAt: SqlValue::date($data['deleted_at'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public static function toRow(Contact $contact): array
    {
        return [
            'partner_id' => $contact->getPartnerId(),
            'whatsapp' => $contact->getWhatsapp(),
            'whatsapp_normalized' => $contact->getWhatsapp(),
            'name' => $contact->getName(),
            'age' => $contact->getAge(),
            'gender' => $contact->getGender(),
            'height_cm' => $contact->getHeightCm(),
            'profile' => $contact->getProfile(),
            'lifecycle_stage' => $contact->getLifecycleStage(),
            'sales_stage' => $contact->getSalesStage(),
            'assigned_advisor_id' => $contact->getAssignedAdvisorId(),
            'loss_reason' => $contact->getLossReason(),
            'contact_source' => SqlValue::encode($contact->getContactSource()),
            'metrics_summary' => SqlValue::encode($contact->getMetricsSummary()),
            'total_measurements' => $contact->getTotalMeasurements(),
            'last_measurement_at' => $contact->getLastMeasurementAt()?->format('Y-m-d H:i:s'),
            'created_at' => $contact->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $contact->getUpdatedAt()?->format('Y-m-d H:i:s') ?? now(),
            'deleted_at' => $contact->getDeletedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
