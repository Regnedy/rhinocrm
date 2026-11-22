# RHINO CRM — Hotfix de conciliación de correo existente para socios

Fecha: 2026-07-26

## Problema corregido

Al crear la cuenta principal de un socio incompleto, Rhino detenía el proceso con:

```text
El correo ya está registrado en Rhino CRM.
```

Esto ocurre cuando una cuenta creada en un intento anterior quedó asociada a otro socio. El hotfix agrega una comprobación previa y permite que `SUPER_ADMIN` transfiera desde la pantalla **Socios** una cuenta sin uso previo, sin entrar por SSH.

## Comportamiento nuevo

1. Rhino comprueba el correo antes de crear la cuenta.
2. Si no existe, crea MySQL + Firebase normalmente.
3. Si existe, muestra el socio actual y solicita confirmación.
4. Para transferir se debe escribir `TRANSFERIR`.
5. Rhino actualiza socio, ubicación, rol, permisos, contraseña y claims de Firebase.
6. Cierra sesiones anteriores y envía la verificación de correo.
7. La transferencia queda registrada en auditoría.

## Protecciones

La interfaz no permite transferir:

- cuentas `SUPER_ADMIN`;
- la única cuenta principal de otro socio;
- cuentas que ya iniciaron sesión;
- cuentas con sesiones activas.

En esos casos debe utilizarse otro correo para evitar mezclar datos entre organizaciones.

## Archivos modificados

- `app/Modules/Partners/Services/PartnerPrimaryAccessService.php`
- `app/Modules/Administration/Controllers/AdminSecurityController.php`
- `routes/api_v1.php`
- `public/assets/rhino-crm.js`
- `public/sw.js`

No incluye migraciones.

## Requisitos

Debe estar instalada la actualización:

```text
2026_07_26_000600_add_partner_primary_access_and_firebase_verification
```

## Instalación

Colocar el ZIP en `/home2/manue221/rhinocrm` y ejecutar:

```bash
set -euo pipefail

cd /home2/manue221/rhinocrm

UPDATE_ZIP="Rhino_CRM_Partner_Existing_Email_Reconciliation_Hotfix_2026-07-26.zip"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="/home2/manue221/backups/rhinocrm/partner-email-reconciliation-${STAMP}"

test -f "$UPDATE_ZIP" || {
    echo "ERROR: No se encontró $UPDATE_ZIP"
    exit 1
}

php artisan migrate:status | grep '2026_07_26_000600' || {
    echo "ERROR: Primero instala la actualización de alta automática de socios."
    exit 1
}

unzip -t "$UPDATE_ZIP"
mkdir -p "$BACKUP_DIR"

tar -czf "$BACKUP_DIR/files-before-hotfix.tar.gz" \
    app/Modules/Partners/Services/PartnerPrimaryAccessService.php \
    app/Modules/Administration/Controllers/AdminSecurityController.php \
    routes/api_v1.php \
    public/assets/rhino-crm.js \
    public/sw.js

php artisan down --retry=60

if unzip -o "$UPDATE_ZIP" \
    && php -l app/Modules/Partners/Services/PartnerPrimaryAccessService.php \
    && php -l app/Modules/Administration/Controllers/AdminSecurityController.php \
    && php -l routes/api_v1.php \
    && composer dump-autoload --no-dev --optimize \
    && php artisan optimize:clear \
    && chmod 644 public/assets/rhino-crm.js public/sw.js
then
    touch /home2/manue221/.lsphp_restart.txt
    touch /home2/manue221/mod_lsapi_reset_me

    php artisan up

    echo "HOTFIX INSTALADO"
    echo "Respaldo: $BACKUP_DIR"
else
    echo "ERROR: Rhino CRM permanece en mantenimiento."
    exit 1
fi
```

No ejecutar `composer update` ni migraciones nuevas.

## Verificación

```bash
cd /home2/manue221/rhinocrm

php artisan route:list | grep -E \
'primary-access/(email-status|transfer-existing)'

grep -n "rhino-shell-v19-partner-email-reconciliation" public/sw.js

grep -n "Para confirmar escribe TRANSFERIR" public/assets/rhino-crm.js
```

## Reparar a Emma Munguia

1. Cerrar todas las pestañas de Rhino CRM y volver a iniciar sesión como `SUPER_ADMIN`.
2. Abrir `Socios → Emma Munguia → Administrar`.
3. Capturar el mismo correo y una contraseña temporal nueva.
4. Rhino mostrará a qué socio pertenece actualmente el correo.
5. Confirmar la transferencia y escribir `TRANSFERIR`.
6. Rhino enviará la verificación de Firebase automáticamente.

Si la cuenta ya inició sesión o es la única cuenta principal de su socio actual, Rhino bloqueará la transferencia y solicitará otro correo.
