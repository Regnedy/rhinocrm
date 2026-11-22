<?php

namespace App\Modules\Integrations\Whatsapp\DTOs;

final readonly class PartnerWhatsappChannelDTO
{
    public function __construct(
        public string $partnerId,
        public string $phoneNumberId,
        public string $accessToken,
        public ?string $wabaId,
        public ?string $displayPhoneNumber,
        public ?string $pdfTemplateName,
        public ?string $imagesTemplateName,
        public string $templateLanguage,
        public string $status,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
