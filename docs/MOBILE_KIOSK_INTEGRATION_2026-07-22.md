# Integración RHINO Kiosco Android ↔ Rhino CRM

## Alcance de esta fase

Esta actualización habilita el primer contrato estable entre la APK y el CRM:

- El socio inicia sesión en Android con Firebase Email/Password.
- Android intercambia el ID token por una sesión Rhino revocable.
- La app consulta contexto, eventos y básculas autorizadas.
- El socio crea y activa eventos mediante las rutas existentes de campañas.
- La APK envía una medición con consentimiento al endpoint móvil.
- Laravel crea o reutiliza el prospecto por WhatsApp, vincula la medición y la atribuye al evento activo.
- Inventario y finanzas quedan preparados en base de datos, pero desactivados por feature flags.

## Endpoints

### Login Firebase

`POST /api/v1/auth/firebase/exchange`

Encabezado:

`Authorization: Bearer <FIREBASE_ID_TOKEN>`

### Contexto móvil

`GET /api/v1/mobile/context`

Devuelve socio, usuario, dispositivos, eventos y funciones habilitadas.

### Eventos

Se reutiliza la API del CRM:

- `GET /api/v1/campaigns`
- `POST /api/v1/campaigns`
- `POST /api/v1/campaigns/{id}/activate`
- `POST /api/v1/campaigns/{id}/close`
- `GET /api/v1/campaigns/{id}/participants`
- `POST /api/v1/campaigns/{id}/participants`

### Medición móvil

`POST /api/v1/mobile/measurements`

Requiere sesión Rhino. El dispositivo debe pertenecer al socio, estar activo y estar vinculado a un evento activo.

Ejemplo:

```json
{
  "device_id": "AA:BB:CC:DD:EE:FF",
  "campaign_id": "CAM_01...",
  "id_medicion": "UUID",
  "timestamp_local": "2026-07-22T18:10:00-07:00",
  "consent_granted": true,
  "paciente": {
    "nombre": "María López",
    "telefono_whatsapp": "526691234567",
    "tipo_visita": "NUEVO_PROSPECTO",
    "edad": 40,
    "altura_cm": 165,
    "sexo": "F",
    "perfil": "NORMAL"
  },
  "resultados": {
    "crudos": {
      "peso_kg": 68.4,
      "impedancia_ohm": 512
    },
    "calculados": {
      "imc": 25.1,
      "grasa_porcentaje": 31.2,
      "masa_magra_kg": 47.1,
      "musculo_porcentaje": 38.5,
      "grasa_subcutanea_porcentaje": 27.1,
      "grasa_visceral": 8,
      "agua_porcentaje": 50.3,
      "musculo_esqueletico_porcentaje": 33.2,
      "masa_muscular_kg": 44.6,
      "masa_osea_kg": 2.5,
      "proteina_porcentaje": 16.1,
      "bmr_kcal": 1395,
      "edad_metabolica": 43
    }
  }
}
```

Respuesta:

```json
{
  "success": true,
  "data": {
    "measurement_id": "UUID",
    "contact_id": "CNT_...",
    "campaign_id": "CAM_...",
    "is_new_contact": true,
    "already_processed": false
  }
}
```

## Inventario y finanzas

La migración crea estructuras futuras sin activarlas:

- `inventory_accounts`
- `inventory_ledger_entries`
- `finance_ledger_entries`
- campos adicionales en productos, ventas y movimientos

Feature flags iniciales:

- `inventory_ledger_v2=false`
- `finance_reports_v2=false`

No cambia el comportamiento actual de ventas ni inventario.

## Preparación futura de inventario

La actualización deja una cuenta de inventario general por socio y permite modelar en una fase posterior cuentas de asesor y evento. El ledger admite entradas, cargas iniciales sujetas a aprobación, ventas por evento, ventas individuales, devoluciones, mermas y correcciones mediante contramovimientos.

Los productos siguen bajo el permiso `products.create`, disponible por defecto solo para `PARTNER_ADMIN`. Los asesores no pueden crear productos. La futura carga inicial del asesor se registrará en su cuenta individual y podrá quedar `PENDING` hasta la aprobación del administrador.

## Preparación futura de finanzas

`finance_ledger_entries` almacena importes en unidades menores y usa `MXN` por defecto. Está preparado para atribuir ingresos o egresos a venta, evento, cliente, asesor y método de pago. La API móvil no escribe todavía en este ledger.

## Registro de la báscula desde Android

El administrador del socio puede usar la ruta existente `POST /api/v1/devices` para registrar la MAC que ya fue validada por Bluetooth en el teléfono. La V2.5 ofrece esta acción desde la pantalla principal. Los asesores pueden utilizar una báscula ya registrada, pero no crear una nueva.
