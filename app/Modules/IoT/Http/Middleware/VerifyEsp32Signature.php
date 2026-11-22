<?php

namespace App\Modules\IoT\Http\Middleware;

use App\Modules\IoT\Contracts\DeviceRepositoryInterface;
use App\Modules\IoT\DTOs\DeviceContextDTO;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class VerifyEsp32Signature
{
    public function __construct(private readonly DeviceRepositoryInterface $devices, private readonly Container $container) {}

    public function handle(Request $request, Closure $next): Response
    {
        $mac = strtoupper(trim((string) $request->header('X-Device-MAC')));
        $timestamp = (string) $request->header('X-Timestamp');
        $signature = strtolower(trim((string) $request->header('X-Signature')));
        if ($mac === '' || $timestamp === '' || $signature === '') return $this->abort('MISSING_HEADERS', 'Faltan cabeceras de seguridad IoT.', 400);
        if (!preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) return $this->abort('DEVICE_MAC_INVALID', 'MAC inválida.', 400);
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > (int) env('IOT_REPLAY_WINDOW_SECONDS', 300)) return $this->abort('EXPIRED_TIMESTAMP', 'Timestamp expirado o inválido.', 401);

        $device = $this->devices->findByMac($mac);
        if ($device === null) return $this->abort('DEVICE_NOT_REGISTERED', 'Dispositivo no registrado.', 403);
        if (!$device->isActive()) return $this->abort('DEVICE_SUSPENDED', 'Dispositivo suspendido.', 403);
        $partner = DB::table('partners')->where('id', $device->getPartnerId())->whereNull('deleted_at')->first();
        if ($partner === null || (string) $partner->status !== 'ACTIVE') return $this->abort('PARTNER_SUSPENDED', 'El socio propietario está suspendido.', 403);
        $location = DB::table('locations')->where('id', $device->getLocationId())->whereNull('deleted_at')->first();
        if ($location === null || (string) $location->status !== 'ACTIVE') return $this->abort('LOCATION_SUSPENDED', 'La ubicación está suspendida.', 403);

        $expected = hash_hmac('sha256', $timestamp.$request->getContent(), $device->getDeviceToken());
        if (!preg_match('/^[a-f0-9]{64}$/', $signature) || !hash_equals($expected, $signature)) {
            Log::channel('security')->warning('Firma IoT inválida', ['mac' => $mac, 'ip' => $request->ip()]);
            return $this->abort('SIGNATURE_INVALID', 'Firma criptográfica rechazada.', 401);
        }

        $context = new DeviceContextDTO($device->getId(), $device->getMacAddress(), $device->getPartnerId(), $device->getLocationId());
        $this->container->forgetInstance(DeviceContextDTO::class);
        $this->container->instance(DeviceContextDTO::class, $context);
        $request->attributes->set('device_context', $context);
        return $next($request);
    }

    private function abort(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
    }
}
