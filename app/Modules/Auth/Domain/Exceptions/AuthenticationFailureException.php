<?php

namespace App\Modules\Auth\Domain\Exceptions;

final class AuthenticationFailureException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, int $status = 401)
    {
        parent::__construct($message, $status);
    }
}
