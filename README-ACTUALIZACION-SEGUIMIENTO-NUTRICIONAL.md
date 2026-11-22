# Rhino CRM — Campañas de seguimiento nutricional

**Versión:** `2026.07.22-nutrition-followup.1`

Esta actualización añade campañas para personas que ya recibieron una alimentación
recomendada RHINO. El socio puede filtrar candidatos, seleccionarlos con un checklist,
enviar invitaciones individuales por WhatsApp y seguir a cada persona en un pipeline.

## Funciones

- Filtrar por fecha del último plan alimentario.
- Exigir que el plan haya sido enviado o entregado.
- Excluir a quien ya tenga un pesaje posterior al plan.
- Exigir WhatsApp verificado y autorización para invitaciones.
- Vincular la campaña a un evento de degustación o nuevo pesaje.
- Seleccionar personas mediante checklist.
- Enviar una invitación por trabajo de cola, una persona a la vez.
- Registrar estados enviado, entregado y leído mediante webhook.
- Interpretar respuestas de WhatsApp: confirmó, no confirmó o respondió.
- Marcar automáticamente `NUEVO PESAJE` al sincronizar una medición posterior.
- Mantener historial de etapas y auditoría por prospecto.
- Informes de confirmación, asistencia, nuevos pesajes, interés y ventas.

## Pipeline

```text
Seleccionado
→ Invitación pendiente
→ Invitación enviada
→ Entregada
→ Leída
→ Respondió
→ Confirmó
→ Asistió
→ Nuevo pesaje
→ Degustación realizada
→ Interesado
→ Venta realizada
```

También contempla: no confirmó, no asistió, sin respuesta y no desea mensajes.

## Instalación

Este paquete acumulativo incluye las migraciones de informes personales,
alimentación recomendada y seguimiento nutricional.

1. Respaldar MySQL y el proyecto.
2. Subir el ZIP a `/home2/manue221/rhinocrm/`.
3. Ejecutar:

```bash
cd /home2/manue221/rhinocrm

cp .env ".env.backup-nutrition-followup-$(date +%Y%m%d-%H%M%S)"

unzip -o Rhino_CRM_Nutrition_FollowUp_Campaigns_Update_2026-07-22.zip

composer update dompdf/dompdf \
  --with-all-dependencies \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader

php artisan migrate --force
php artisan optimize:clear
php artisan rhino:contacts-backfill-codes
```

No ejecutar:

```text
php artisan migrate:fresh
php artisan migrate:reset
php artisan migrate:rollback
php artisan db:wipe
```

## Migraciones incluidas

```text
2026_07_22_000600_create_event_personal_reports
2026_07_22_000700_add_nutrition_recommendations_to_reports
2026_07_22_000800_create_nutrition_followup_campaigns
```

Todas son incrementales y sus métodos `down()` no eliminan datos.

## Configuración

Agregar o revisar en `.env`:

```env
RHINO_VERSION=2026.07.22-nutrition-followup.1

RHINO_NUTRITION_CAMPAIGN_DAYS_SINCE_PLAN=30
RHINO_WHATSAPP_NUTRITION_INVITATION_TEMPLATE=
RHINO_WHATSAPP_NUTRITION_INVITATION_LANGUAGE=es_MX
```

La integración Meta ya debe contener:

```env
META_WHATSAPP_API_URL=https://graph.facebook.com/v25.0
META_PHONE_NUMBER_ID=
META_ACCESS_TOKEN=
META_WEBHOOK_VERIFY_TOKEN=
META_APP_SECRET=
```

Fuera de la ventana activa de WhatsApp se requiere una plantilla aprobada.
Variables de la plantilla, en orden:

```text
1. Nombre
2. Fecha de la alimentación recomendada
3. Nombre del evento
4. Fecha y hora del evento
5. Código RHINO
```

## Permisos

- Socio: crea, selecciona, invita, administra etapas e informes.
- Coordinador: administra campañas dentro de sus equipos.
- Responsable: consulta y actualiza la etapa de las personas asignadas.
- Operador: no administra campañas nutricionales.

## Rutas

```text
GET    /api/v1/nutrition-campaigns
POST   /api/v1/nutrition-campaigns
POST   /api/v1/nutrition-campaigns/candidates/search
GET    /api/v1/nutrition-campaigns/{id}
PATCH  /api/v1/nutrition-campaigns/{id}
POST   /api/v1/nutrition-campaigns/{id}/members
DELETE /api/v1/nutrition-campaigns/{id}/members/{memberId}
POST   /api/v1/nutrition-campaigns/{id}/activate
POST   /api/v1/nutrition-campaigns/{id}/close
POST   /api/v1/nutrition-campaigns/{id}/dispatch
PATCH  /api/v1/nutrition-campaigns/{id}/members/{memberId}/stage
GET    /api/v1/nutrition-campaigns/{id}/report
```

## Verificación

```bash
php artisan migrate:status --no-ansi | grep 2026_07_22_000800
php artisan route:list --path=api/v1/nutrition-campaigns
php artisan optimize:clear
```

Después borra los datos almacenados de la PWA o desregistra su service worker.

## Prueba recomendada

1. Usa prospectos ficticios que ya tengan plan alimentario.
2. Confirma que al menos uno tenga autorización de invitaciones por WhatsApp.
3. Crea un evento futuro de degustación.
4. Abre `Seguimiento` y crea una campaña.
5. Pulsa `Buscar candidatos`.
6. Selecciona mediante checklist.
7. Agrega a la campaña.
8. Marca uno o más integrantes y pulsa `Enviar seleccionados`.
9. Confirma en el pipeline los estados de WhatsApp.
10. Responde `SÍ` desde el teléfono de prueba.
11. Registra un nuevo pesaje y verifica que avance a `Nuevo pesaje`.

## Seguridad

- No se envía a personas sin autorización para invitaciones.
- No se crea un envío masivo único: cada persona se procesa por separado.
- Las bajas de WhatsApp mueven al prospecto a `No desea mensajes`.
- El historial de etapas nunca se sobrescribe.
- Las campañas guardan la fecha y versión del plan usado para la selección.
