# RHINO Quick Nutrition — WhatsApp directo con archivos

Versión CRM: `2026.08.08-quick-plan-direct-media.1`
Kiosco compatible: `2.7.3-direct-media-whatsapp`

## Objetivo

Enviar el PDF o las imágenes del Plan Alimenticio Rápido directamente al número capturado en Kiosco mediante WhatsApp Cloud API. El operador no abre WhatsApp, no guarda el contacto y no selecciona una conversación.

## Flujo productivo

1. El Kiosco sincroniza el pesaje y genera el Plan Rápido.
2. El operador elige **ENVIAR PDF** o **ENVIAR IMÁGENES**.
3. Esa acción registra consentimiento `REPORT_DELIVERY` para el número del prospecto.
4. RHINO genera el archivo, lo carga a Meta y lo envía al número exacto.
5. El Kiosco recibe `SENT` y muestra la cantidad de archivos enviados.

No se agrega el número a Contactos del dispositivo Android.

## Configuración Meta obligatoria

```env
META_WHATSAPP_API_URL=https://graph.facebook.com/v25.0
META_PHONE_NUMBER_ID=
META_ACCESS_TOKEN=

RHINO_WHATSAPP_NUTRITION_PLAN_TEMPLATE=rhino_plan_rapido_pdf
RHINO_WHATSAPP_NUTRITION_PLAN_LANGUAGE=es_MX
RHINO_WHATSAPP_NUTRITION_IMAGES_TEMPLATE=rhino_plan_rapido_imagen
RHINO_WHATSAPP_NUTRITION_IMAGES_LANGUAGE=es_MX
```

No incluyas credenciales dentro de un ZIP o repositorio. Configúralas solamente en `.env` del servidor.

## Plantillas que debes crear y aprobar en WhatsApp Manager

### PDF — `rhino_plan_rapido_pdf`

- Categoría recomendada: Utility.
- Encabezado: **DOCUMENT**.
- Cuerpo sugerido: `Hola {{1}}, aquí tienes tu Plan Alimenticio Rápido RHINO. Código RHINO: {{2}}.`

### Imágenes — `rhino_plan_rapido_imagen`

- Categoría recomendada: Utility.
- Encabezado: **IMAGE**.
- Cuerpo sugerido: `Hola {{1}}, página {{2}} de {{3}} de tu Plan Alimenticio Rápido RHINO.`

Cuando no existe una ventana de atención abierta, RHINO usa la plantilla correspondiente. Para imágenes, cada página se entrega como un mensaje de plantilla independiente. Si existe una ventana de atención abierta por un mensaje reciente del prospecto, RHINO envía las imágenes como mensajes media normales.

## Modo Test

`POST /api/v1/mobile/whatsapp/test-delivery` usa el número escrito en Modo Test únicamente durante la petición. Genera archivos ficticios y los envía por Cloud API. No crea prospectos, pesajes, planes ni consentimientos en la base de datos.

## Endpoints nuevos

```text
POST /api/v1/mobile/nutrition-plans/{id}/send-whatsapp
POST /api/v1/mobile/whatsapp/test-delivery
```

## No requiere migración

La actualización reutiliza `whatsapp_contact_links`, `health_data_consents`, `whatsapp_messages`, `nutrition_plan_versions` y `timeline_activities` existentes.

## Verificación después de instalar

```bash
php artisan optimize:clear
php artisan route:list | grep -E 'mobile/nutrition-plans/.*/send-whatsapp|mobile/whatsapp/test-delivery'
php tests/Standalone/QuickNutritionDirectMediaWhatsappTest.php
php tests/Standalone/MetaWhatsappDirectMediaHttpTest.php
```

Comprobar configuración sin imprimir secretos:

```bash
php artisan tinker --execute="echo config('services.meta.phone_number_id') ? 'META_PHONE_NUMBER_ID=SET'.PHP_EOL : 'META_PHONE_NUMBER_ID=UNSET'.PHP_EOL; echo config('services.meta.access_token') ? 'META_ACCESS_TOKEN=SET'.PHP_EOL : 'META_ACCESS_TOKEN=UNSET'.PHP_EOL; echo config('rhino.nutrition_engine.whatsapp_pdf_template') ? 'PDF_TEMPLATE=SET'.PHP_EOL : 'PDF_TEMPLATE=UNSET'.PHP_EOL; echo config('rhino.nutrition_engine.whatsapp_images_template') ? 'IMAGE_TEMPLATE=SET'.PHP_EOL : 'IMAGE_TEMPLATE=UNSET'.PHP_EOL;"
```
