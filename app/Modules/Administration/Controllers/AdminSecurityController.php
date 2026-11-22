<?php

namespace App\Modules\Administration\Controllers;

use App\Modules\Administration\Services\AdminSecurityService;
use App\Modules\Administration\Services\PartnerPermissionAdminService;
use App\Modules\Partners\Services\PartnerPrimaryAccessService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminSecurityController
{
    use ApiResponse;

    public function partners(AdminSecurityService $s): JsonResponse { return $this->successResponse($s->partners()); }
    public function partnerPermissionCatalog(PartnerPermissionAdminService $s): JsonResponse { return $this->successResponse($s->catalog()); }
    public function partnerPermissions(string $id, PartnerPermissionAdminService $s): JsonResponse { return $this->successResponse($s->show($id)); }
    public function updatePartnerPermissions(string $id, Request $r, PartnerPermissionAdminService $s): JsonResponse
    {
        $d = $r->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'max:120', 'distinct'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->successResponse($s->update($id, $d['permissions'], $d['reason'] ?? null), 'Permisos del socio actualizados.');
    }
    public function resetPartnerPermissions(string $id, Request $r, PartnerPermissionAdminService $s): JsonResponse
    {
        $d = $r->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->successResponse($s->reset($id, $d['reason'] ?? null), 'Permisos predeterminados restaurados.');
    }
    public function suspendPartner(string $id, Request $r, AdminSecurityService $s): JsonResponse { $d=$r->validate(['reason'=>'required|string|max:1000']); return $this->successResponse($s->suspendPartner($id,$d['reason']),'Socio suspendido.'); }
    public function activatePartner(string $id, AdminSecurityService $s): JsonResponse { return $this->successResponse($s->activatePartner($id),'Socio activado.'); }
    public function users(Request $r, AdminSecurityService $s): JsonResponse { return $this->successResponse($s->users($r->query('partner_id'))); }
    public function suspendUser(string $id, Request $r, AdminSecurityService $s): JsonResponse { $d=$r->validate(['reason'=>'required|string|max:1000']); return $this->successResponse($s->suspendUser($id,$d['reason']),'Usuario suspendido.'); }
    public function activateUser(string $id, AdminSecurityService $s): JsonResponse { return $this->successResponse($s->activateUser($id),'Usuario activado.'); }
    public function revokeUserSessions(string $id, Request $r, AdminSecurityService $s): JsonResponse { $d=$r->validate(['reason'=>'required|string|max:1000']); return $this->successResponse($s->revokeUserSessions($id,$d['reason']),'Sesiones revocadas.'); }
    public function sessions(Request $r, AdminSecurityService $s): JsonResponse { return $this->successResponse($s->sessionList($r->query('partner_id'),$r->query('user_id'))); }
    public function revokeSession(string $id, Request $r, AdminSecurityService $s): JsonResponse { $d=$r->validate(['reason'=>'required|string|max:1000']); return $this->successResponse($s->revokeSession($id,$d['reason']),'Sesión revocada.'); }
    public function devices(Request $r, AdminSecurityService $s): JsonResponse { return $this->successResponse($s->devices($r->query('partner_id'))); }
    public function deviceOverview(Request $r, AdminSecurityService $s): JsonResponse { return $this->successResponse($s->deviceOverview($r->query('partner_id'))); }
    public function suspendDevice(string $id, Request $r, AdminSecurityService $s): JsonResponse { $d=$r->validate(['reason'=>'required|string|max:1000']); return $this->successResponse($s->suspendDevice($id,$d['reason']),'Báscula suspendida.'); }
    public function activateDevice(string $id, AdminSecurityService $s): JsonResponse { return $this->successResponse($s->activateDevice($id),'Báscula activada.'); }
    public function revokeDevice(string $id, Request $r, AdminSecurityService $s): JsonResponse { $d=$r->validate(['reason'=>'required|string|max:1000']); return $this->successResponse($s->revokeDevice($id,$d['reason']),'Báscula revocada.'); }
    public function rotateDeviceSecret(string $id, AdminSecurityService $s): JsonResponse { return $this->successResponse($s->rotateDeviceSecret($id),'Secreto rotado. Guárdelo; no volverá a mostrarse.'); }
    public function blockContact(string $id, Request $r, AdminSecurityService $s): JsonResponse { $d=$r->validate(['reason'=>'required|string|max:1000']); return $this->successResponse($s->blockContact($id,$d['reason']),'Cliente bloqueado.'); }
    public function activateContact(string $id, AdminSecurityService $s): JsonResponse { return $this->successResponse($s->activateContact($id),'Cliente activado.'); }
    public function audit(Request $r, AdminSecurityService $s): JsonResponse { return $this->successResponse($s->auditLogs($r->query('partner_id'))); }
    public function partnerPrimaryEmailStatus(string $id, Request $r, PartnerPrimaryAccessService $s): JsonResponse
    {
        $d = $r->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        try {
            return $this->successResponse($s->emailStatus($id, $d['email']));
        } catch (\Throwable $exception) {
            return $this->serviceFailure($exception, 'PRIMARY_ACCESS_EMAIL_STATUS_FAILED');
        }
    }

    public function createPartnerPrimaryAccess(string $id, Request $r, PartnerPrimaryAccessService $s): JsonResponse
    {
        $d = $r->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:12', 'max:128'],
        ]);

        try {
            $result = $s->create($id, $d);
            $message = $result['verification']['error'] ?? null
                ? 'Cuenta principal creada; el correo de verificación requiere reintento.'
                : 'Cuenta principal creada. Se envió la verificación de correo.';

            return $this->successResponse($result, $message, 201);
        } catch (\Throwable $exception) {
            return $this->serviceFailure($exception, 'PRIMARY_ACCESS_CREATE_FAILED');
        }
    }

    public function transferPartnerPrimaryAccess(string $id, Request $r, PartnerPrimaryAccessService $s): JsonResponse
    {
        $d = $r->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:12', 'max:128'],
            'confirmation' => ['required', 'string', 'in:TRANSFERIR'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $result = $s->transferExisting($id, $d);
            $message = $result['verification']['error'] ?? null
                ? 'Cuenta transferida; el correo de verificación requiere reintento.'
                : 'Cuenta transferida y verificación enviada.';

            return $this->successResponse($result, $message);
        } catch (\Throwable $exception) {
            return $this->serviceFailure($exception, 'PRIMARY_ACCESS_TRANSFER_FAILED');
        }
    }

    public function resendPartnerVerification(string $id, PartnerPrimaryAccessService $s): JsonResponse
    {
        try {
            $result = $s->resendVerification($id);
            $message = $result['verification']['email_verified']
                ? 'El correo ya estaba verificado.'
                : ($result['verification']['sent'] ? 'Correo de verificación reenviado.' : 'No fue posible enviar el correo de verificación.');

            return $this->successResponse($result, $message);
        } catch (\Throwable $exception) {
            return $this->serviceFailure($exception, 'FIREBASE_VERIFICATION_SEND_FAILED');
        }
    }

    public function partnerVerificationLink(string $id, PartnerPrimaryAccessService $s): JsonResponse
    {
        try {
            return $this->successResponse($s->verificationLink($id));
        } catch (\Throwable $exception) {
            return $this->serviceFailure($exception, 'FIREBASE_VERIFICATION_LINK_FAILED');
        }
    }

    public function refreshPartnerFirebase(string $id, PartnerPrimaryAccessService $s): JsonResponse
    {
        try {
            return $this->successResponse($s->refresh($id), 'Estado de Firebase actualizado.');
        } catch (\Throwable $exception) {
            return $this->serviceFailure($exception, 'FIREBASE_STATUS_REFRESH_FAILED');
        }
    }

    public function changePartnerPrimaryPassword(string $id, Request $r, PartnerPrimaryAccessService $s): JsonResponse
    {
        $d = $r->validate([
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:12', 'max:128'],
        ]);

        try {
            return $this->successResponse($s->changePassword($id, $d['password']), 'Contraseña actualizada y sesiones cerradas.');
        } catch (\Throwable $exception) {
            return $this->serviceFailure($exception, 'PRIMARY_ACCESS_PASSWORD_FAILED');
        }
    }

    private function serviceFailure(\Throwable $exception, string $code): JsonResponse
    {
        $status = in_array((int) $exception->getCode(), [403, 404, 409, 422], true)
            ? (int) $exception->getCode()
            : 422;

        return $this->errorResponse($exception->getMessage(), $code, $status);
    }

}
