# RHINO WhatsApp multi-socio — Diseño

## Objetivo
Cada socio conecta su propio número de WhatsApp Business/Cloud API. El Kiosco nunca recibe credenciales: envía el `measurement_id`, el número del prospecto y el formato; RHINO resuelve el `partner_id` propietario y usa exclusivamente el canal WhatsApp de ese socio.

## Alcance inicial
- Configuración manual por socio (fase A).
- Un canal WhatsApp principal por socio.
- `PARTNER_ADMIN` administra su canal si el permiso del socio lo permite.
- `SUPER_ADMIN` puede consultar/configurar el canal del socio seleccionado.
- Token cifrado en base de datos y nunca devuelto por API/UI.
- Phone Number ID, WABA ID, número emisor, plantillas PDF/imagen, idioma y estado por socio.
- Prueba de conexión desde RHINO sin enviar un mensaje.
- Quick Nutrition PDF/Images/Both y Modo Test usan el canal del socio actual/propietario.
- Si el socio no tiene canal activo, el envío directo falla de forma explícita; nunca usa credenciales de otro socio ni un fallback global silencioso.

## Persistencia
Tabla `partner_whatsapp_channels`, única por `partner_id`. `access_token_ciphertext` usa `Crypt::encryptString`. El `down()` de la migración es deliberadamente no destructivo.

## Resolución
`PartnerWhatsappChannelResolver` devuelve un DTO de credenciales por `partner_id`. `MetaWhatsappProvider` acepta un `partnerId` opcional en todas las operaciones; si no se pasa usa el `UserContextDTO`. Los servicios de nutrición pasan explícitamente el `partner_id` del plan para soportar eventos colaborativos sin mezclar emisores.

## UI
Tarjeta “WhatsApp del socio” dentro de Organización. Muestra estado, número, Phone Number ID, WABA ID, plantillas, último test y error; nunca el token. Formulario de configuración con token tipo password y texto “dejar vacío para conservar”.

## Seguridad
- Permiso nuevo `organization.whatsapp.manage`, por defecto solo `PARTNER_ADMIN` y `SUPER_ADMIN`.
- Auditoría en `security_audit_logs` sin secretos.
- Token nunca se registra, serializa ni retorna.
- Un usuario no SUPER_ADMIN no puede seleccionar otro `partner_id`.
- Quick Nutrition usa el `partner_id` del plan, no el del usuario colaborador.

## Compatibilidad
Las variables globales `META_PHONE_NUMBER_ID` y `META_ACCESS_TOKEN` pueden permanecer en `.env` durante transición, pero el flujo multi-socio no las usa para envíos de socios. `META_WHATSAPP_API_URL`, webhook verify token y app secret continúan como configuración de plataforma.
