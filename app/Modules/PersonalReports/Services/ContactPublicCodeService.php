<?php

namespace App\Modules\PersonalReports\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ContactPublicCodeService
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function ensure(string $contactId): string
    {
        return DB::transaction(function () use ($contactId): string {
            $contact = DB::table('contacts')->where('id', $contactId)->lockForUpdate()->first();
            if ($contact === null) {
                throw new \RuntimeException('Contacto no encontrado para generar código RHINO.', 404);
            }
            if ($contact->public_code !== null && trim((string) $contact->public_code) !== '') {
                return strtoupper((string) $contact->public_code);
            }

            for ($attempt = 0; $attempt < 20; $attempt++) {
                $code = 'RH-'.$this->segment(4).'-'.$this->segment(4);
                try {
                    DB::table('contacts')->where('id', $contactId)->update([
                        'public_code' => $code,
                        'updated_at' => now(),
                    ]);
                    return $code;
                } catch (QueryException $exception) {
                    if (!str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                        throw $exception;
                    }
                }
            }

            throw new \RuntimeException('No fue posible generar un código RHINO único.');
        }, 3);
    }

    public function extract(string $text): ?string
    {
        if (preg_match('/\bRH-[A-Z0-9]{4}-[A-Z0-9]{4}\b/i', $text, $matches) !== 1) {
            return null;
        }
        return strtoupper($matches[0]);
    }

    private function segment(int $length): string
    {
        $result = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $result .= self::ALPHABET[random_int(0, $max)];
        }
        return $result;
    }
}
