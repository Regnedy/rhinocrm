# Actualización: backend de Rhino CRM Android Native y facturación desactivada

Fecha: 24 de julio de 2026

Esta actualización prepara Rhino CRM para la aplicación Android nativa con identificador:

```text
com.activa7.rhinocrm.mobile
```

RHINO Kiosco conserva su identificador existente `com.activa7.rhinocrm`; ambas aplicaciones pueden instalarse en el mismo dispositivo.

## Incluye

- Bootstrap y dashboard móvil.
- Notificaciones de invitaciones y seguimientos.
- Registro de instalaciones móviles.
- Contrato central de acceso.
- Plan `rhino_crm_access` en estado `DRAFT` con precio de referencia de 9.99 USD mensuales.
- Tablas de facturación futura sin proveedores activos.
- Endpoints administrativos de preparación para `SUPER_ADMIN`.
- Cobro y bloqueo desactivados por defecto.

## Dependencias

Debe estar instalada la migración de eventos colaborativos:

```text
2026_07_24_000300_add_collaborative_event_party
```

También debe estar instalada la actualización de pesaje visual.

## Instalación

Sube este ZIP a `/home2/manue221/rhinocrm/`, crea un respaldo de código y base de datos, y ejecuta:

```bash
set -euo pipefail
cd /home2/manue221/rhinocrm
UPDATE_ZIP="Rhino_CRM_Mobile_App_Native_Backend_Foundation_Update_2026-07-24.zip"

php artisan down --retry=60

if unzip -o "$UPDATE_ZIP" \
    && composer dump-autoload --no-dev --optimize \
    && php artisan migrate --force \
    && php artisan optimize:clear \
    && chmod -R ug+rwX storage bootstrap/cache
then
    touch /home2/manue221/.lsphp_restart.txt
    touch /home2/manue221/mod_lsapi_reset_me
    php artisan up
    echo "BACKEND MÓVIL INSTALADO"
else
    echo "ERROR: Rhino CRM permanece en mantenimiento."
    exit 1
fi
```

No ejecutes `composer update`.

## Cobros desactivados

En `.env` conserva:

```env
BILLING_ENABLED=false
SUBSCRIPTION_ENFORCEMENT_ENABLED=false
BILLING_DEFAULT_PLAN_CODE=rhino_crm_access
STRIPE_BILLING_ENABLED=false
GOOGLE_PLAY_BILLING_ENABLED=false
APPLE_BILLING_ENABLED=false
RHINO_MOBILE_MIN_VERSION=1.0.0
RHINO_MOBILE_LATEST_VERSION=1.0.0
RHINO_MOBILE_MAINTENANCE=false
```

## Verificación

```bash
cd /home2/manue221/rhinocrm
php artisan migrate:status | grep '2026_07_24_000400'
php artisan route:list --path=mobile/app
php artisan route:list --path=admin/billing
grep -n "com.activa7.rhinocrm.mobile" app/Modules/Mobile/Services/MobileAppService.php
```

Mientras los cobros estén apagados, `GET /api/v1/mobile/app/access` debe indicar:

```text
allowed: true
reason: billing_disabled
status: DEVELOPMENT
enabled: false
enforcement_enabled: false
checkout_available: false
```
