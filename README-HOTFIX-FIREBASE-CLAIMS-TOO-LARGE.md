# RHINO CRM — Hotfix Firebase CLAIM_TOO_LARGE

## Problema

Al crear un socio o usuario, RHINO enviaba a Firebase el arreglo completo de permisos como custom claims. El catálogo actual supera el límite de Firebase y la creación del administrador fallaba con `CLAIM_TOO_LARGE`.

## Solución

El hotfix conserva en Firebase únicamente:

- `partner_id`
- `role`
- `location_id`

Los permisos completos permanecen en MySQL y RHINO los valida en el servidor en cada sesión y solicitud. No se eliminan permisos ni cambia el panel administrativo.

## Archivos modificados

- `app/Modules/Auth/Services/UserAdminService.php`
- `app/Modules/Auth/Services/HybridIdentityService.php`
- `app/Modules/Auth/Services/FirebaseIdentityLinkService.php`
- `app/Console/Commands/FirebaseProvisionUserCommand.php`

## Instalación

```bash
cd /home2/manue221/rhinocrm
UPDATE_ZIP="Rhino_CRM_Firebase_Claims_Too_Large_Hotfix_2026-07-24.zip"
unzip -t "$UPDATE_ZIP"
php artisan down --retry=60
unzip -o "$UPDATE_ZIP"
composer dump-autoload --no-dev --optimize
php artisan optimize:clear
touch /home2/manue221/.lsphp_restart.txt
touch /home2/manue221/mod_lsapi_reset_me
php artisan up
```

No requiere migraciones ni `composer update`.

## Socio parcialmente creado

El formulario crea primero el socio y después su administrador. Si apareció `CLAIM_TOO_LARGE`, el socio probablemente ya existe, pero su usuario administrador no.

Después de instalar el hotfix:

1. Entra como `SUPER_ADMIN`.
2. Abre `Socios` y localiza el socio.
3. Pulsa `Usuarios`.
4. Crea el usuario con rol `PARTNER_ADMIN` usando el mismo correo y contraseña introducidos originalmente.

No vuelvas a registrar el mismo Socio ID desde el formulario principal, porque podría marcarlo como duplicado.
