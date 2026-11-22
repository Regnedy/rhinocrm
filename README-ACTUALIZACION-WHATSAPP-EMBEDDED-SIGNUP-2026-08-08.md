# RHINO CRM — WhatsApp Embedded Signup por socio

Fecha: 2026-08-08
Versión RHINO: `2026.08.08-whatsapp-embedded-signup.1`

## Objetivo

Sustituir la captura manual de `Phone Number ID`, `WABA ID` y `Access Token` por un flujo de conexión guiado con Meta para cada socio. El socio usa **Organización → WhatsApp del socio → Conectar con Meta**, autoriza su negocio en la ventana oficial de Meta y RHINO completa la configuración del canal sin exponer secretos al navegador.

La configuración manual se conserva únicamente como contingencia para `SUPER_ADMIN`.

## Qué cambia

- Nuevo cliente backend `MetaEmbeddedSignupClient` para intercambiar el código de autorización, inspeccionar el token, consultar los números autorizados y suscribir la WABA al webhook.
- Nuevo servicio `PartnerWhatsappEmbeddedSignupService` con aislamiento estricto por `partner_id`.
- Nuevos endpoints autenticados:
  - `GET /api/v1/organization/whatsapp-channel/embedded-signup/config`
  - `POST /api/v1/organization/whatsapp-channel/embedded-signup/complete`
- Nueva migración aditiva `2026_08_08_000200_add_embedded_signup_to_partner_whatsapp_channels`.
- La credencial obtenida se cifra antes de guardarse; nunca se devuelve al frontend.
- La UI de Organización muestra **Conectar con Meta**, **Reconectar**, **Probar conexión** y **Desactivar**.
- La configuración manual queda visible solo para `SUPER_ADMIN`.
- Si una autorización Embedded Signup tiene fecha de expiración y ya venció, RHINO bloquea el envío y solicita reconexión.
- El contrato del Kiosco no cambia: sigue enviando el `measurement_id`, destinatario y formato; el servidor resuelve el canal del socio.

## Variables de entorno requeridas

Agregar a `.env` de producción, usando los datos de la aplicación central de RHINO en Meta:

```env
META_WHATSAPP_API_URL=https://graph.facebook.com/v25.0
META_APP_ID=
META_APP_SECRET=
META_EMBEDDED_SIGNUP_CONFIG_ID=
META_EMBEDDED_SIGNUP_ENABLED=true
```

Nunca compartir `META_APP_SECRET` por chat, correo o capturas. Debe permanecer únicamente en el servidor.

`META_PHONE_NUMBER_ID` y `META_ACCESS_TOKEN` ya no son la fuente de envío de los canales por socio; cada conexión se almacena cifrada en `partner_whatsapp_channels`.

## Instalación

Antes de instalar: respaldar código, `.env` y MySQL.

```bash
cd /home2/manue221/rhinocrm
php artisan down --retry=60

# Copiar los archivos del parche previamente validado sobre la raíz del proyecto.

php artisan optimize:clear
php artisan migrate --force
php artisan view:clear
php artisan view:cache
```

Validaciones recomendadas:

```bash
php tests/Standalone/PartnerWhatsappEmbeddedSignupContractTest.php
php tests/Standalone/MetaEmbeddedSignupHttpTest.php
php tests/Standalone/PartnerWhatsappMultiTenantContractTest.php
php tests/Standalone/MetaWhatsappDirectMediaHttpTest.php
php tests/Standalone/QuickNutritionDirectMediaWhatsappTest.php
php tests/Standalone/QuickNutritionBothDeliveryTest.php
node --check public/assets/rhino-crm.js
bash scripts/static_audit.sh
```

Luego:

```bash
php artisan up
php artisan about
```

## Configuración única en Meta

Para que el botón **Conectar con Meta** funcione, la aplicación central de RHINO debe tener configurado un flujo de Facebook Login for Business / WhatsApp Embedded Signup. El `Configuration ID` generado en Meta se coloca en `META_EMBEDDED_SIGNUP_CONFIG_ID`.

La app debe usar HTTPS y tener configurados correctamente dominio/URL de RHINO y su webhook. Para incorporar negocios reales fuera de cuentas de desarrollo/prueba, completar los requisitos de Meta para la aplicación y los permisos de negocio/WhatsApp necesarios.

La aprobación de plantillas de WhatsApp sigue siendo independiente. Embedded Signup conecta el canal, pero no convierte automáticamente una plantilla no aprobada en aprobada.

## Seguridad y aislamiento

- El App Secret solo se usa en backend.
- El código de autorización se intercambia en backend.
- El frontend recibe únicamente App ID, Configuration ID y versión pública de Graph API.
- El canal se guarda por `partner_id`.
- Un socio no puede conectar/reconectar el canal de otro socio.
- `phone_number_id` se mantiene único entre canales.
- El webhook entrante continúa resolviendo primero el número receptor para determinar el socio.
- No hay fallback silencioso a credenciales de otro socio.

## Rollback

La migración de Embedded Signup es aditiva y su `down()` es intencionalmente no destructivo. Si se necesita rollback de aplicación, restaurar el backup de código y `.env`. Si la base debe volver exactamente al snapshot anterior, restaurar el dump MySQL tomado antes del despliegue.
