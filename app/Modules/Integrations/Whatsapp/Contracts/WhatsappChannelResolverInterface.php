<?php

namespace App\Modules\Integrations\Whatsapp\Contracts;

use App\Modules\Integrations\Whatsapp\DTOs\PartnerWhatsappChannelDTO;

interface WhatsappChannelResolverInterface
{
    public function resolve(?string $partnerId = null): PartnerWhatsappChannelDTO;

    /** @return array<string,mixed> */
    public function summary(string $partnerId): array;
}
