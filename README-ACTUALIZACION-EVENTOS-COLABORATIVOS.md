# RHINO CRM — Eventos colaborativos (Party)

Fecha del paquete: 24 de julio de 2026

## Objetivo

Esta actualización convierte el módulo Eventos en una sala de trabajo temporal para el organizador y sus colaboradores.

La regla funcional principal es:

- El usuario que opera Kiosko queda registrado como **Registrado por**.
- El primer usuario que genera la dieta queda automáticamente como **propietario del contacto** y **responsable del seguimiento**.
- El usuario que registra una venta conserva la atribución de la venta, pero la venta no cambia al propietario del contacto.
- Al cerrar el evento, cada contacto con dieta se conserva o transfiere a la organización del autor de la dieta.

## Incluye

- Invitaciones por correo a usuarios activos de RHINO CRM.
- Aceptar, rechazar o revocar invitaciones dentro del CRM.
- Aviso al iniciar sesión y contador de invitaciones pendientes en el menú Eventos.
- Acceso temporal a los prospectos, pesajes, dietas y ventas del evento aceptado.
- Bitácora de actividad con autor y fecha.
- Perfil alimentario y generación de dieta dentro del evento.
- Bloqueo de concurrencia para que dos colaboradores no puedan tomar el mismo contacto al mismo tiempo.
- Registro de productos y ventas del evento.
- Asistente de cierre con totales, productos vendidos, contactos con o sin dieta y un solo campo **Notas del evento**.
- Historial congelado del evento con estadísticas por participante.
- Nuevos permisos administrativos:
  - `campaigns.invite`
  - `campaigns.collaborate`
  - `campaigns.sales`
  - `campaigns.close`

## Reglas de acceso

- Antes de aceptar una invitación, el usuario no puede abrir el evento.
- Mientras el evento está activo, el colaborador aceptado puede ver únicamente las personas asociadas a ese evento.
- Después del cierre, un colaborador puede abrir el expediente completo únicamente de los contactos que obtuvo al generar la dieta.
- El organizador conserva las estadísticas y la auditoría del evento.
- Los permisos de la organización configurados por `SUPER_ADMIN` siguen siendo el límite superior.

## Requisitos

Debe estar instalada la versión actual de RHINO CRM con estas migraciones previas:

```text
2026_07_23_000100_add_nutrition_library_management
2026_07_23_000200_create_partner_permission_policies
```

El paquete no modifica `composer.json` y no requiere `composer update`.

## Instalación

Coloque este ZIP en la raíz del CRM:

```text
/home2/manue221/rhinocrm/
```

Valide el paquete:

```bash
cd /home2/manue221/rhinocrm
UPDATE_ZIP="Rhino_CRM_Collaborative_Events_Party_Update_2026-07-24.zip"
unzip -t "$UPDATE_ZIP"
```

Cree un respaldo de código y base de datos antes de continuar.

Instale:

```bash
cd /home2/manue221/rhinocrm
UPDATE_ZIP="Rhino_CRM_Collaborative_Events_Party_Update_2026-07-24.zip"

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
    echo "INSTALACIÓN COMPLETADA"
else
    echo "ERROR: el CRM permanece en modo mantenimiento"
    exit 1
fi
```

## Verificación

```bash
cd /home2/manue221/rhinocrm

php artisan migrate:status | grep '2026_07_24_000300'
php artisan route:list | grep -E 'campaign-invitations|close-preview|participants/.+/diet|participants/.+/sales'
grep -n "rhino-shell-v16-collaborative-events" public/sw.js
php -l app/Modules/Campaigns/Services/CampaignCollaborationService.php
php -l app/Modules/Campaigns/Services/CampaignDietOwnershipService.php
```

La migración debe aparecer como ejecutada:

```text
2026_07_24_000300_add_collaborative_event_party
```

## Prueba funcional recomendada

1. Ingrese como socio organizador y cree o abra un evento.
2. Invite al segundo usuario mediante su correo exacto de RHINO CRM.
3. Ingrese con el usuario invitado y acepte desde Eventos.
4. Registre una persona desde Kiosko o desde el evento.
5. Confirme que se muestra `Registrado por` y que el responsable continúa sin asignar.
6. Genere la dieta con uno de los dos usuarios.
7. Confirme que ese usuario aparece como responsable y propietario.
8. Registre una venta con cualquiera de los dos usuarios.
9. Abra el cierre, confirme las cantidades de productos y capture una nota opcional.
10. Cierre el evento y revise el historial de ambos participantes.

## Consideraciones de la primera versión

- La notificación es interna en RHINO CRM; todavía no es una notificación push del sistema operativo.
- Kiosko debe capturar dentro del evento activo y con una báscula vinculada al evento del organizador.
- Si en la organización que recibirá el contacto ya existe otro registro con el mismo WhatsApp, el cierre se bloquea para evitar una transferencia ambigua. El CRM mostrará los nombres que deben revisarse antes de cerrar.
- La migración tiene reversión no destructiva: un `rollback` no elimina invitaciones, actividad ni propiedad histórica de contactos.
