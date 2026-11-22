# Rhino CRM — edición MySQL para hosting compartido

Backend Laravel multitenant para socios, equipos comerciales, prospectos, básculas ESP32, ventas, inventario, workflows y reportes. Esta edición reemplaza la persistencia Firestore por MySQL/MariaDB y no requiere las extensiones PHP gRPC ni Protobuf.

## Alcance de esta entrega

Incluye:

- API JSON versionada en `/api/v1` para web o una aplicación Android personalizada.
- Inicio de sesión local con tokens revocables por dispositivo.
- Estructura preparada para habilitar Firebase Authentication posteriormente.
- Control global `SUPER_ADMIN` sobre socios, usuarios, sesiones, clientes y básculas.
- Equipos, líderes, integrantes, metas e informes de rendimiento.
- Eventos y minicampañas de pesaje, Home Parties y campañas en establecimientos.
- Participantes y mediciones vinculados al evento sin duplicar expedientes.
- Informes por evento con prospectos, pesajes, seguimientos, conversiones, ventas e ingresos.
- Prospectos generados y asignados por usuario/equipo.
- CRM, tareas, timeline, dispositivos, mediciones, productos, inventario, ventas y reembolsos.
- Transactional Outbox, colas mediante base de datos y tareas programadas.
- Generación privada de reportes CSV.
- Instalador web `public/install.php` tipo asistente.
- Panel web responsive/PWA en `/` y `/crm` para dashboard, eventos, prospectos, agenda, pipeline, clientes, equipos, usuarios, sesiones y básculas.
- Consola administrativa segura en `/admin/developer-console` para logs, diagnóstico, acciones autorizadas, lectura limitada de código y asistencia IA opcional.
- Migraciones aditivas: no borran ni reinician tablas en `down()`.

No incluye todavía:

- La aplicación Android nativa; mientras tanto, el panel PWA puede instalarse en Android desde Chrome.
- Credenciales, SDK o configuración de Firebase.
- `vendor/`; debe generarse con Composer en el servidor o localmente.

## Requisitos

- PHP 8.3 o posterior.
- MySQL 8 o MariaDB compatible.
- Extensiones: PDO MySQL, BCMath, Mbstring, OpenSSL, JSON y Fileinfo.
- Composer 2.
- El dominio o subdominio debe apuntar a la carpeta `public/`.

## Instalación resumida

```bash
composer install --no-dev --optimize-autoloader
```

Después abra:

```text
https://su-dominio.example/install.php
```

El asistente prueba el servidor y MySQL, genera `.env`, ejecuta exclusivamente migraciones incrementales, crea el administrador global y genera `storage/app/installed.lock`.

Configure un cron cada minuto:

```bash
cd /ruta/al/crm && php artisan schedule:run >> /dev/null 2>&1
```

La guía completa está en [docs/HOSTGATOR_DEPLOYMENT.md](docs/HOSTGATOR_DEPLOYMENT.md).

## Autenticación

Rhino admite autenticación local, Firebase o modo híbrido. Para Android se recomienda el modo híbrido:

```env
RHINO_AUTH_DRIVER=hybrid
FIREBASE_AUTH_ENABLED=true
FIREBASE_PROJECT_ID=...
FIREBASE_CREDENTIALS=/ruta/privada/firebase-admin.json
FIREBASE_REQUIRE_EMAIL_VERIFIED=true
FIREBASE_AUTO_LINK_BY_EMAIL=true
FIREBASE_ALLOW_DIRECT_API_TOKENS=false
```

Android inicia sesión con Firebase y envía su ID Token a `POST /api/v1/auth/firebase/exchange`. Laravel valida Firebase y emite una sesión Rhino revocable; MySQL continúa siendo la base de datos principal.

Consulte [docs/UPGRADE_FIREBASE_AUTH_2026-07-21.md](docs/UPGRADE_FIREBASE_AUTH_2026-07-21.md).

## Eventos y ubicación técnica

El socio es la unidad operativa principal. La interfaz no obliga a gestionar sucursales. Cada socio conserva internamente una ubicación `Principal` para asociar usuarios, equipos y básculas sin cambiar la arquitectura existente. Las jornadas de pesaje y Home Parties se administran desde **Eventos**. Consulte [docs/UPGRADE_EVENTS_2026-07-21.md](docs/UPGRADE_EVENTS_2026-07-21.md).


## Consola de mantenimiento

Después de instalar y ejecutar las migraciones, abra:

```text
https://su-dominio.example/admin/developer-console
```

La consola exige una sesión `SUPER_ADMIN`. No contiene una shell Linux, no acepta comandos arbitrarios y no permite ejecutar migraciones desde el navegador. Sus acciones están definidas en una lista cerrada y las que modifican estado requieren reautenticación.

La asistencia IA es opcional y permanece deshabilitada hasta configurar en el servidor:

```env
RHINO_DEV_CONSOLE_AI_ENABLED=true
OPENAI_API_KEY=...
OPENAI_MODEL=...
```

Consulte [docs/DEVELOPER_CONSOLE.md](docs/DEVELOPER_CONSOLE.md).

## Política de datos

En producción quedan prohibidos:

```text
php artisan migrate:fresh
php artisan migrate:reset
php artisan migrate:rollback
php artisan db:wipe
```

Toda ampliación debe añadirse con nuevas migraciones compatibles. Consulte [docs/DATABASE_SAFETY.md](docs/DATABASE_SAFETY.md).

## Validación incluida

Ejecute:

```bash
bash scripts/static_audit.sh
```

La validación exacta de esta compilación está documentada en [docs/BUILD_VALIDATION.md](docs/BUILD_VALIDATION.md).

Con dependencias instaladas y una base de pruebas:

```bash
php artisan test
```
