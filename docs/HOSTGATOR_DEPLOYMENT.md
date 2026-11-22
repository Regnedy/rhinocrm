# Instalación en HostGator o hosting compartido

## 1. Crear una base de datos

Desde cPanel cree:

- Una base MySQL vacía o una base Rhino existente respaldada.
- Un usuario MySQL.
- Todos los privilegios del usuario sobre esa base.

No use la misma base de otra aplicación si contiene tablas con nombres como `users`, `partners`, `contacts`, `sales` o `jobs` y una estructura distinta.

## 2. Subir el proyecto

La configuración recomendada es:

```text
/home/usuario/rhino-crm/       Código completo
/public_html/crm/              Document root apuntando a rhino-crm/public
```

En un subdominio de cPanel, establezca el document root en:

```text
/home/usuario/rhino-crm/public
```

No exponga `.env`, `storage/`, `vendor/` ni las credenciales de Firebase dentro de una carpeta pública independiente.

## 3. Instalar dependencias

Por SSH:

```bash
cd /home/usuario/rhino-crm
php -v
composer install --no-dev --prefer-dist --optimize-autoloader
```

Esta edición no solicita `ext-grpc` ni `ext-protobuf`.

## 4. Permisos

Las carpetas siguientes deben ser escribibles por PHP:

```bash
chmod -R 775 storage bootstrap/cache
```

No use `777` salvo diagnóstico temporal y solo cuando el proveedor lo exija.

## 5. Ejecutar el asistente

Abra:

```text
https://crm.su-dominio.example/install.php
```

El asistente:

1. Comprueba PHP y extensiones.
2. Prueba la conexión MySQL.
3. Solicita confirmación de respaldo.
4. Escribe `.env` de forma atómica.
5. Ejecuta `php artisan migrate --force`.
6. Ejecuta `php artisan rhino:bootstrap --force`.
7. Crea o actualiza exclusivamente una cuenta `SUPER_ADMIN` ya reconocida como tal.
8. Registra el despliegue.
9. Crea `storage/app/installed.lock`.

No ejecuta operaciones destructivas.

## 6. Cron

En cPanel → Cron Jobs, cada minuto:

```bash
cd /home/usuario/rhino-crm && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Ajuste la ruta de PHP según el hosting. El scheduler procesa:

- Cola de base de datos durante periodos cortos.
- Transactional Outbox.
- Alertas de tareas vencidas.
- Heartbeat de cron para la consola de mantenimiento.

## 7. Verificación

Pruebe:

```text
GET https://crm.su-dominio.example/
GET https://crm.su-dominio.example/up
```

Para iniciar sesión:

```http
POST /api/v1/auth/login
Content-Type: application/json

{
  "email": "administrador@example.com",
  "password": "contraseña-del-instalador",
  "device_name": "Panel administrativo",
  "platform": "WEB"
}
```

Guarde el `access_token` y envíelo como:

```http
Authorization: Bearer rhino_...
Accept: application/json
```

## 8. Actualizaciones futuras

Antes de actualizar:

1. Respalde MySQL y los archivos.
2. Suba el código nuevo sin reemplazar `.env` ni `storage/app/installed.lock`.
3. Ejecute `composer install --no-dev --optimize-autoloader`.
4. Ejecute `php artisan migrate --force`.
5. Ejecute `php artisan optimize:clear`.
6. Revise `storage/logs/laravel.log`.

Nunca ejecute `migrate:fresh`, `reset`, `rollback` o `db:wipe` en producción.


## 9. Consola de mantenimiento

Abra:

```text
https://crm.su-dominio.example/admin/developer-console
```

Inicie sesión con la cuenta `SUPER_ADMIN` creada por el instalador. Para limitarla a direcciones IP conocidas:

```env
RHINO_DEV_CONSOLE_ALLOWED_IPS=203.0.113.10,198.51.100.24
```

Después de cambiar `.env` ejecute por SSH:

```bash
php artisan optimize:clear
```

Para deshabilitar completamente la consola:

```env
RHINO_DEV_CONSOLE_ENABLED=false
```

La consola usa `Artisan::call()` exclusivamente con una lista interna de acciones. No depende de `exec`, `shell_exec`, `system`, `passthru`, `proc_open` ni acceso root.

La integración IA es opcional. Guarde `OPENAI_API_KEY` solo en `.env` fuera de la carpeta pública; no la coloque en Android ni en JavaScript. Consulte `docs/DEVELOPER_CONSOLE.md`.
