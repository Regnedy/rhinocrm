# Actualización Rhino CRM Web 2026.07.21

Esta versión agrega el panel operativo responsive y PWA sin reemplazar ni reiniciar la base de datos.

## Funciones

- Inicio de sesión local revocable.
- Dashboard global, del socio y del asesor.
- Alta, edición y consulta de prospectos.
- Agenda de citas, llamadas, WhatsApp, mediciones, cotizaciones y seguimientos.
- Pipeline ampliado y conversión del prospecto a cliente al cerrar la etapa.
- Expediente con timeline, notas y mediciones.
- Clientes, equipos, metas, usuarios, sesiones y básculas.
- Administración global de socios.
- Instalación de la aplicación web en la pantalla de inicio de Android.

## Actualización de una instalación existente

1. Realizar respaldo de archivos y MySQL.
2. Extraer el parche en la raíz del proyecto conservando `.env`, `storage/` y `vendor/`.
3. Ejecutar:

```bash
cd /home2/manue221/rhinocrm
php artisan optimize:clear
composer dump-autoload --optimize
```

No hay migraciones nuevas en esta actualización. No se debe ejecutar `migrate:fresh`, `migrate:reset`, `migrate:rollback` ni `db:wipe`.

## Acceso

- Panel: `https://ventas.activa7.com/`
- Estado JSON: `https://ventas.activa7.com/health`
- Consola técnica: `https://ventas.activa7.com/admin/developer-console`

## Android

Desde Chrome, abrir el panel y usar **Instalar aplicación** o **Agregar a pantalla principal**. Esta PWA usa la API Laravel y MySQL. Firebase Authentication y FCM siguen pendientes para la futura aplicación nativa.
