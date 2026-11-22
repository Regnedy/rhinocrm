<?php

declare(strict_types=1);

use App\Modules\Integrations\Whatsapp\Contracts\WhatsappChannelResolverInterface;
use App\Modules\Integrations\Whatsapp\DTOs\PartnerWhatsappChannelDTO;
use App\Modules\Integrations\Whatsapp\Providers\MetaWhatsappProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function verify(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

config(['services.meta.api_url' => 'https://graph.facebook.com/v25.0']);

$resolver = new class implements WhatsappChannelResolverInterface {
    public function resolve(?string $partnerId = null): PartnerWhatsappChannelDTO
    {
        $id = $partnerId ?: 'PARTNER-A';
        return match ($id) {
            'PARTNER-A' => new PartnerWhatsappChannelDTO('PARTNER-A', 'PHONE_A', 'TOKEN_A', 'WABA_A', '+526620000001', 'pdf_a', 'img_a', 'es_MX', 'ACTIVE'),
            'PARTNER-B' => new PartnerWhatsappChannelDTO('PARTNER-B', 'PHONE_B', 'TOKEN_B', 'WABA_B', '+14155550002', 'pdf_b', 'img_b', 'en_US', 'ACTIVE'),
            default => throw new RuntimeException('Partner desconocido'),
        };
    }
    public function summary(string $partnerId): array { return ['configured' => true, 'active' => true]; }
};

$requests = [];
Http::fake(function (Request $request) use (&$requests) {
    $requests[] = $request;
    if (str_ends_with($request->url(), '/media')) {
        return Http::response(['id' => 'MEDIA_TEST_'.count($requests)], 200);
    }
    return Http::response(['messages' => [['id' => 'wamid.TEST_'.count($requests)]]], 200);
});

$tmp = tempnam(sys_get_temp_dir(), 'rhino-wa-test-');
file_put_contents($tmp, 'fake-media');
$provider = new MetaWhatsappProvider($resolver);

$imageId = $provider->uploadImage($tmp, 'pagina-01.jpg', 'image/jpeg', 'PARTNER-A');
$imageMessageId = $provider->sendImage('526623669415', $imageId, 'Página 1', 'PARTNER-A');
$pdfId = $provider->uploadDocument($tmp, 'plan.pdf', 'application/pdf', 'PARTNER-B');
$pdfMessageId = $provider->sendDocument('14155552671', $pdfId, 'plan.pdf', 'Your plan', 'PARTNER-B');
@unlink($tmp);

verify(str_starts_with($imageId, 'MEDIA_TEST_'), 'La carga de imagen debe devolver media ID.');
verify(str_starts_with($imageMessageId, 'wamid.TEST_'), 'El envío de imagen debe devolver wamid.');
verify(str_starts_with($pdfId, 'MEDIA_TEST_'), 'La carga de PDF debe devolver media ID.');
verify(str_starts_with($pdfMessageId, 'wamid.TEST_'), 'El envío de PDF debe devolver wamid.');
verify(count($requests) === 4, 'Deben ejecutarse 4 requests HTTP.');
verify(str_ends_with($requests[0]->url(), '/PHONE_A/media'), 'Partner A debe cargar a su propio Phone Number ID.');
verify(str_ends_with($requests[1]->url(), '/PHONE_A/messages'), 'Partner A debe enviar desde su propio Phone Number ID.');
verify(str_contains(implode(' ', $requests[0]->header('Authorization')), 'TOKEN_A'), 'Partner A debe usar su propio token.');
verify(($requests[1]->data()['type'] ?? null) === 'image', 'Mensaje A debe ser tipo image.');
verify(($requests[1]->data()['to'] ?? null) === '526623669415', 'Imagen debe apuntar al número exacto.');
verify(str_ends_with($requests[2]->url(), '/PHONE_B/media'), 'Partner B debe cargar a su propio Phone Number ID.');
verify(str_ends_with($requests[3]->url(), '/PHONE_B/messages'), 'Partner B debe enviar desde su propio Phone Number ID.');
verify(str_contains(implode(' ', $requests[2]->header('Authorization')), 'TOKEN_B'), 'Partner B debe usar su propio token.');
verify(($requests[3]->data()['type'] ?? null) === 'document', 'Mensaje B debe ser tipo document.');
verify(($requests[3]->data()['to'] ?? null) === '14155552671', 'PDF debe apuntar al número exacto.');
verify(!str_contains($requests[0]->url(), 'PHONE_B') && !str_contains($requests[2]->url(), 'PHONE_A'), 'No se deben cruzar canales entre socios.');

fwrite(STDOUT, "PASS: Meta WhatsApp multi-partner HTTP contract\n");
