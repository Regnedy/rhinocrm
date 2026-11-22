# Validación de esta compilación

Fecha de preparación: 2026-07-21.

Comprobaciones ejecutadas sobre el código entregado:

- Sintaxis PHP de `app/`, `bootstrap/`, `config/`, `database/`, `routes/`, `tests/` y `public/`.
- Sintaxis JavaScript de `public/assets/rhino-crm.js` mediante `node --check`.
- JSON válido en `composer.json` y `public/manifest.webmanifest`.
- Correspondencia entre rutas API, controladores y métodos públicos.
- Resolución de imports internos `App\\...`.
- Revisión de tablas y columnas del módulo de eventos contra las migraciones.
- Revisión de aislamiento por socio, equipo, usuario, campaña y dispositivo.
- Revisión de la regla que impide activar una báscula en dos eventos simultáneos.
- Revisión de deduplicación de personas por WhatsApp.
- Revisión de atribución única de conversiones y ventas a campañas.
- Ausencia de Firestore, gRPC, Protobuf y comandos destructivos en código ejecutable.
- Ausencia de `.env`, cuentas de servicio y claves privadas en el paquete.
- Ausencia de funciones de shell en el módulo de consola (`exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`).
- Migración de eventos diseñada para reanudarse si una ejecución queda incompleta y con `down()` no destructivo.

No fue posible ejecutar en el entorno de preparación:

- `composer install`, porque Composer no estaba disponible.
- Migraciones contra MySQL/MariaDB real, porque no había servidor de base de datos.
- Pruebas HTTP o de integración, porque `vendor/` no está incluido.

Antes de producción debe realizarse un respaldo y ejecutar:

```bash
composer dump-autoload --optimize
php artisan migrate --force
php artisan test
bash scripts/static_audit.sh
```

Nunca utilice `migrate:fresh`, `migrate:reset`, `migrate:rollback` ni `db:wipe` sobre producción.
