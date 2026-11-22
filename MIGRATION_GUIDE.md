# RHINO CRM 2.7.0 — Guía de migración

## Estrategia

RHINO 2.7.0 es una evolución de la base 2.6.x. La migración principal es:

```text
database/migrations/2026_08_26_000200_rhino_270_devices_assignments_updates.php
```

No reemplaza `devices`, `campaigns`, `campaign_contacts`, `contacts` ni `measurements`.

## Tablas nuevas

### `personal_devices`

Representa equipos personales del socio.

Campos clave:

- `owner_user_id`: propietario real e inmutable del equipo salvo una futura operación administrativa explícita.
- `owner_partner_id`: organización del propietario.
- `device_type`: `SCALE` o `PRINTER`.
- `linked_device_id`: referencia al dispositivo técnico heredado cuando se trata de una báscula ya registrada.
- `hardware_identifier`: MAC/identificador visible.
- `status`: `AVAILABLE`, `IN_EVENT`, `REMOVED`, etc.

Las básculas existentes con `linked_by_user_id` se representan también en esta tabla sin eliminar su registro técnico en `devices`.

### `campaign_device_availability`

Registra el uso temporal de un dispositivo personal dentro de un evento.

No transfiere propiedad.

- `ACTIVE`: disponible dentro del evento.
- `RELEASED`: disponibilidad terminada.

Al finalizar/cancelar el evento, la disponibilidad activa se libera y el equipo personal vuelve a `AVAILABLE` si no está activo en otro evento.

### `campaign_assignment_history`

Audita cada cambio de responsable de un prospecto:

- responsable anterior;
- nuevo responsable;
- usuario que realizó el cambio;
- motivo;
- fecha.

`registered_by_user_id` sigue representando quién capturó originalmente al prospecto y no se sobrescribe al reasignar.

### `kiosk_versions`

Versiones APK publicadas desde SUPER ADMIN:

- versión;
- versionCode;
- package;
- ruta privada;
- SHA-256 del APK;
- SHA-256 opcional del certificado;
- tipo `RECOMMENDED` / `MANDATORY`;
- notas y estado.

### `kiosk_installations`

Auditoría de solicitudes e instalaciones del actualizador.

## Reglas de datos 2.7.0

### Captura y responsabilidad

Se separan estos conceptos:

- **Capturado por**: `campaign_contacts.registered_by_user_id`.
- **Responsable actual**: `campaign_contacts.assigned_advisor_id` / `owner_user_id`.
- **Organización responsable**: `owner_partner_id` y `contacts.responsible_partner_id` cuando corresponda.
- **Propietario del dispositivo**: `personal_devices.owner_user_id`.

El dispositivo utilizado nunca determina el responsable del prospecto.

### Generar dieta

Generar una dieta registra quién la generó, pero **no reasigna** el prospecto.

### Evento finalizado

Para conservar compatibilidad interna:

```text
CLOSED (base de datos / reglas heredadas)
```

se expone visualmente como:

```text
FINISHED / Finalizado
```

### Privacidad post-evento

Después de `CLOSED`, salvo SUPER ADMIN, la consulta de participantes se limita a registros cuyo `owner_user_id` o `assigned_advisor_id` sea el usuario actual.

Los permisos temporales del Party no conceden acceso permanente a los prospectos de otros colaboradores.

## Procedimiento

```bash
# 1. respaldo de DB
# 2. copiar archivos del update
php artisan optimize:clear
php artisan migrate --force
php artisan route:list --path=api/v1
```

## Rollback

El método `down()` de la migración 2.7.0 es deliberadamente no destructivo. No elimina tablas de auditoría, versiones o propiedad.

Si necesitas volver temporalmente al código 2.6.x, restaura el código previo pero conserva las nuevas tablas hasta definir una migración de reversión explícita y respaldada.

