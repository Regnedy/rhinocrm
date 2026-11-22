# Actualización RHINO CRM: eventos en vivo, WhatsApp pendiente y wizard nutricional

Fecha del paquete: 24 de julio de 2026.

## Alcance

Esta actualización conecta RHINO Kiosco, RHINO CRM y la app Android nativa en un solo flujo operativo:

1. La app RHINO CRM inicia en **Eventos**.
2. Un evento activo actualiza sus prospectos cada 8 segundos.
3. RHINO Kiosco puede registrar una medición sin solicitar WhatsApp en ese momento.
4. El prospecto queda con estado **WhatsApp pendiente**.
5. Desde la app, el colaborador muestra un QR individual.
6. La página pública permite descargar la vCard de RHINO, esperar cinco minutos y abrir WhatsApp con un mensaje preparado.
7. Si el webhook de WhatsApp Cloud está activo, el número se vincula automáticamente al contacto mediante su código RHINO.
8. Existe confirmación manual como respaldo cuando el webhook no está disponible.
9. Desde el prospecto se puede abrir **Ver pesaje** y consultar composición corporal e historial.
10. **Generar plan alimenticio** abre un wizard móvil, guarda el perfil existente del motor nutricional, genera el PDF actual de RHINO y permite compartirlo por WhatsApp después de confirmar el número.

El QR no contiene datos de pesaje. Contiene únicamente una URL temporal de vinculación. Expira en 24 horas y puede regenerarse.

## Dependencias

Antes de instalar, deben estar aplicadas estas migraciones:

```text
2026_07_24_000300_add_collaborative_event_party
2026_07_24_000400_create_mobile_app_billing_foundation
```

La actualización agrega:

```text
2026_07_24_000500_add_mobile_event_whatsapp_and_nutrition_wizard
```

## Facturación

Este paquete no activa cobros. Mantén:

```env
BILLING_ENABLED=false
SUBSCRIPTION_ENFORCEMENT_ENABLED=false
STRIPE_BILLING_ENABLED=false
GOOGLE_PLAY_BILLING_ENABLED=false
APPLE_BILLING_ENABLED=false
```

## Configuración necesaria

En `.env` configura la URL pública HTTPS del CRM y el número oficial que recibirá los mensajes:

```env
APP_URL=https://TU-DOMINIO
RHINO_WHATSAPP_BUSINESS_PHONE=CODIGOPAISYNUMERO
RHINO_WHATSAPP_CONTACT_NAME=RHINO CRM
```

`RHINO_WHATSAPP_BUSINESS_PHONE` debe contener únicamente dígitos, sin `+`, espacios ni guiones.

La confirmación automática requiere que la integración existente de WhatsApp Cloud entregue los mensajes entrantes al webhook de RHINO. Sin ese webhook, el flujo sigue funcionando mediante **Confirmar número manualmente** en la app.

## Respaldo por SSH

Sube el ZIP incremental a `/home2/manue221/rhinocrm` y ejecuta:

