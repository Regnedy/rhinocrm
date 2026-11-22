# RHINO CRM — Quick Nutrition WhatsApp Direct Media + BOTH

Versión CRM: `2026.08.08-quick-plan-direct-media-both.1`

## Cambio

La entrega directa del Plan Alimenticio Rápido admite tres formatos:

- `pdf`: envía el PDF real por WhatsApp Cloud API.
- `images`: envía las páginas JPG por WhatsApp Cloud API.
- `both`: envía primero el PDF y después todas las imágenes al mismo número.

La opción `both` usa las mismas validaciones de WhatsApp, consentimiento, tenant y ventana de conversación que las opciones existentes. Fuera de la ventana de conversación requiere las dos plantillas aprobadas: DOCUMENT para PDF e IMAGE para imágenes.

## Endpoints

`POST /api/v1/mobile/nutrition-plans/{id}/send-whatsapp`

```json
{"format":"both","whatsapp":"526623669415","consent":true}
```

`POST /api/v1/mobile/whatsapp/test-delivery`

```json
{"format":"both","whatsapp":"526623669415"}
```

El endpoint TEST no crea prospecto, medición, plan ni actividad comercial.

## Configuración requerida

```env
RHINO_VERSION=2026.08.08-quick-plan-direct-media-both.1
META_WHATSAPP_API_URL=https://graph.facebook.com/v25.0
META_PHONE_NUMBER_ID=
META_ACCESS_TOKEN=
RHINO_WHATSAPP_NUTRITION_PLAN_TEMPLATE=rhino_plan_rapido_pdf
RHINO_WHATSAPP_NUTRITION_PLAN_LANGUAGE=es_MX
RHINO_WHATSAPP_NUTRITION_IMAGES_TEMPLATE=rhino_plan_rapido_imagen
RHINO_WHATSAPP_NUTRITION_IMAGES_LANGUAGE=es_MX
```

No contiene credenciales ni requiere una migración nueva.
