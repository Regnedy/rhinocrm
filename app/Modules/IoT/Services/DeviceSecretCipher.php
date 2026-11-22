<?php

namespace App\Modules\IoT\Services;

use Illuminate\Support\Facades\Crypt;

final class DeviceSecretCipher
{
    public function encrypt(string $secret): string
    {
        return Crypt::encryptString($secret);
    }

    public function decrypt(string $encryptedValue): string
    {
        try {
            return Crypt::decryptString($encryptedValue);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'No fue posible descifrar el secreto del dispositivo. Reaprovisione el hardware.',
                500,
                $exception,
            );
        }
    }
}
