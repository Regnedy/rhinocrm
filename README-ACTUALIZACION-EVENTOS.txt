RHINO CRM — ACTUALIZACIÓN DE EVENTOS Y MINICAMPAÑAS

1. Respalde MySQL y la carpeta /home2/manue221/rhinocrm.
2. Extraiga el ZIP sobre /home2/manue221/rhinocrm y permita sobrescribir.
3. NO elimine ni sustituya .env, vendor/ ni storage/.
4. Ejecute:

   cd /home2/manue221/rhinocrm
   composer dump-autoload --optimize
   grep -q '^RHINO_VERSION=' .env && sed -i 's/^RHINO_VERSION=.*/RHINO_VERSION=2026.07.21-events.1/' .env || echo 'RHINO_VERSION=2026.07.21-events.1' >> .env
   php artisan migrate --force
   php artisan optimize:clear
   php artisan route:list | grep campaigns

5. Cierre y vuelva a abrir la PWA o haga una recarga forzada.
6. Entre como administrador del socio. El menú nuevo se llama "Eventos".

Esta actualización crea únicamente tablas y columnas aditivas. No borra datos.
No use migrate:fresh, migrate:reset, migrate:rollback ni db:wipe.
