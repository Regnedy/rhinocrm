# Actualización RHINO CRM: administración de permisos de socios

Fecha: 23 de julio de 2026

## Objetivo

Esta actualización agrega un panel exclusivo para `SUPER_ADMIN` desde el cual se controla el acceso de cada socio a los módulos y operaciones del CRM.

El panel aparece en el menú administrativo como:

```text
Administración global > Permisos
```

También se agrega el botón **Permisos** en cada fila de la pantalla **Socios**.

## Funcionamiento

Los permisos configurados para un socio actúan como un límite máximo para toda su organización:

- `SUPER_ADMIN` no queda limitado por estas políticas.
- Los usuarios del socio solo conservan los permisos autorizados tanto por su rol como por la política del socio.
- Un permiso deshabilitado por el administrador global no puede ser recuperado por `PARTNER_ADMIN`, coordinadores, responsables u operadores.
- Los permisos individuales y los roles todavía pueden restringir más el acceso; la política del socio no concede permisos que el usuario no tuviera previamente.
- Si no existe una política personalizada, el socio conserva su comportamiento actual sin restricciones adicionales.

El catálogo incluye 61 permisos agrupados por:

- Usuarios y acceso.
- Organización del socio.
- Aplicación móvil y dispositivos.
- Eventos y campañas.
- Nutrición.
- Seguimiento nutricional.
- Prospectos, clientes y agenda.
- Productos, inventario y ventas.
- Automatización y reportes.

Dentro de Nutrición se pueden controlar por separado:

```text
nutrition.read
nutrition.profiles.update
nutrition.plans.generate
nutrition.plans.update
nutrition.pills.read
nutrition.pills.manage
nutrition.templates.manage
nutrition.ai.manage
```

Por ejemplo, para permitir que un socio edite comidas y plantillas de dietas deben estar habilitados:

```text
nutrition.read
nutrition.pills.read
nutrition.pills.manage
nutrition.templates.manage
```

Las dependencias necesarias se activan automáticamente. Si se deshabilita un permiso base, también se deshabilitan las operaciones que dependen de él.

## Auditoría

Cada cambio queda registrado en `security_audit_logs` con:

- Administrador que realizó el cambio.
- Socio afectado.
- Permisos anteriores y nuevos.
- Motivo del cambio.
- Fecha e IP administrativa.

## Base de datos

Se agrega la tabla:

```text
partner_permission_policies
```

La tabla almacena una política personalizada por socio. Eliminar la política desde el panel restaura el acceso predeterminado y no elimina usuarios ni información operativa.

## Requisito previo

Este paquete está preparado para instalarse sobre la versión que ya contiene la actualización de comidas, plantillas de dietas y PDF:

```text
2026_07_23_000100_add_nutrition_library_management
```

## Instalación

Coloca el ZIP en la raíz del proyecto:

```text
/home2/manue221/rhinocrm/
```

Después ejecuta:

```bash
cd /home2/manue221/rhinocrm

UPDATE_ZIP="Rhino_CRM_Partner_Permissions_Admin_Update_2026-07-23.zip"

unzip -t "$UPDATE_ZIP"
php artisan down --retry=60

if unzip -o "$UPDATE_ZIP" \
    && composer dump-autoload --no-dev --optimize \
    && php artisan migrate --force \
    && php artisan optimize:clear \
    && chmod -R ug+rwX storage bootstrap/cache
then
    php artisan up
    echo "INSTALACIÓN COMPLETADA"
else
    echo "ERROR DURANTE LA INSTALACIÓN"
    echo "El CRM permanece en modo mantenimiento."
    exit 1
fi
```

## Verificación por SSH

```bash
cd /home2/manue221/rhinocrm

php artisan migrate:status | grep '2026_07_23_000200'
php artisan route:list | grep -E 'admin/(permissions/catalog|partners/.+/permissions)'
php -l app/Modules/Auth/Services/PartnerPermissionPolicyService.php
php -l app/Modules/Administration/Services/PartnerPermissionAdminService.php
```

La migración debe aparecer como ejecutada:

```text
2026_07_23_000200_create_partner_permission_policies
```

En la interfaz, inicia sesión como `SUPER_ADMIN` y verifica:

```text
Menú lateral > Permisos
Socios > botón Permisos
```

## Aplicación de cambios

La validación del servidor se actualiza inmediatamente en cada solicitud. Un socio que ya tenga abierta la interfaz debe recargar la página o volver a iniciar sesión para que el menú visible refleje los nuevos permisos.

## Restauración

Para retirar únicamente esta actualización de base de datos:

```bash
php artisan migrate:rollback --step=1 --force
php artisan optimize:clear
```

Ese comando solo debe usarse si esta migración es la última ejecutada. Para restaurar también el código, utiliza el respaldo realizado antes de descomprimir el paquete.

## Archivos incluidos

- `app/Console/Commands/FirebaseProvisionUserCommand.php`
- `app/Modules/Administration/Controllers/AdminSecurityController.php`
- `app/Modules/Administration/Services/PartnerPermissionAdminService.php`
- `app/Modules/Auth/Services/AccessTokenService.php`
- `app/Modules/Auth/Services/ApiSessionService.php`
- `app/Modules/Auth/Services/FirebaseIdentityLinkService.php`
- `app/Modules/Auth/Services/PartnerPermissionPolicyService.php`
- `app/Modules/Auth/Services/RolePermissionService.php`
- `app/Modules/Auth/Services/UserAdminService.php`
- `database/migrations/2026_07_23_000200_create_partner_permission_policies.php`
- `public/assets/rhino-crm.css`
- `public/assets/rhino-crm.js`
- `routes/api_v1.php`

## Validaciones realizadas

- Sintaxis PHP de todos los archivos modificados.
- Sintaxis JavaScript mediante `node --check`.
- Registro de las rutas administrativas mediante `php artisan route:list`.
- Verificación del catálogo: cubre todos los permisos actuales de socios, excepto `partners.create`, que permanece reservado para el administrador global.
- Prueba directa de normalización de dependencias; por ejemplo, `nutrition.templates.manage` agrega automáticamente `nutrition.read`.

No se ejecutó la suite completa con SQLite porque el entorno de preparación no dispone del controlador `pdo_sqlite`. Esta limitación no afecta la instalación MySQL/MariaDB del servidor RHINO CRM.
