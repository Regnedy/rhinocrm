<?php
// Archivo: app/Modules/Auth/Domain/Exceptions/TenantViolationException.php

namespace App\Modules\Auth\Domain\Exceptions;

use RuntimeException;
use Illuminate\Support\Facades\Log;

class TenantViolationException extends RuntimeException
{
    public function __construct(string $userId, string $attemptedPartnerId)
    {
        parent::__construct("Violación de Aislamiento Multitenant detectada y bloqueada.", 403);
        Log::channel('stderr')->alert('SECURITY_ALERT: TENANT_VIOLATION', [
            'user_id' => $userId,
            'attempted_partner_id' => $attemptedPartnerId
        ]);
    }
}