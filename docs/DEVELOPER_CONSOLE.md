# Consola de mantenimiento segura

## Objetivo

La consola permite que `SUPER_ADMIN` consulte el estado técnico de Rhino CRM desde un navegador sin exponer una shell del servidor. Está diseñada para hosting compartido y no necesita permisos root.

URL:

```text
/admin/developer-console
```

## Controles de acceso

La interfaz pública solo contiene el formulario de inicio de sesión. Todos los datos y acciones pasan por `/api/v1/admin/developer-console/*` y exigen:

1. Token Rhino válido.
2. Usuario y socio activos.
3. Rol exacto `SUPER_ADMIN`.
4. Consola habilitada.
5. Dirección IP permitida cuando exista una lista configurada.
6. Límite de solicitudes.

Para restringir por IP:

```env
RHINO_DEV_CONSOLE_ALLOWED_IPS=203.0.113.10,198.51.100.24
```

Para deshabilitar:

```env
RHINO_DEV_CONSOLE_ENABLED=false
```

## Funciones incluidas

### Estado

- PHP y Laravel.
- Conexión y latencia MySQL.
- Migraciones pendientes, sin aplicarlas.
- Cron mediante `scheduler_heartbeat`.
- Trabajos pendientes y fallidos.
- Eventos outbox pendientes y fallidos.
- Permisos de `storage/` y `bootstrap/cache/`.
- Extensiones PHP requeridas.
- Espacio de disco y límites principales de PHP.
- Estado de configuración de Firebase y del asistente IA.

### Logs

Solo se leen:

```text
storage/logs/laravel.log
storage/logs/laravel-*.log
storage/logs/security/*.log
```

No se acepta una ruta proporcionada libremente. La lectura se limita por bytes y líneas. Antes de devolver información se ocultan tokens Rhino, JWT, claves, contraseñas, encabezados Bearer, claves privadas y, por defecto, datos personales básicos.

### Acciones seguras

La consola no acepta texto como comando. Las acciones disponibles están codificadas en `SafeCommandService`:

- Información de Laravel.
- Estado de migraciones.
- Lista de rutas.
- Lista del scheduler.
- Lista de trabajos fallidos.
- Limpiar caché.
- Limpiar cachés compilados.
- Procesar outbox.
- Procesar la cola durante un máximo de 30 segundos.

Las acciones que cambian estado requieren:

- Una frase exacta mostrada en la interfaz.
- La contraseña local de `SUPER_ADMIN`, o una sesión administrativa reciente cuando el usuario no tenga contraseña local.
- Registro en `maintenance_action_logs`.

No están disponibles:

```text
migrate:fresh
migrate:reset
migrate:rollback
db:wipe
SQL arbitrario
comandos de shell
edición directa de .env
instalación de paquetes
```

### Código autorizado

La lectura es únicamente de archivos con extensión permitida dentro de:

```text
app/
routes/
config/
database/migrations/
docs/
```

`realpath()` impide salir de esas raíces mediante `../` o enlaces simbólicos. No se permite leer `.env`, `storage/`, `vendor/`, claves JSON ni otros archivos del servidor.

## Asistente IA opcional

Configuración:

```env
RHINO_DEV_CONSOLE_AI_ENABLED=true
OPENAI_API_BASE=https://api.openai.com/v1
OPENAI_API_KEY=su-clave-en-el-servidor
OPENAI_MODEL=modelo-habilitado-en-su-proyecto
OPENAI_TIMEOUT_SECONDS=60
OPENAI_MAX_OUTPUT_TOKENS=2400
OPENAI_MAX_CONTEXT_CHARS=90000
```

Después:

```bash
php artisan optimize:clear
```

La clave permanece en Laravel y nunca se entrega a Android o al navegador. El backend llama a `/v1/responses` con `store=false`.

El administrador elige explícitamente:

- La pregunta.
- Un log opcional.
- El número de líneas.
- Hasta ocho archivos autorizados.

El sistema elimina secretos antes de enviar el contexto. El asistente solo devuelve un diagnóstico textual y una propuesta. No dispone de herramientas, no escribe archivos, no ejecuta comandos, no accede a MySQL y no aplica cambios.

Cada solicitud se registra en `ai_diagnostic_requests`. La respuesta debe revisarse antes de utilizarse. Para cambios reales se recomienda preparar un ZIP nuevo, probarlo en `staging`, respaldar MySQL y aplicar migraciones aditivas por SSH.

## Heartbeat

El scheduler ejecuta cada minuto:

```text
php artisan system:heartbeat
```

Actualiza `system_settings.scheduler_heartbeat`. Si la consola muestra más de tres minutos sin actividad, revise el cron de cPanel.

## Actualización de una instalación existente

1. Respalde MySQL y archivos.
2. Sustituya el código, conservando `.env` y `storage/app/installed.lock`.
3. Ejecute `composer install --no-dev --optimize-autoloader`.
4. Ejecute `php artisan migrate --force`.
5. Ejecute `php artisan optimize:clear`.
6. Abra `/admin/developer-console` y revise Estado y Auditoría.

La migración de la consola solo crea `maintenance_action_logs` y `ai_diagnostic_requests`; su `down()` no elimina tablas.
