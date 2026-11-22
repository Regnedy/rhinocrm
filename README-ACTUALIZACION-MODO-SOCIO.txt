RHINO CRM — MODO SOCIO INDIVIDUAL / EQUIPO
Fecha: 2026-07-22

OBJETIVO
--------
Simplifica el CRM para que un socio que trabaja solo no tenga que administrar
asesores, equipos ni asignaciones. La estructura interna se conserva para poder
activar el modo equipo posteriormente sin borrar ni migrar nuevamente los datos.

CAMBIOS PRINCIPALES
-------------------
- PARTNER_ADMIN se muestra como "Socio".
- ADVISOR se muestra como "Responsable".
- TEAM_LEADER se muestra como "Coordinador de equipo".
- Modo individual por defecto para socios con un solo usuario y sin equipos.
- Equipos y asignaciones quedan ocultos en modo individual.
- El socio se asigna automáticamente como responsable de:
  * eventos;
  * prospectos;
  * participantes;
  * actividades y seguimientos;
  * pesajes sincronizados desde la APK.
- En "Mi cuenta" se puede activar Modo equipo cuando sea necesario.
- Al volver a modo individual no se borran usuarios, equipos ni historiales.

INSTALACION
-----------
1. Respaldar MySQL y los archivos del CRM.
2. Subir este ZIP a /home2/manue221/rhinocrm/
3. Ejecutar:

   cd /home2/manue221/rhinocrm

   cp .env ".env.backup-work-mode-$(date +%Y%m%d-%H%M%S)"
   cp public/assets/rhino-crm.js "public/assets/rhino-crm.js.backup-work-mode-$(date +%Y%m%d-%H%M%S)"

   unzip -o Rhino_CRM_Partner_Individual_Mode_Update_2026-07-22.zip

   composer dump-autoload --optimize
   php artisan migrate --force
   php artisan optimize:clear

4. Verificar:

   php artisan migrate:status | grep 2026_07_22_000500
   php artisan route:list --path=api/v1/work-mode

5. Borrar los datos almacenados de ventas.activa7.com o desregistrar el service worker.

MIGRACION ESPERADA
------------------
2026_07_22_000500_add_partner_work_mode ... Ran

COMPORTAMIENTO DE LA MIGRACION
------------------------------
- Socios con un solo usuario activo y sin equipos: INDIVIDUAL.
- Socios con más de un usuario activo o equipos existentes: TEAM.
- No elimina usuarios, equipos, prospectos, eventos ni mediciones.
- Solo asigna al socio registros que estaban sin responsable.

NO EJECUTAR
-----------
php artisan migrate:fresh
php artisan migrate:reset
php artisan migrate:rollback
php artisan db:wipe

PRUEBA RAPIDA
-------------
1. Iniciar sesión como PARTNER_ADMIN.
2. Abrir "Mi cuenta".
3. Confirmar que aparece "Modo individual".
4. Crear un evento: el responsable debe asignarse automáticamente.
5. Crear un prospecto: debe asignarse al mismo socio.
6. Agendar una actividad: debe asignarse al socio.
7. En la APK, sincronizar un pesaje: el prospecto debe quedar bajo el socio.
8. Activar "Modo equipo" desde Mi cuenta y confirmar que aparecen Usuarios y Equipos.

REVERSIBILIDAD
--------------
El modo se cambia desde la interfaz. No requiere rollback de base de datos.
