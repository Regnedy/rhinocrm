# API móvil y administrativa

Base:

```text
/api/v1
```

Las respuestas utilizan:

```json
{
  "success": true,
  "data": {},
  "message": null
}
```

Los errores utilizan:

```json
{
  "success": false,
  "error": {
    "code": "CODIGO",
    "message": "Descripción"
  }
}
```

## Autenticación local

```http
POST /api/v1/auth/login
```

Campos: `email`, `password`, `device_name`, `platform`, `app_version`, `device_fingerprint` y `fcm_token` opcionales según la solicitud.

Después:

```http
Authorization: Bearer rhino_...
Accept: application/json
```

Rutas de sesión:

```text
GET  /api/v1/me
POST /api/v1/auth/logout
POST /api/v1/auth/firebase/exchange
```

Para Firebase, Android envía el ID Token en `Authorization: Bearer ...` al endpoint de intercambio junto con los datos del dispositivo. La respuesta contiene una sesión `rhino_...`. Por seguridad, las rutas de negocio no aceptan directamente ID Tokens Firebase cuando `FIREBASE_ALLOW_DIRECT_API_TOKENS=false`.

## CRM

```text
GET    /contacts
POST   /contacts
GET    /contacts/{id}
PATCH  /contacts/{id}
GET    /contacts/{id}/measurements
GET    /contacts/{contactId}/timeline
GET    /pipeline
PATCH  /pipeline/{id}/stage
GET    /tasks
POST   /tasks
POST   /tasks/{id}/complete
POST   /tasks/{id}/reschedule
```

Los listados admiten límites y filtros definidos en sus Form Requests/controladores. El backend aplica socio, rol y equipo aunque el cliente intente enviar identificadores distintos.

## Catálogos y ubicación técnica

```text
GET  /plans                       solo SUPER_ADMIN
POST /partners                    solo SUPER_ADMIN
GET  /locations
POST /locations
```

`SUPER_ADMIN` crea socios. El CRM genera una ubicación técnica `Principal` para asociar usuarios, equipos y básculas; no es necesario administrarla desde la interfaz. Los demás usuarios operan exclusivamente dentro de su socio.


## Eventos y minicampañas

```text
GET    /campaigns
POST   /campaigns
GET    /campaigns/{id}
PATCH  /campaigns/{id}
POST   /campaigns/{id}/activate
POST   /campaigns/{id}/close
POST   /campaigns/{id}/cancel
GET    /campaigns/{id}/participants
POST   /campaigns/{id}/participants
GET    /campaigns/{id}/report
```

Los eventos pueden vincular equipos, integrantes y básculas. Cuando una báscula participa en un evento `ACTIVE`, las mediciones y el expediente asociado quedan atribuidos automáticamente. El registro manual reutiliza contactos por WhatsApp y puede crear un seguimiento. Una báscula no puede estar activa en dos eventos simultáneamente.

El listado de contactos admite `campaign_id` para consultar prospectos o clientes relacionados con un evento.

## Equipos

```text
GET    /teams
POST   /teams
GET    /teams/{id}
PATCH  /teams/{id}
POST   /teams/{id}/members
DELETE /teams/{id}/members/{userId}
PUT    /teams/{id}/goals
GET    /teams/{id}/report
GET    /teams/reports/summary
```

Métricas principales: prospectos generados, prospectos asignados, mediciones, tareas, ventas, ingresos y cumplimiento de metas.

## Operación

```text
GET  /devices
POST /devices
GET  /products
POST /products
GET  /inventory
GET  /inventory/movements
POST /inventory/adjustments
GET  /sales
GET  /sales/{id}
POST /sales
POST /sales/{id}/refund
```

Las operaciones de ventas e inventario usan idempotencia y transacciones.

## Administración y bloqueos

```text
GET  /security/users
POST /security/users/{id}/suspend
POST /security/users/{id}/activate
POST /security/users/{id}/revoke-sessions
GET  /security/sessions
POST /security/sessions/{id}/revoke
GET  /security/devices
POST /security/devices/{id}/suspend
POST /security/devices/{id}/activate
POST /security/devices/{id}/revoke
POST /security/devices/{id}/rotate-secret
POST /security/contacts/{id}/block
POST /security/contacts/{id}/activate
GET  /security/audit
```

Solo `SUPER_ADMIN` puede usar la administración global:

```text
GET  /admin/partners
POST /admin/partners/{id}/suspend
POST /admin/partners/{id}/activate
```

## Informes

```text
GET  /analytics/dashboard/summary
POST /reports
GET  /reports/{id}
GET  /reports/{id}/download
```

Los archivos se guardan en almacenamiento privado, no en una URL pública permanente.


## Consola técnica de SUPER_ADMIN

La interfaz está disponible en:

```text
GET /admin/developer-console
```

La página realiza el inicio de sesión contra la API y conserva el token únicamente en `sessionStorage` del navegador. Los datos técnicos utilizan:

```text
GET  /admin/developer-console/status
GET  /admin/developer-console/log-files
GET  /admin/developer-console/logs
GET  /admin/developer-console/commands
POST /admin/developer-console/commands/{action}
GET  /admin/developer-console/source-files
GET  /admin/developer-console/source
GET  /admin/developer-console/actions
GET  /admin/developer-console/ai/history
POST /admin/developer-console/ai/analyze
```

Estas rutas están dentro de `/api/v1`, requieren token Rhino, rol `SUPER_ADMIN`, el middleware `developer.console` y límites de frecuencia. La ruta de IA tiene un límite adicional.

La API no ofrece comandos libres, escritura de archivos, SQL arbitrario ni ejecución de migraciones.
