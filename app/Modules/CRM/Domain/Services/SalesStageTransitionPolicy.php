<?php

namespace App\Modules\CRM\Domain\Services;

final class SalesStageTransitionPolicy
{
    private const CATALOG = [
        'NEW' => ['label' => 'Nuevo', 'allowed_to' => ['CONTACTED', 'APPOINTMENT_SCHEDULED', 'LOST']],
        'CONTACTED' => ['label' => 'Contactado', 'allowed_to' => ['APPOINTMENT_SCHEDULED', 'FOLLOW_UP', 'INTERESTED', 'LOST']],
        'APPOINTMENT_SCHEDULED' => ['label' => 'Cita agendada', 'allowed_to' => ['FOLLOW_UP', 'MEASUREMENT_COMPLETED', 'INTERESTED', 'LOST']],
        'FOLLOW_UP' => ['label' => 'Seguimiento', 'allowed_to' => ['APPOINTMENT_SCHEDULED', 'INTERESTED', 'QUOTE_SENT', 'NEGOTIATION', 'LOST']],
        'MEASUREMENT_COMPLETED' => ['label' => 'Medición realizada', 'allowed_to' => ['FOLLOW_UP', 'INTERESTED', 'QUOTE_SENT', 'NEGOTIATION', 'LOST']],
        'INTERESTED' => ['label' => 'Interesado', 'allowed_to' => ['FOLLOW_UP', 'QUOTE_SENT', 'NEGOTIATION', 'LOST']],
        'QUOTE_SENT' => ['label' => 'Cotización enviada', 'allowed_to' => ['FOLLOW_UP', 'NEGOTIATION', 'CLOSED', 'LOST']],
        'NEGOTIATION' => ['label' => 'Negociación', 'allowed_to' => ['QUOTE_SENT', 'CLOSED', 'LOST']],
        'CLOSED' => ['label' => 'Cliente', 'allowed_to' => ['REPURCHASE']],
        'REPURCHASE' => ['label' => 'Recompra', 'allowed_to' => ['FOLLOW_UP', 'QUOTE_SENT', 'NEGOTIATION', 'CLOSED', 'REPURCHASE', 'LOST']],
        'LOST' => ['label' => 'Perdido', 'allowed_to' => ['NEW', 'FOLLOW_UP']],
    ];

    public function assertAllowed(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        if (!isset(self::CATALOG[$to]) || !in_array($to, self::CATALOG[$from]['allowed_to'] ?? [], true)) {
            throw new \DomainException("Transición comercial no permitida: {$from} → {$to}.");
        }
    }

    /** @return array<int,array{id:string,label:string,allowed_to:array<int,string>}> */
    public function catalog(): array
    {
        $result = [];
        foreach (self::CATALOG as $id => $definition) {
            $result[] = ['id' => $id, 'label' => $definition['label'], 'allowed_to' => $definition['allowed_to']];
        }
        return $result;
    }

    /** @return string[] */
    public static function stageIds(): array
    {
        return array_keys(self::CATALOG);
    }
}
