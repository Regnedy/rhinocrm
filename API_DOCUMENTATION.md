# RHINO 2.7.0 — API Documentation

Prefijo general:

```text
/api/v1
```

Todas las rutas privadas usan la autenticación ya existente de RHINO.

## Mis dispositivos

### GET `/personal-devices`

Devuelve los dispositivos cuyo propietario es el usuario autenticado.

Respuesta de ejemplo:

```json
{
  "success": true,
  "data": [
    {
      "id": "PDV_...",
      "device_type": "SCALE",
      "display_name": "Báscula Rhino",
      "hardware_identifier": "AA:BB:CC:DD:EE:FF",
      "model": "AAAx",
      "status": "AVAILABLE",
      "owner_user_id": "..."
    }
  ]
}
```

### POST `/personal-devices`

```json
{
  "device_type": "SCALE",
  "display_name": "Báscula Rhino",
  "hardware_identifier": "AA:BB:CC:DD:EE:FF",
  "model": "AAAx"
}
```

`device_type`: `SCALE` o `PRINTER`.

### DELETE `/personal-devices/{id}`

Marca el equipo como retirado. No permite retirarlo mientras esté disponible en un evento activo.

## Dispositivos disponibles en evento

### GET `/campaigns/{id}/available-devices`

Devuelve los dispositivos temporalmente disponibles en el evento, incluyendo propietario.

### POST `/campaigns/{id}/available-devices`

```json
{
  "personal_device_ids": ["PDV_...", "PDV_..."]
}
```

El usuario sólo puede compartir equipos propios. Un equipo no puede estar activo simultáneamente en otro evento activo.

## QR Party

### POST `/campaign-join/preview`

```json
{
  "token": "TOKEN_QR"
}
```

Además de los datos del evento, 2.7.0 devuelve:

- `organizer_name`;
- `my_devices`.

### POST `/campaign-join/accept`

```json
{
  "token": "TOKEN_QR",
  "personal_device_ids": ["PDV_..."]
}
```

Aceptar el evento y compartir equipos son operaciones independientes de la propiedad del dispositivo.

## Party

### GET `/campaigns/{id}/collaborators`

Cada colaborador puede incluir:

```json
{
  "id": "USER_ID",
  "name": "Manuel",
  "member_role": "COLLABORATOR",
  "devices_available": [
    {
      "id": "PDV_...",
      "device_type": "SCALE",
      "display_name": "Báscula Rhino",
      "hardware_identifier": "AA:BB:CC:DD:EE:FF"
    }
  ]
}
```

## Prospectos del evento

### GET `/campaigns/{id}/participants`

2.7.0 mantiene, entre otros:

- `registered_by_name` → Capturado por.
- `assigned_advisor_name` → Responsable actual.
- `owner_name`.
- `device_used` → última báscula utilizada dentro del evento, con propietario cuando existe representación personal.

Ejemplo de `device_used`:

```json
{
  "device_id": "DEV_...",
  "display_name": "Báscula Rhino",
  "hardware_identifier": "AA:BB:CC:DD:EE:FF",
  "model": "AAAx",
  "owner_name": "Manuel",
  "measured_at": "2026-08-26 18:34:00"
}
```

## Asignar responsable

### PATCH `/campaigns/{id}/participants/{contactId}/assignee`

```json
{
  "user_id": "USER_ID"
}
```

Registra el cambio en `campaign_assignment_history`. No modifica `registered_by_user_id`.

## Historial de prospecto

### GET `/campaigns/{id}/participants/{contactId}/history`

Devuelve una línea temporal ordenada con creación del prospecto, actividades del evento y cambios de responsable.

## Pipeline

### GET `/campaigns/{id}/pipeline`

Incluye `party_pipeline` con etapas de evento como pesajes, contacto/WhatsApp, plan enviado, respuesta positiva y compra.

## Finalizar evento

### POST `/campaigns/{id}/close`

Cierra el evento de forma compatible con `CLOSED`, revoca invitaciones/QR pendientes, libera dispositivos y congela operaciones colaborativas.

La respuesta del evento incluye `display_status` con `FINISHED` cuando el estado interno es `CLOSED`.

## Actualizador Kiosko

### GET `/kiosk/update/check?version_code=270`

Ejemplo:

```json
{
  "success": true,
  "data": {
    "update_available": true,
    "current_version_code": 270,
    "update": {
      "id": "KVR_...",
      "version_name": "2.7.1",
      "version_code": 271,
      "package_name": "com.activa7.rhinocrm",
      "sha256": "...",
      "signing_sha256": null,
      "release_type": "RECOMMENDED",
      "mandatory": false,
      "notes": "Mejoras...",
      "download_path": "/api/v1/kiosk/update/download/KVR_..."
    }
  }
}
```

### GET `/kiosk/update/download/{id}`

Descarga autenticada del APK almacenado de forma privada. El servidor vuelve a verificar el SHA-256 del archivo antes de enviarlo.

### POST `/kiosk/update/installations`

```json
{
  "kiosk_version_id": "KVR_...",
  "installed_version_code": 271,
  "app_version_name": "2.7.1",
  "event_type": "INSTALL_REQUESTED",
  "device_fingerprint": "..."
}
```

`event_type`: `INSTALL_REQUESTED`, `INSTALLED` o `FAILED`.

## SUPER ADMIN — Versiones Kiosko

### GET `/admin/kiosk-versions`

Lista las versiones publicadas.

### POST `/admin/kiosk-versions`

`multipart/form-data`:

- `version_name`
- `version_code`
- `release_type`: `RECOMMENDED` o `MANDATORY`
- `notes` opcional
- `signing_sha256` opcional
- `apk` obligatorio

El servidor calcula `apk_sha256` automáticamente.

### POST `/admin/kiosk-versions/{id}/revoke`

Marca una versión como `REVOKED`; deja de ofrecerse a los Kioskos.

