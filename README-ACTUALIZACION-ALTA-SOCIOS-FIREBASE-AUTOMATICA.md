# RHINO CRM — Alta de socios y Firebase automática

Esta actualización elimina el alta en dos pasos. A partir de ahora, **Nuevo socio** crea en una sola operación:

- socio;
- ubicación principal;
- cuenta principal `PARTNER_ADMIN`;
- contraseña local para el modo híbrido;
- identidad Firebase;
- custom claims compactos;
- correo de verificación;
- auditoría y movimientos.

Si Firebase o MySQL fallan durante el alta, el socio no queda creado a medias. Si únicamente falla el envío del correo, la cuenta queda disponible en el panel para reenviar la verificación o copiar el enlace sin usar SSH.

## Cambios de interfaz

Para `SUPER_ADMIN`, la administración se concentra en **Socios**. El menú general **Usuarios** deja de mostrarse.

En cada socio aparece **Administrar**, con estas operaciones:

- crear la cuenta principal de un socio antiguo que quedó incompleto;
- actualizar el estado real de Firebase;
- reenviar la verificación;
- copiar el enlace de verificación;
- configurar o reparar Firebase mediante una nueva contraseña;
- cambiar la contraseña en Rhino y Firebase;
- cerrar las sesiones existentes.

## Configuración

La verificación automática queda habilitada de forma predeterminada. Opcionalmente puedes dejar explícito en `.env`:

```env
FIREBASE_AUTO_SEND_VERIFICATION=true
FIREBASE_VERIFICATION_CONTINUE_URL=https://TU-DOMINIO/crm
FIREBASE_REQUIRE_EMAIL_VERIFIED=true
```

La actualización **no marca correos como verificados automáticamente**. El propietario del correo debe abrir el enlace de Firebase.

## Requisitos

Deben estar instaladas las migraciones anteriores, incluida:

```text
2026_07_24_000500_add_mobile_event_whatsapp_and_nutrition_wizard
```

## Instalación por SSH

Sube el ZIP a `/home2/manue221/rhinocrm` y ejecuta:

```bash
set -euo pipefail

cd /home2/manue221/rhinocrm

UPDATE_ZIP="Rhino_CRM_Partner_Onboarding_Firebase_Automation_Update_2026-07-26.zip"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="/home2/manue221/backups/rhinocrm/partner-firebase-onboarding-${STAMP}"

test -f "$UPDATE_ZIP" || {
    echo "ERROR: No se encontró $UPDATE_ZIP"
    exit 1
}

unzip -t "$UPDATE_ZIP"
mkdir -p "$BACKUP_DIR"

cp -a .env "$BACKUP_DIR/.env"

tar -czf "$BACKUP_DIR/files-before-update.tar.gz" \
    app/Modules/Auth/Services/HybridIdentityService.php \
    app/Modules/Auth/Services/UserAdminService.php \
    app/Modules/Auth/Services/FirebaseIdentityLinkService.php \
    app/Modules/Partners/Services/PartnerProvisioningService.php \
    app/Modules/Partners/Requests/CreatePartnerRequest.php \
    app/Modules/Partners/Controllers/PartnerController.php \
    app/Modules/Administration/Services/AdminSecurityService.php \
    app/Modules/Administration/Controllers/AdminSecurityController.php \
    routes/api_v1.php \
    public/assets/rhino-crm.js \
    public/sw.js

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

test "$DB_DRIVER" = "mysql" || {
    echo "ERROR: La base de datos no es MySQL/MariaDB."
    exit 1
}

test -n "$DUMP_BIN" || {
    echo "ERROR: No se encontró mariadb-dump ni mysqldump."
    exit 1
}

MYSQL_PWD="$DB_PASS" "$DUMP_BIN" \
    --single-transaction \
    --quick \
    --skip-lock-tables \
    --no-tablespaces \
    --default-character-set=utf8mb4 \
    -h "$DB_HOST" \
    -P "${DB_PORT:-3306}" \
    -u "$DB_USER" \
    "$DB_NAME" | gzip > "$BACKUP_DIR/database-before-update.sql.gz"

echo "Respaldo: $BACKUP_DIR"

php artisan down --retry=60

if unzip -o "$UPDATE_ZIP" \
    && composer dump-autoload --no-dev --optimize \
    && php artisan migrate --force \
    && php artisan optimize:clear \
    && chmod -R ug+rwX storage bootstrap/cache \
    && chmod 644 public/assets/rhino-crm.js public/sw.js
then
    touch /home2/manue221/.lsphp_restart.txt
    touch /home2/manue221/mod_lsapi_reset_me
    php artisan up
    echo "ACTUALIZACIÓN INSTALADA"
else
    echo "ERROR: RHINO CRM permanece en mantenimiento."
    exit 1
fi
```

No ejecutes `composer update`.

## Verificación

```bash
cd /home2/manue221/rhinocrm

php artisan migrate:status | grep '2026_07_26_000600'
php artisan route:list --path=primary-access

grep -n "rhino-shell-v18-partner-firebase-onboarding" public/sw.js
grep -n "Crear socio y acceso" public/assets/rhino-crm.js

php -l app/Modules/Auth/Services/HybridIdentityService.php
php -l app/Modules/Auth/Services/UserAdminService.php
php -l app/Modules/Partners/Services/PartnerProvisioningService.php
php -l app/Modules/Partners/Services/PartnerPrimaryAccessService.php
```

## Reparar el socio Emma desde el panel

1. Inicia sesión como `SUPER_ADMIN`.
2. Entra en **Socios**.
3. En la fila de Emma Munguia, presiona **Administrar**.
4. Como no tiene cuenta principal, aparecerá **Crear cuenta principal**.
5. Usa un correo que no pertenezca a otro socio.
6. Rhino creará o reutilizará la identidad Firebase, sincronizará la contraseña y enviará la verificación.
7. Si el correo no llega, abre **Administrar → Copiar enlace** y envíalo por WhatsApp.

El correo actualmente vinculado a otro socio no se transfiere automáticamente. Esa protección evita que una organización pierda su cuenta por error.
