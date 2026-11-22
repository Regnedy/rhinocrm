<?php

namespace App\Modules\IoT\Services;

use Illuminate\Support\Facades\Crypt;

final class IntakePayloadCipher
{
    public function encrypt(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return Crypt::encryptString($json);
    }

    public function decrypt(string $encryptedPayload): array
    {
        try {
            $json = Crypt::decryptString($encryptedPayload);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('No fue posible descifrar la ficha temporal de la medición.', 500, $exception);
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Ficha temporal de medición inválida.', 500);
        }

        return $data;
    }
}
