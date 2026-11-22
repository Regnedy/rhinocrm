# RHINO CRM Android + Eventos V1.2

Fecha de preparación: 2026-08-10

## Objetivo

Convertir la pantalla de evento y la ficha del participante en una herramienta operativa de captación y seguimiento comercial sin duplicar lógica entre Android y RHINO CRM.

## Funciones incluidas

### Eventos Android
- Filtros: Todos, Pesajes sin WhatsApp, Pesajes con WhatsApp, Plan pendiente, Plan enviado.
- Filtros comerciales: Sin explorar, Interesado, Evaluando, No interesado por ahora, Compra realizada.
- Colores/chips por estado del prospecto.
- Pipeline accionable: Pesajes → WhatsApp capturado → Contacto iniciado → Respondió → Plan enviado → Interesado/Evaluando → Compra.
- No interesado por ahora como salida lateral.
- Paginación de participantes con `Cargar más`.
- Refresco de participantes cada 15 s mientras se mantiene la primera página.
- Actividad comercial legible desde `activity_type`.

### Participante Android
- Seguimiento WhatsApp extensible, iniciando con `SALUDO`.
- `SALUDO` registra CONTACTO INICIADO antes de abrir `wa.me`.
- Marcación manual de respuesta.
- AGREGAR A CONTACTOS.
- Estado comercial por evento.
- ASIGNAR RESPONSABLE con colaboradores activos del evento.
- Envío directo de plan por WhatsApp mediante RHINO cuando esté disponible.
- Compartir manualmente como respaldo sin marcar falsamente PLAN ENVIADO.
- PDFs con `planId` en el nombre para evitar colisiones.
- QR sin bucle infinito cuando el backend no devuelve URL.
- Bloqueo temporal de controles durante operaciones para evitar doble toque.

### Backend
- Nuevos estados de seguimiento por `campaign_contacts`.
- Pipeline de evento calculado desde datos reales.
- Filtros server-side y paginación por cursor.
- Eliminación del N+1 de ventas en la lista de participantes.
- Detección de respuesta por webhook Meta cuando `last_inbound_at >= contact_started_at`.
- Registro manual de respuesta idempotente en el timeline.
- Clasificación comercial y compra automática desde venta completada.
- Reasignación por evento con trazabilidad.
- Lista de colaboradores autorizados del evento.
- Filtro `contact_id` en tareas.
- Idempotencia estable al generar dieta cuando Android envía `X-Idempotency-Key`.
- Envío directo de plan reutilizando `NutritionPlanDeliveryService`.

## Estados comerciales

- `UNEXPLORED`: Sin explorar.
- `INTERESTED`: Interesado.
- `EVALUATING`: Evaluando / requiere seguimiento.
- `NOT_INTERESTED_NOW`: No interesado por ahora.
- `PURCHASE_COMPLETED`: Compra realizada. No se selecciona manualmente desde Android; se deriva de una venta completada.

## Estado visual

- `NEUTRAL`: sin WhatsApp aprovechable.
- `WHATSAPP`: WhatsApp confirmado, verde ligero.
- `PLAN_READY`: plan generado, verde intermedio.
- `PLAN_SENT`: plan enviado confirmado por backend, verde oscuro.

## Endpoints agregados/modificados

```text
GET   /api/v1/campaigns/{id}/participants?filter=...&limit=100&cursor=...
GET   /api/v1/campaigns/{id}/pipeline
GET   /api/v1/campaigns/{id}/collaborators
POST  /api/v1/campaigns/{id}/participants/{contactId}/contact-started
POST  /api/v1/campaigns/{id}/participants/{contactId}/responded
PATCH /api/v1/campaigns/{id}/participants/{contactId}/commercial-status
PATCH /api/v1/campaigns/{id}/participants/{contactId}/assignee
POST  /api/v1/campaigns/{id}/participants/{contactId}/plans/{planId}/send-whatsapp
GET   /api/v1/tasks?status=PENDING&contact_id={contactId}&limit=100
```

El endpoint de participantes conserva la respuesta histórica en forma de array cuando se llama sin filtros/paginación, para reducir riesgo de romper clientes existentes.

## Migración de base de datos

Se agrega:

```text
2026_08_10_000600_add_event_followup_state_to_campaign_contacts.php
```

Campos:

```text
contact_started_at
contact_started_by_user_id
manual_responded_at
manual_responded_by_user_id
commercial_status
commercial_status_updated_at
commercial_status_updated_by_user_id
```

El `down()` es intencionalmente no destructivo para no borrar historial comercial si se revierte el código.

## Compatibilidad

- Android package: `com.activa7.rhinocrm.mobile`.
- Android versionCode: `3`.
- Android versionName: `1.2.0-native`.
- Kiosco mantiene su package separado.
- La generación de dieta sigue funcionando sin clave de idempotencia para clientes anteriores; Android V1.2 sí la envía.
