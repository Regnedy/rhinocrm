<?php

namespace App\Modules\Mobile\Controllers;

use App\Modules\Mobile\Services\KioskUpdateService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class KioskUpdateController
{
    use ApiResponse;

    public function check(Request $request, KioskUpdateService $service): JsonResponse
    {
        $data = $request->validate(['version_code' => ['required', 'integer', 'min:1']]);
        return $this->respond(fn () => $service->check((int) $data['version_code']));
    }
    public function installations(Request $request, KioskUpdateService $service): JsonResponse
    {
        $data = $request->validate([
            'kiosk_version_id' => ['nullable','string','max:80'], 'installed_version_code' => ['required','integer','min:1'],
            'event_type' => ['nullable','in:INSTALL_REQUESTED,INSTALLED,FAILED'], 'app_version_name' => ['nullable','string','max:32'],
            'device_fingerprint' => ['nullable','string','max:255'], 'metadata' => ['nullable','array'],
        ]);
        return $this->respond(fn () => $service->recordInstallation($data), 'Instalación registrada.', 201);
    }
    public function download(string $id, KioskUpdateService $service): BinaryFileResponse
    {
        $version = $service->downloadable($id);
        return response()->download($version->absolute_path, 'RHINO-Kiosko-'.$version->version_name.'.apk', [
            'Content-Type' => 'application/vnd.android.package-archive', 'Cache-Control' => 'private, no-store',
        ]);
    }
    public function index(KioskUpdateService $service): JsonResponse { return $this->respond(fn () => $service->list()); }
    public function publish(Request $request, KioskUpdateService $service): JsonResponse
    {
        $data = $request->validate([
            'version_name' => ['required','string','max:32'], 'version_code' => ['required','integer','min:1'],
            'release_type' => ['required','in:RECOMMENDED,MANDATORY'], 'notes' => ['nullable','string','max:20000'],
            'signing_sha256' => ['nullable','string','max:128'], 'apk' => ['required','file','max:204800'],
        ]);
        return $this->respond(fn () => $service->publish($data, $request->file('apk')), 'Versión Kiosko publicada.', 201);
    }
    public function revoke(string $id, KioskUpdateService $service): JsonResponse { return $this->respond(fn () => $service->revoke($id), 'Versión revocada.'); }

    private function respond(callable $callback, string $message = 'Success', int $status = 200): JsonResponse
    {
        try { return $this->successResponse($callback(), $message, $status); }
        catch (\Throwable $e) { $code=(int)$e->getCode(); $http=in_array($code,[403,404,409,422],true)?$code:500; if($http===500)report($e); return $this->errorResponse($http===500?'No fue posible completar la operación de actualización.':$e->getMessage(),'KIOSK_UPDATE_FAILED',$http); }
    }
}
