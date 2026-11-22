RHINO CRM — ACTUALIZACIÓN MÓVIL / KIOSCO
Fecha: 2026-07-22

OBJETIVO
- Conectar RHINO Kiosco Android con eventos y prospectos.
- Mantener Firebase Email/Password para los usuarios del socio.
- Preparar inventario y finanzas sin activarlos todavía.

INSTALACIÓN
1. Respaldar MySQL y archivos.
2. Extraer este ZIP en la raíz de /home2/manue221/rhinocrm.
3. Ejecutar:

   cd /home2/manue221/rhinocrm
   composer dump-autoload --optimize
   php artisan migrate --force
   php artisan optimize:clear
   php artisan route:list | grep -E 'mobile/context|mobile/measurements|auth/firebase/exchange'

Migración esperada:
2026_07_22_000400_prepare_mobile_kiosk_inventory_finance ... DONE

NO ejecutar migrate:fresh, reset, rollback ni db:wipe.

CONFIGURACIÓN
Agregar o conservar en .env:

RHINO_AUTH_DRIVER=hybrid
FIREBASE_AUTH_ENABLED=true
FIREBASE_WEB_GOOGLE_ENABLED=false
RHINO_MOBILE_REQUIRE_ACTIVE_CAMPAIGN=true
RHINO_VERSION=2026.07.22-mobile-kiosk.1

Después ejecutar:

php artisan optimize:clear
php artisan rhino:firebase-check

RUTAS NUEVAS
GET  /api/v1/mobile/context?device_id=<MAC_O_ID>
POST /api/v1/mobile/measurements

La app también reutiliza:
POST /api/v1/auth/firebase/exchange
POST /api/v1/devices
GET/POST/PATCH /api/v1/campaigns
POST /api/v1/campaigns/{id}/activate

INVENTARIO Y FINANZAS
Se preparan, pero permanecen desactivados:
- inventory_accounts
- inventory_ledger_entries
- finance_ledger_entries
- inventory_ledger_v2=false
- finance_reports_v2=false

No cambia todavía el comportamiento de productos, ventas, inventario ni finanzas.
