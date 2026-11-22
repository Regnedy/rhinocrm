RHINO CRM - ACTUALIZACION PANEL WEB/PWA 2026.07.21

Esta actualización NO crea, elimina ni reinicia tablas.

1. Respalde los archivos y MySQL.
2. Suba este ZIP a /home2/manue221/rhinocrm
3. Extráigalo en esa misma carpeta y permita sobrescribir los archivos incluidos.
4. No sobrescriba ni elimine .env, vendor/ o storage/.
5. Ejecute por SSH:

   cd /home2/manue221/rhinocrm
   composer dump-autoload --optimize
   php artisan optimize:clear
   php artisan route:list | grep -E "crm|pipeline/stages|timeline"

6. Abra una ventana privada y visite:

   https://ventas.activa7.com/

La respuesta JSON de estado se movió a:

   https://ventas.activa7.com/health

No ejecute migrate:fresh, migrate:reset, migrate:rollback ni db:wipe.
No hay migraciones nuevas en este parche.
