<?php

namespace App\Modules\Integrations\Whatsapp\Contracts;

interface WhatsappProviderInterface
{
    public function sendText(string $toPhoneNumber, string $body, ?string $partnerId = null): string;

    public function sendTemplate(string $toPhoneNumber, string $templateName, array $variables, string $language = 'es_MX', ?string $partnerId = null): string;

    public function uploadDocument(string $absolutePath, string $filename, string $mimeType = 'application/pdf', ?string $partnerId = null): string;

    public function uploadImage(string $absolutePath, string $filename, string $mimeType = 'image/jpeg', ?string $partnerId = null): string;

    public function sendDocument(string $toPhoneNumber, string $mediaId, string $filename, string $caption = '', ?string $partnerId = null): string;

    public function sendImage(string $toPhoneNumber, string $mediaId, string $caption = '', ?string $partnerId = null): string;

    public function sendImageTemplate(
        string $toPhoneNumber,
        string $templateName,
        string $mediaId,
        array $variables = [],
        string $language = 'es_MX',
        ?string $partnerId = null,
    ): string;

    public function sendDocumentTemplate(
        string $toPhoneNumber,
        string $templateName,
        string $mediaId,
        string $filename,
        array $variables = [],
        string $language = 'es_MX',
        ?string $partnerId = null,
    ): string;
}
