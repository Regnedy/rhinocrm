# RHINO CRM 2.7.0 — archivos incluidos

Este ZIP es un **paquete de actualización**, no una instalación completa de Laravel. Copia su contenido sobre la raíz del RHINO CRM actual respetando las rutas.

## Archivos modificados

- `app/Modules/CRM/Services/ContactMeasurementIngestionService.php`
- `app/Modules/Campaigns/Controllers/CampaignCollaborationController.php`
- `app/Modules/Campaigns/Services/CampaignCollaborationService.php`
- `app/Modules/Campaigns/Services/CampaignDietOwnershipService.php`
- `app/Modules/Campaigns/Services/CampaignQrJoinService.php`
- `app/Modules/Campaigns/Services/CampaignService.php`
- `app/Modules/IoT/Services/MeasurementIngestionService.php`
- `app/Modules/Mobile/Services/MobileMeasurementService.php`
- `public/assets/rhino-crm.js`
- `routes/api_v1.php`

## Archivos nuevos

- `app/Modules/Devices/Controllers/PersonalDeviceController.php`
- `app/Modules/Devices/Services/PersonalDeviceService.php`
- `app/Modules/Mobile/Controllers/KioskUpdateController.php`
- `app/Modules/Mobile/Services/KioskUpdateService.php`
- `database/migrations/2026_08_26_000200_rhino_270_devices_assignments_updates.php`
- `tests/Standalone/Rhino270ContractTest.php`

También se incluyen `README_INSTALACION.md`, `API_DOCUMENTATION.md` y `MIGRATION_GUIDE.md`.
