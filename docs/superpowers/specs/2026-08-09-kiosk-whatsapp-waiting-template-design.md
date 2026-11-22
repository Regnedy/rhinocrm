# RHINO Kiosco — Plantilla WhatsApp de espera por socio

## Objetivo
Permitir que cada socio edite una única plantilla de WhatsApp de espera desde la Configuración del Kiosco y que la misma plantilla se aplique automáticamente a todos los Kioscos donde exista una sesión activa de ese socio.

## Arquitectura
RHINO CRM es la fuente de verdad por `partner_id`. La configuración se almacena en `partner_kiosk_settings`; el Kiosco descarga y cachea localmente la plantilla para poder trabajar sin conexión. Los cambios hechos desde cualquier Kiosco autorizado se guardan en CRM y se propagan a los demás Kioscos en su siguiente sincronización/context refresh.

## Plantilla predeterminada
```
Hola {nombre} 👋

Tu evaluación RHINO quedó registrada correctamente.

🥗 Estamos preparando tu Plan Alimenticio Personalizado utilizando los datos obtenidos durante tu pesaje.

Estará listo en un plazo máximo de {plazo} y lo recibirás por este WhatsApp en formato PDF e imágenes.

📲 Guarda este número para identificar la entrega de tu plan y recibir tu seguimiento.

Gracias por realizar tu evaluación con {socio}.
```

## Variables permitidas
- `{nombre}`: nombre del prospecto; si falta, se reemplaza por `Hola` sin dejar marcador.
- `{socio}`: nombre comercial del socio de la sesión.
- `{plazo}`: texto configurable, predeterminado `24 horas`.

Los marcadores desconocidos se rechazan al guardar.

## Seguridad y tenancy
- GET disponible a sesiones móviles del propio socio.
- PUT/reset requiere `organization.kiosk.manage` o `SUPER_ADMIN`.
- Un socio no puede leer ni editar la plantilla de otro socio.
- El Kiosco nunca envía `partner_id` arbitrario; el backend deriva el socio de la sesión.

## Modo offline
El Kiosco conserva `template`, `plazo` y `version` en SharedPreferences, scoped por partner. Si CRM no responde, usa la última copia válida o el mensaje predeterminado.

## UX
En Configuración se añade `Mensaje WhatsApp de espera` con:
- editor multilínea,
- plazo,
- ayuda de variables,
- vista previa,
- Guardar para todos los Kioscos del socio,
- Restaurar predeterminado,
- indicador de última sincronización.

## Flujo de uso
Al finalizar un pesaje, el botón de seguimiento toma el WhatsApp capturado, renderiza la plantilla cacheada con datos reales y abre `wa.me/<numero>?text=<mensaje>` directamente. El mensaje no requiere Embedded Signup ni Cloud API.

## Pruebas
- aislamiento multi-socio del CRM,
- validación de marcadores,
- sincronización/caché Kiosco,
- render de variables,
- fallback offline,
- regresión de ticket, Quick Nutrition, WhatsApp país, multi-báscula y SQLite.
