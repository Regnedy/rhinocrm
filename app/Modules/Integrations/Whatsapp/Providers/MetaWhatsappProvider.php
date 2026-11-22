<?php

namespace App\Modules\Integrations\Whatsapp\Providers;

use App\Modules\Integrations\Whatsapp\Contracts\WhatsappChannelResolverInterface;
use App\Modules\Integrations\Whatsapp\Contracts\WhatsappProviderInterface;
use App\Modules\Integrations\Whatsapp\DTOs\PartnerWhatsappChannelDTO;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MetaWhatsappProvider implements WhatsappProviderInterface
{
    public function __construct(private readonly WhatsappChannelResolverInterface $channels) {}

    public function sendText(string $toPhoneNumber, string $body, ?string $partnerId = null): string
    {
        $body = trim($body);
        if ($body === '') {
            throw new RuntimeException('El mensaje de WhatsApp está vacío.');
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $this->digits($toPhoneNumber),
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => mb_substr($body, 0, 4096),
            ],
        ], $partnerId);
    }

    public function sendTemplate(string $toPhoneNumber, string $templateName, array $variables, string $language = 'es_MX', ?string $partnerId = null): string
    {
        $components = [];
        if ($variables !== []) {
            ksort($variables);
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    static fn (mixed $value): array => ['type' => 'text', 'text' => (string) $value],
                    array_values($variables),
                ),
            ];
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $this->digits($toPhoneNumber),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
                'components' => $components,
            ],
        ], $partnerId);
    }

    public function uploadDocument(string $absolutePath, string $filename, string $mimeType = 'application/pdf', ?string $partnerId = null): string
    {
        return $this->uploadMedia($absolutePath, $filename, $mimeType, 'document', $partnerId);
    }

    public function uploadImage(string $absolutePath, string $filename, string $mimeType = 'image/jpeg', ?string $partnerId = null): string
    {
        return $this->uploadMedia($absolutePath, $filename, $mimeType, 'image', $partnerId);
    }

    public function sendDocument(string $toPhoneNumber, string $mediaId, string $filename, string $caption = '', ?string $partnerId = null): string
    {
        $document = ['id' => $mediaId, 'filename' => $filename];
        if (trim($caption) !== '') {
            $document['caption'] = mb_substr(trim($caption), 0, 1000);
        }
        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $this->digits($toPhoneNumber),
            'type' => 'document',
            'document' => $document,
        ], $partnerId);
    }

    public function sendImage(string $toPhoneNumber, string $mediaId, string $caption = '', ?string $partnerId = null): string
    {
        $image = ['id' => $mediaId];
        if (trim($caption) !== '') {
            $image['caption'] = mb_substr(trim($caption), 0, 1000);
        }
        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $this->digits($toPhoneNumber),
            'type' => 'image',
            'image' => $image,
        ], $partnerId);
    }

    public function sendImageTemplate(
        string $toPhoneNumber,
        string $templateName,
        string $mediaId,
        array $variables = [],
        string $language = 'es_MX',
        ?string $partnerId = null,
    ): string {
        $components = [[
            'type' => 'header',
            'parameters' => [[
                'type' => 'image',
                'image' => ['id' => $mediaId],
            ]],
        ]];
        if ($variables !== []) {
            ksort($variables);
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    static fn (mixed $value): array => ['type' => 'text', 'text' => (string) $value],
                    array_values($variables),
                ),
            ];
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $this->digits($toPhoneNumber),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
                'components' => $components,
            ],
        ], $partnerId);
    }

    public function sendDocumentTemplate(
        string $toPhoneNumber,
        string $templateName,
        string $mediaId,
        string $filename,
        array $variables = [],
        string $language = 'es_MX',
        ?string $partnerId = null,
    ): string {
        $components = [[
            'type' => 'header',
            'parameters' => [[
                'type' => 'document',
                'document' => ['id' => $mediaId, 'filename' => $filename],
            ]],
        ]];
        if ($variables !== []) {
            ksort($variables);
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    static fn (mixed $value): array => ['type' => 'text', 'text' => (string) $value],
                    array_values($variables),
                ),
            ];
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $this->digits($toPhoneNumber),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
                'components' => $components,
            ],
        ], $partnerId);
    }

    private function uploadMedia(string $absolutePath, string $filename, string $mimeType, string $label, ?string $partnerId): string
    {
        $channel = $this->channels->resolve($partnerId);
        if (!is_readable($absolutePath)) {
            throw new RuntimeException('El archivo no está disponible para enviarse por WhatsApp.');
        }
        $size = filesize($absolutePath);
        $maxBytes = str_starts_with($mimeType, 'image/') ? 5 * 1024 * 1024 : 100 * 1024 * 1024;
        if ($size !== false && $size > $maxBytes) {
            throw new RuntimeException('El archivo excede el tamaño permitido por WhatsApp para '.$label.'.');
        }

        $response = Http::withToken($channel->accessToken)
            ->timeout(45)
            ->retry(2, 700)
            ->attach('file', file_get_contents($absolutePath), $filename, ['Content-Type' => $mimeType])
            ->post($this->baseUrl($channel).'/media', [
                'messaging_product' => 'whatsapp',
            ]);
        if (!$response->successful()) {
            throw new RuntimeException('Meta rechazó la carga del '.$label.': '.mb_substr($response->body(), 0, 700));
        }
        $mediaId = $response->json('id');
        if (!is_string($mediaId) || $mediaId === '') {
            throw new RuntimeException('Meta no devolvió el ID del archivo cargado.');
        }
        return $mediaId;
    }

    /** @param array<string,mixed> $payload */
    private function send(array $payload, ?string $partnerId): string
    {
        $channel = $this->channels->resolve($partnerId);
        $response = Http::withToken($channel->accessToken)
            ->timeout(30)
            ->retry(2, 700)
            ->post($this->baseUrl($channel).'/messages', $payload);
        if (!$response->successful()) {
            throw new RuntimeException('Meta WhatsApp rechazó el mensaje: '.mb_substr($response->body(), 0, 700));
        }
        $id = $response->json('messages.0.id');
        if (!is_string($id) || $id === '') {
            throw new RuntimeException('Meta no devolvió el ID del mensaje.');
        }
        return $id;
    }

    private function baseUrl(PartnerWhatsappChannelDTO $channel): string
    {
        return rtrim((string) config('services.meta.api_url'), '/').'/'.rawurlencode($channel->phoneNumberId);
    }

    private function digits(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            throw new RuntimeException('El número de WhatsApp está vacío o es inválido.');
        }
        return $digits;
    }
}
