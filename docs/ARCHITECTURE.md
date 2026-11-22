# Arquitectura

## Flujo principal

```text
Android / cliente web
        │ HTTPS + token Rhino
        ▼
Laravel API /api/v1
        │
        ├── identidad y sesión revocable
        ├── estado del usuario
        ├── estado del socio
        ├── permisos y alcance de equipo
        ├── validación de báscula
        └── auditoría
        │
        ▼
MySQL / MariaDB
```

Firebase Authentication queda como proveedor de identidad opcional. Cuando se active, Android obtiene un ID token Firebase y lo intercambia en `/api/v1/auth/firebase/exchange` por una sesión Rhino controlable desde el CRM.

## Tenencia

Los registros de negocio contienen `partner_id`. El middleware crea un `UserContextDTO` por solicitud. Los repositorios y servicios aplican aislamiento por socio.

Roles principales:

- `SUPER_ADMIN`: administración global.
- `PARTNER_ADMIN`: administración de su socio.
- `LOCATION_MANAGER`: operación de una ubicación.
- `TEAM_LEADER`: acceso a equipos propios e integrantes.
- `ADVISOR`: acceso a prospectos y tareas asignados.
- `OPERATOR`: operación limitada.

## Control de seguridad

Cada solicitud autenticada comprueba:

1. Token y sesión no revocados.
2. Sesión no expirada.
3. Usuario activo.
4. Socio activo.
5. Ubicación activa, cuando corresponda.
6. Permiso requerido.
7. Alcance de socio, equipo o asesor.

Una suspensión no borra datos. El administrador puede reactivar la entidad posteriormente.

## Básculas

Las mediciones usan firma HMAC, marca de tiempo y control de repetición. El backend comprueba:

- Báscula activa.
- Socio activo.
- Ubicación activa.
- Firma válida.
- Ventana de tiempo permitida.

Las credenciales MySQL nunca se almacenan en la báscula ni en Android.

## Consistencia

Ventas, inventario, finanzas y eventos se escriben en transacciones SQL. Los eventos pendientes se guardan en `event_outbox` y se procesan mediante el scheduler.

## Extensibilidad

La aplicación es un monolito modular. Las ampliaciones deben añadirse por módulo, migraciones aditivas, endpoints compatibles y feature flags. La API actual se mantiene bajo `/api/v1`; cambios incompatibles deben publicarse en una versión nueva.
