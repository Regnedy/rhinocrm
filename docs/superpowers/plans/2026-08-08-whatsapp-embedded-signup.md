# WhatsApp Embedded Signup por socio Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que un PARTNER_ADMIN conecte su WhatsApp Business desde Meta sin capturar Phone Number ID, WABA ID ni Access Token manualmente.

**Architecture:** El navegador usa Facebook Login for Business/Embedded Signup y entrega un código de autorización al backend. El backend intercambia y valida ese código contra Meta, verifica WABA y número, suscribe el webhook y persiste la credencial cifrada en el canal del socio. La configuración manual permanece solo para SUPER_ADMIN.

**Tech Stack:** Laravel 13, PHP 8.3, MySQL/MariaDB, Laravel HTTP Client, Laravel Crypt, JavaScript vanilla, Meta Graph API/Facebook JavaScript SDK.

## Global Constraints

- No exponer `META_APP_SECRET` ni access tokens en cliente o respuestas API.
- Un PARTNER_ADMIN solo puede conectar el `partner_id` de su contexto.
- Mantener compatibilidad con Kiosco y Direct Media PDF/imágenes/ambas.
- Migraciones aditivas; `down()` no destruye credenciales.
- Configuración manual solo para SUPER_ADMIN.

---

### Task 1: Contrato y configuración Embedded Signup

**Files:**
- Create: `tests/Standalone/PartnerWhatsappEmbeddedSignupContractTest.php`
- Modify: `config/services.php`
- Modify: `.env.example`
- Modify: `routes/api_v1.php`

**Interfaces:**
- Produces: GET `embedded-signup/config`; POST `embedded-signup/complete`.

- [ ] Escribir prueba que falle por ausencia de configuración/rutas/UI.
- [ ] Ejecutar `php tests/Standalone/PartnerWhatsappEmbeddedSignupContractTest.php` y confirmar FAIL.
- [ ] Agregar configuración Meta pública/privada y rutas.
- [ ] Reejecutar hasta PASS.

### Task 2: Cliente Graph de Embedded Signup

**Files:**
- Create: `app/Modules/Integrations/Whatsapp/Services/MetaEmbeddedSignupClient.php`
- Create: `tests/Standalone/MetaEmbeddedSignupHttpTest.php`

**Interfaces:**
- Produces: `exchangeCode()`, `inspectToken()`, `phoneNumbers()`, `subscribeWaba()`.

- [ ] Escribir HTTP fake para intercambio, debug token, teléfonos y suscripción.
- [ ] Confirmar FAIL porque el cliente no existe.
- [ ] Implementar llamadas Meta con timeouts, validaciones y mensajes sanitizados.
- [ ] Confirmar PASS.

### Task 3: Persistencia y finalización multi-socio

**Files:**
- Create: `database/migrations/2026_08_08_000200_add_embedded_signup_to_partner_whatsapp_channels.php`
- Create: `app/Modules/Integrations/Whatsapp/Services/PartnerWhatsappEmbeddedSignupService.php`
- Modify: `app/Modules/Integrations/Whatsapp/Services/PartnerWhatsappChannelService.php`
- Modify: `app/Modules/Integrations/Whatsapp/Controllers/PartnerWhatsappChannelController.php`
- Modify: `app/Modules/Integrations/Whatsapp/Services/PartnerWhatsappChannelResolver.php`

**Interfaces:**
- Consumes: Meta client.
- Produces: conexión cifrada `connection_method=EMBEDDED` y metadata de expiración/suscripción.

- [ ] Añadir al contrato expectativas de aislamiento, cifrado y manual-only-superadmin.
- [ ] Confirmar FAIL.
- [ ] Implementar migración aditiva y servicio de finalización.
- [ ] Confirmar PASS y sintaxis PHP.

### Task 4: UI autoservicio

**Files:**
- Modify: `public/assets/rhino-crm.js`

**Interfaces:**
- Consumes: configuración pública Embedded Signup y endpoint complete.
- Produces: botón `Conectar con Meta`, reconexión y configuración avanzada solo SUPER_ADMIN.

- [ ] Añadir prueba contractual para `FB.login`, `config_id`, respuesta `code`, evento `WA_EMBEDDED_SIGNUP` y ausencia de App Secret.
- [ ] Confirmar FAIL.
- [ ] Implementar carga segura del SDK, listener de sesión y finalización.
- [ ] Confirmar PASS y `node --check public/assets/rhino-crm.js`.

### Task 5: Regresión, release y parche

**Files:**
- Modify: `config/rhino.php`
- Create: `README-ACTUALIZACION-WHATSAPP-EMBEDDED-SIGNUP-2026-08-08.md`

**Interfaces:**
- Produces: release `2026.08.08-whatsapp-embedded-signup.1`.

- [ ] Ejecutar contrato Embedded Signup.
- [ ] Ejecutar HTTP fake Embedded Signup.
- [ ] Ejecutar multi-tenant WhatsApp, Direct Media y BOTH.
- [ ] Ejecutar `scripts/static_audit.sh`.
- [ ] Empaquetar solo archivos modificados, sin `.env` ni credenciales.
- [ ] Generar `MANIFEST_SHA256.txt` y validar el ZIP sobre copia limpia.
