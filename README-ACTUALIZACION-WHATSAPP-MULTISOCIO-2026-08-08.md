# RHINO CRM — WhatsApp Business multi-socio

Versión: `2026.08.08-partner-whatsapp.1`

## Objetivo

Cada socio administra su propio canal de WhatsApp Business/Cloud API. El Kiosco no conoce ni almacena tokens: RHINO toma el `partner_id` propietario del pesaje/plan y usa exclusivamente las credenciales de ese socio.

## Cambios

- Tabla `partner_whatsapp_channels`, una configuración principal por socio.
- `phone_number_id` único globalmente para impedir que un mismo emisor quede asignado a dos socios.
- Token cifrado mediante Laravel `Crypt`; nunca se devuelve por API ni se renderiza en la UI.
- Configuración desde `Organización > WhatsApp del socio`.
- `PARTNER_ADMIN` puede administrar su canal con `organization.whatsapp.manage`.
- `SUPER_ADMIN` puede administrar el canal del socio seleccionado.
- Prueba de conexión a Meta sin enviar mensajes.
- Quick Nutrition PDF / Imágenes / Ambas resuelve el canal por el `partner_id` del plan.
- Modo Test usa el canal del socio autenticado sin crear prospectos o mediciones.
- Workflows, informes personales y campañas pasan el `partner_id` al proveedor WhatsApp.
- Webhook entrante resuelve primero el socio por `metadata.phone_number_id`, evitando cruces entre tenants.
- No existe fallback silencioso al `META_PHONE_NUMBER_ID` o `META_ACCESS_TOKEN` global para envíos de socios.

## Instalación

1. Respaldar código, `.env` y MySQL.
2. Descomprimir el parche en staging y validar `MANIFEST_SHA256.txt`.
3. Poner RHINO en mantenimiento.
4. Copiar archivos del parche sobre la raíz del CRM.
5. Actualizar `RHINO_VERSION=2026.08.08-partner-whatsapp.1` en `.env`.
6. Ejecutar:

```bash
php artisan optimize:clear
php artisan migrate --force
php tests/Standalone/PartnerWhatsappMultiTenantContractTest.php
php tests/Standalone/MetaWhatsappDirectMediaHttpTest.php
php tests/Standalone/QuickNutritionDirectMediaWhatsappTest.php
php tests/Standalone/QuickNutritionBothDeliveryTest.php
php artisan route:list | grep -E 'organization/whatsapp-channel|send-whatsapp|test-delivery'
php artisan up
```

## Configuración por socio

Entrar a `Organización` y usar la tarjeta **WhatsApp del socio**. Registrar:

- Número emisor (informativo; Meta puede actualizarlo al probar conexión).
- Phone Number ID.
- WABA ID.
- Token de acceso.
- Plantilla PDF con encabezado DOCUMENT.
- Plantilla de imágenes con encabezado IMAGE.
- Idioma de plantillas, por defecto `es_MX`.

El token se solicita solo al conectar o rotar credenciales. Cuando se edita una configuración existente, dejar el campo token vacío conserva el token cifrado actual.

## Seguridad

- El token no se guarda en texto plano.
- El token no se devuelve al navegador.
- Los logs de auditoría nunca incluyen el token.
- Un socio no puede consultar ni modificar el canal de otro socio.
- Si el canal está desactivado o en error, RHINO no usa el canal de otro socio ni credenciales globales como fallback.
- El webhook entrante utiliza el Phone Number ID receptor para resolver el tenant antes de buscar contactos por teléfono.

## Nota sobre permisos personalizados

La migración agrega `organization.whatsapp.manage` a PARTNER_ADMIN cuando el usuario ya tiene permisos explícitos. Si el socio tiene una política personalizada restrictiva, SUPER_ADMIN debe habilitar también este permiso en la política del socio.

## Compatibilidad

Compatible con el contrato móvil de Kiosco 2.7.4 Direct Media + BOTH. No requiere cambios nuevos en Android para esta actualización multi-socio.

Las variables globales `META_WHATSAPP_API_URL`, `META_WEBHOOK_VERIFY_TOKEN` y `META_APP_SECRET` siguen siendo configuración de plataforma. `META_PHONE_NUMBER_ID` y `META_ACCESS_TOKEN` quedan como legado/transición y no se utilizan como emisor de respaldo para socios.
