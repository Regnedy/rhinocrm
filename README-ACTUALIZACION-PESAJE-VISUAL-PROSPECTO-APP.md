# Actualización RHINO CRM: pesaje visual desde el prospecto y API para la app

Fecha: 24 de julio de 2026

## Objetivo

Esta actualización sustituye la tabla técnica de biométricos por una vista visual de pesaje dentro del expediente del prospecto y publica un contrato estable para la futura aplicación RHINO CRM.

## Cambios incluidos

- Botón **Pesaje** en la tabla de prospectos cuando existen mediciones.
- Pestaña **Pesaje** dentro del expediente.
- Indicador visual de peso calculado con la estatura registrada.
- Tarjetas desplegables para IMC, grasa corporal, músculo, masa libre de grasa, grasa subcutánea, grasa visceral, agua corporal, músculo esquelético, masa muscular, masa ósea, proteína, metabolismo basal y edad metabólica cuando la báscula los reporta.
- Cambio contra el pesaje anterior, sin calificar automáticamente si subir o bajar es positivo o negativo.
- Historial visual y listado de mediciones.
- Fuente de la medición: evento, báscula, ubicación y usuario capturista cuando estén disponibles.
- Vista compacta dentro de la atención del prospecto en eventos colaborativos.
- Contrato JSON compartido por CRM web, evento colaborativo y futura app móvil.
- Bandera `contact_measurement_dashboard_v1` dentro de `/api/v1/mobile/context`.
- Nueva versión de caché del service worker.

## Contrato para la aplicación móvil

La documentación está en:

```text
docs/api/CONTACT-MEASUREMENT-DASHBOARD-V1.md
```

Endpoints:

```text
GET /api/v1/contacts/{contactId}/measurement-dashboard?limit=30
GET /api/v1/mobile/contacts/{contactId}/measurement-dashboard?limit=30
GET /api/v1/campaigns/{campaignId}/participants/{contactId}/measurement-dashboard
```

El workspace del participante de un evento también incluye:

```text
measurement_dashboard
```

## Requisitos previos

Debe estar instalada la actualización de eventos colaborativos y su migración:

```text
2026_07_24_000300_add_collaborative_event_party
```

Esta actualización no contiene migraciones nuevas y no modifica datos.

## Instalación por SSH

Subir el ZIP a:

```text
/home2/manue221/rhinocrm/
```

Después ejecutar:

```bash
set -euo pipefail

cd /home2/manue221/rhinocrm

UPDATE_ZIP="Rhino_CRM_Prospect_Weighing_Visual_Mobile_API_Update_2026-07-24.zip"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="/home2/manue221/backups/rhinocrm/prospect-weighing-${STAMP}"

test -f "$UPDATE_ZIP" || {
    echo "ERROR: No se encontró $UPDATE_ZIP"
    exit 1
}

unzip -t "$UPDATE_ZIP"

php artisan migrate:status | grep '2026_07_24_000300' || {
    echo "ERROR: Primero instala la actualización de eventos colaborativos."
    exit 1
}

mkdir -p "$BACKUP_DIR"

tar -czf "$BACKUP_DIR/files-before-update.tar.gz" \
    routes/api_v1.php \
    app/Modules/CRM/Controllers/ContactController.php \
    app/Modules/CRM/Services/ContactManagementService.php \
    app/Modules/Mobile/Controllers/MobileController.php \
    app/Modules/Campaigns/Controllers/CampaignCollaborationController.php \
    app/Modules/Campaigns/Services/CampaignCollaborationService.php \
    public/assets/rhino-crm.js \
    public/assets/rhino-crm.css \
    public/sw.js

php artisan down --retry=60

if unzip -o "$UPDATE_ZIP" \
    && composer dump-autoload --no-dev --optimize \
    && php -l app/Modules/CRM/Services/ContactMeasurementPresentationService.php \
    && php -l app/Modules/CRM/Services/ContactManagementService.php \
    && php -l app/Modules/Campaigns/Services/CampaignCollaborationService.php \
    && php artisan optimize:clear \
    && chmod -R ug+rwX storage bootstrap/cache \
    && chmod 644 public/assets/rhino-crm.js public/assets/rhino-crm.css public/sw.js
then
    touch /home2/manue221/.lsphp_restart.txt
    touch /home2/manue221/mod_lsapi_reset_me
    php artisan up
    echo
    echo "INSTALACIÓN COMPLETADA"
    echo "Respaldo: $BACKUP_DIR"
else
    echo
    echo "ERROR DURANTE LA INSTALACIÓN"
    echo "El CRM permanece en modo mantenimiento."
    exit 1
fi
```

No ejecutar `composer update` ni `php artisan migrate` para este paquete.

## Verificación

```bash
cd /home2/manue221/rhinocrm

php artisan route:list --path=measurement-dashboard

grep -n "rhino-shell-v17-contact-weighing-dashboard" public/sw.js

grep -n "contact-weight" public/assets/rhino-crm.js

grep -n "contact_measurement_dashboard_v1" \
    app/Modules/Mobile/Controllers/MobileController.php

php -l app/Modules/CRM/Services/ContactMeasurementPresentationService.php
php -l app/Modules/CRM/Services/ContactManagementService.php
php -l app/Modules/Campaigns/Services/CampaignCollaborationService.php
```

Deben aparecer tres rutas `measurement-dashboard`.

## Validación en el CRM

1. Cerrar todas las pestañas del CRM.
2. Abrir nuevamente e iniciar sesión.
3. Entrar en **Prospectos**.
4. Abrir un prospecto con pesajes o pulsar el botón **Pesaje**.
5. Verificar el indicador de peso, las métricas y el historial.
6. Abrir un evento colaborativo y revisar la atención de una persona con pesaje.

Si el navegador conserva la interfaz anterior, abrir una ventana de incógnito o borrar el service worker del dominio.

## Notas funcionales

- La pantalla muestra únicamente métricas presentes en la báscula.
- El IMC puede calcularse desde peso y estatura cuando la báscula no lo entrega.
- La masa libre de grasa puede estimarse desde peso y grasa corporal cuando no llega explícitamente; el campo queda marcado como `derived`.
- Los valores diferentes a peso e IMC se muestran sin clasificaciones clínicas automáticas.
- La app debe ignorar códigos de métricas desconocidos para conservar compatibilidad con versiones futuras.