```bash
set -euo pipefail

cd /home2/manue221/rhinocrm

UPDATE_ZIP="Rhino_CRM_Mobile_Event_Live_Whatsapp_QR_Nutrition_Wizard_Backend_Update_2026-07-24.zip"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="/home2/manue221/backups/rhinocrm/mobile-event-flow-${STAMP}"

 test -f "$UPDATE_ZIP" || {
    echo "ERROR: No se encontró $UPDATE_ZIP"
    exit 1
}

unzip -t "$UPDATE_ZIP"
mkdir -p "$BACKUP_DIR"
cp -a .env "$BACKUP_DIR/.env"

tar \
    --exclude='./vendor' \
    --exclude='./node_modules' \
    --exclude='./storage/logs/*' \
    --exclude='./storage/framework/cache/*' \
    --exclude='./storage/framework/sessions/*' \
    --exclude='./storage/framework/views/*' \
    --exclude='./*.zip' \
    -czf "$BACKUP_DIR/code-before-update.tar.gz" .

mapfile -t DB_CFG < <(php -r '
require getcwd()."/vendor/autoload.php";
$app = require getcwd()."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = config("database.default");
$config = config("database.connections.".$connection);
foreach (["driver", "host", "port", "database", "username", "password"] as $key) {
    echo base64_encode((string) ($config[$key] ?? "")).PHP_EOL;
}
')

DB_DRIVER="$(printf '%s' "${DB_CFG[0]}" | base64 --decode)"
DB_HOST="$(printf '%s' "${DB_CFG[1]}" | base64 --decode)"
DB_PORT="$(printf '%s' "${DB_CFG[2]}" | base64 --decode)"
DB_NAME="$(printf '%s' "${DB_CFG[3]}" | base64 --decode)"
DB_USER="$(printf '%s' "${DB_CFG[4]}" | base64 --decode)"
DB_PASS="$(printf '%s' "${DB_CFG[5]}" | base64 --decode)"
DUMP_BIN="$(command -v mariadb-dump || command -v mysqldump || true)"

test "$DB_DRIVER" = "mysql" || { echo "ERROR: La base no es MySQL/MariaDB"; exit 1; }
test -n "$DUMP_BIN" || { echo "ERROR: Falta mariadb-dump o mysqldump"; exit 1; }

MYSQL_PWD="$DB_PASS" "$DUMP_BIN" \
    --single-transaction --quick --skip-lock-tables --no-tablespaces \
    --default-character-set=utf8mb4 \
    -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USER" "$DB_NAME" \
    | gzip > "$BACKUP_DIR/database-before-update.sql.gz"

gzip -t "$BACKUP_DIR/database-before-update.sql.gz"
tar -tzf "$BACKUP_DIR/code-before-update.tar.gz" >/dev/null

echo "RESPALDO COMPLETADO: $BACKUP_DIR"
```

## Instalación

```bash
set -euo pipefail

cd /home2/manue221/rhinocrm
UPDATE_ZIP="Rhino_CRM_Mobile_Event_Live_Whatsapp_QR_Nutrition_Wizard_Backend_Update_2026-07-24.zip"

php artisan migrate:status | grep '2026_07_24_000400' || {
    echo "ERROR: primero instala la base móvil 000400"
    exit 1
}

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
    echo "ACTUALIZACIÓN INSTALADA"
else
    echo "ERROR: RHINO permanece en mantenimiento"
    exit 1
fi
```

No ejecutes `composer update`.

## Verificación por SSH

```bash
cd /home2/manue221/rhinocrm

php artisan migrate:status | grep '2026_07_24_000500'
php artisan route:list --path=connect
php artisan route:list --path=whatsapp-capture
php -l app/Modules/Campaigns/Services/CampaignWhatsappCaptureService.php
php -l app/Modules/Campaigns/Controllers/WhatsappCapturePageController.php

grep -E '^(APP_URL|RHINO_WHATSAPP_BUSINESS_PHONE|RHINO_WHATSAPP_CONTACT_NAME|BILLING_ENABLED|SUBSCRIPTION_ENFORCEMENT_ENABLED)=' .env
```

## Prueba funcional recomendada

1. Activa un evento.
2. En Kiosko 2.5.3 realiza un pesaje.
3. Pulsa **Registrar en evento / WhatsApp**, deja el número vacío y acepta la vinculación.
4. Abre RHINO CRM App 1.1.0 y entra al evento.
5. Confirma que el prospecto aparezca como **WhatsApp pendiente**.
6. Abre el prospecto y pulsa **Mostrar QR**.
7. Escanea el QR con otro teléfono, descarga la vCard y completa el mensaje preparado.
8. Pulsa **Actualizar confirmación** en la app.
9. Abre **Ver pesaje**.
10. Ejecuta el wizard **Generar plan alimenticio**.
11. Comparte el PDF desde WhatsApp.

## Archivos modificados

Consulta `UPDATE-MANIFEST-MOBILE-EVENT-WHATSAPP-NUTRITION-2026-07-24.txt` dentro del paquete.
