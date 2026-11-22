# Actualización: eventos y minicampañas — 2026-07-21

Esta actualización adapta Rhino CRM al modelo operativo en el que cada socio organiza jornadas de pesaje, Home Parties y otras minicampañas. Las sucursales dejan de ser un paso visible; el sistema conserva una ubicación técnica `Principal` para compatibilidad interna.

## Funciones añadidas

- Eventos con nombre, tipo, lugar, fecha, responsable, equipo, integrantes, básculas, metas, presupuesto y estado.
- Estados `SCHEDULED`, `ACTIVE`, `CLOSED` y `CANCELLED`.
- Registro manual de participantes con reutilización del expediente por WhatsApp.
- Seguimiento programable al registrar un participante.
- Atribución automática de mediciones a un evento cuando una báscula vinculada está activa.
- Un mismo contacto puede participar en varios eventos sin duplicarse.
- Informes por evento: personas, prospectos nuevos, pesajes, seguimientos, conversiones, ventas e ingresos.
- Desglose por asesor.
- Atribución de venta y conversión a una sola campaña de origen para evitar duplicar resultados.
- Filtro de prospectos y clientes por evento.
- Ubicación técnica `Principal` creada automáticamente para cada socio que no tenga una.

## Tablas nuevas

- `campaigns`
- `campaign_members`
- `campaign_devices`
- `campaign_contacts`

## Columnas aditivas

- `measurements.campaign_id`
- `contacts.origin_campaign_id`
- `tasks.campaign_id`
- `sales.origin_campaign_id`

No se elimina ni reinicia ninguna tabla. El método `down()` de la migración es deliberadamente no destructivo.

## Despliegue sobre una instalación existente

Haga primero un respaldo de MySQL y de los archivos del proyecto. Extraiga el ZIP de actualización sobre la raíz del CRM, conservando `.env`, `vendor/` y `storage/`.

```bash
cd /home2/manue221/rhinocrm
composer dump-autoload --optimize
grep -q '^RHINO_VERSION=' .env && sed -i 's/^RHINO_VERSION=.*/RHINO_VERSION=2026.07.21-events.1/' .env || echo 'RHINO_VERSION=2026.07.21-events.1' >> .env
php artisan migrate --force
php artisan optimize:clear
php artisan route:list | grep campaigns
```

No ejecute `migrate:fresh`, `migrate:reset`, `migrate:rollback` ni `db:wipe`.

Después cierre y vuelva a abrir la PWA. Si el menú **Eventos** no aparece, fuerce una recarga o elimine únicamente la caché del sitio; el service worker cambia a `rhino-shell-v4`.

## Operación recomendada

1. El socio crea un evento.
2. Vincula integrantes y básculas.
3. Activa el evento al comenzar.
4. Registra participantes manualmente o recibe mediciones desde las básculas.
5. El CRM crea o reutiliza el expediente por WhatsApp.
6. Programa seguimientos.
7. Cierra el evento al terminar.
8. Consulta su informe y continúa el seguimiento desde Prospectos, Agenda y Pipeline.

Una báscula no puede estar activa simultáneamente en dos eventos.
