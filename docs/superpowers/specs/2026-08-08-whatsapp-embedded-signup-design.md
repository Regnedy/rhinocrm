# WhatsApp Embedded Signup por socio — Diseño

## Objetivo

Reemplazar el ingreso manual de Phone Number ID, WABA ID y Access Token por un onboarding autoservicio desde RHINO: el administrador del socio pulsa **Conectar con Meta**, completa el flujo oficial de Embedded Signup y vuelve a RHINO con su canal de WhatsApp configurado.

## Alcance

- Un canal WhatsApp independiente por `partner_id`.
- El socio nunca ve ni manipula tokens técnicos.
- `SUPER_ADMIN` conserva configuración manual avanzada como respaldo.
- El Kiosco no cambia su contrato ni recibe credenciales.
- RHINO conserva el envío existente PDF / imágenes / ambas.
- El webhook entrante continúa resolviendo el socio por `phone_number_id`.

## Flujo

1. Organización carga `GET /organization/whatsapp-channel/embedded-signup/config`.
2. Si Meta está configurado, RHINO carga el Facebook JavaScript SDK con el App ID público.
3. El socio pulsa **Conectar con Meta**.
4. `FB.login` abre la configuración de Facebook Login for Business indicada por `META_EMBEDDED_SIGNUP_CONFIG_ID` con respuesta de tipo `code`.
5. RHINO captura el evento `WA_EMBEDDED_SIGNUP` para obtener, cuando Meta lo entrega, `waba_id` y `phone_number_id`.
6. El navegador envía exclusivamente `code`, `waba_id` y `phone_number_id` al backend autenticado.
7. El backend intercambia el código usando App ID + App Secret; el App Secret nunca sale del servidor.
8. RHINO inspecciona el token, valida que la WABA esté autorizada, consulta los números de esa WABA y verifica que el Phone Number ID pertenezca a ella.
9. RHINO suscribe la app a `/{WABA-ID}/subscribed_apps`.
10. RHINO cifra el token y persiste el canal con `connection_method=EMBEDDED`.
11. La tarjeta muestra número conectado, estado y vencimiento de credencial, sin mostrar secretos.

## Seguridad

- `META_APP_SECRET` jamás se entrega al navegador.
- El endpoint de finalización usa el `partner_id` del contexto; un PARTNER_ADMIN no puede indicar otro socio.
- Se valida que el token pertenezca a la App ID configurada y tenga `whatsapp_business_management`.
- Se valida que la WABA solicitada aparezca en los `granular_scopes`/targets autorizados cuando Meta los devuelve.
- Se valida que el Phone Number ID provenga de `/{waba_id}/phone_numbers`.
- El token se cifra con Laravel `Crypt` antes de persistirlo.
- La configuración manual queda disponible únicamente para `SUPER_ADMIN`.
- Las migraciones son aditivas y `down()` no destruye credenciales.

## Configuración de plataforma

Variables nuevas:

- `META_APP_ID`
- `META_APP_SECRET`
- `META_EMBEDDED_SIGNUP_CONFIG_ID`
- `META_EMBEDDED_SIGNUP_ENABLED=true`

Se conserva `META_WHATSAPP_API_URL` y la configuración global del webhook. `META_SYSTEM_USER_ACCESS_TOKEN` queda opcional para una fase posterior de Tech Provider/System User; la primera versión completa Embedded Signup con la credencial autorizada por el negocio y deja visible su expiración.

## Estado y degradación

- Si faltan App ID/Secret/Config ID, la UI muestra **Onboarding automático pendiente de configurar** y no abre Meta.
- Si Meta cancela el flujo, no se modifica el canal existente.
- Si falla validación de WABA/número o la suscripción, no se reemplaza la conexión existente.
- Un canal manual existente continúa funcionando hasta que Embedded Signup finaliza correctamente.
- Si el token tiene expiración, RHINO la muestra para permitir reconexión antes del vencimiento.

## Pruebas

- Contrato estático de rutas, seguridad y UI.
- Cliente Meta con `Http::fake`: intercambio de código, debug token, consulta de teléfonos y suscripción WABA.
- Verificación de que el App Secret no aparece en JS ni respuestas públicas.
- Regresión de WhatsApp multi-socio, Direct Media y entrega BOTH.
