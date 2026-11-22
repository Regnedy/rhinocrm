# RHINO Contact Measurement Dashboard API v1

Contrato estable para mostrar los resultados del pesaje en RHINO CRM web y en la futura aplicación RHINO CRM.

## Endpoints

```http
GET /api/v1/contacts/{contactId}/measurement-dashboard?limit=30
GET /api/v1/mobile/contacts/{contactId}/measurement-dashboard?limit=30
GET /api/v1/campaigns/{campaignId}/participants/{contactId}/measurement-dashboard
```

El workspace colaborativo del evento también incluye el mismo objeto en:

```json
{
  "measurement_dashboard": {
    "schema_version": 1,
    "contract": "rhino.contact.measurement-dashboard"
  }
}
```

## Autorización

- El endpoint de contactos respeta la propiedad del contacto, el socio y el equipo.
- El alias móvil utiliza el mismo servicio y requiere `contacts.read`.
- El endpoint del evento utiliza el acceso temporal del evento y requiere `campaigns.collaborate`.
- Después de cerrar un evento, el colaborador solo puede consultar los contactos que quedaron a su cargo, salvo administradores autorizados.

## Respuesta principal

```json
{
  "schema_version": 1,
  "contract": "rhino.contact.measurement-dashboard",
  "contact": {
    "id": "CNT_...",
    "name": "Persona",
    "age": 35,
    "gender": "F",
    "height_cm": 153
  },
  "latest": {
    "id": "MEASUREMENT_...",
    "measured_at": "2026-07-24 10:00:00",
    "metrics": [],
    "source": {}
  },
  "weight_scale": {},
  "series": [],
  "history": [],
  "summary": {},
  "presentation": {}
}
```

## Métrica

Cada elemento de `latest.metrics` contiene:

```json
{
  "code": "body_fat_percent",
  "label": "Grasa corporal",
  "short_label": "Grasa",
  "value": 38.0,
  "unit": "%",
  "decimals": 1,
  "icon": "body-fat",
  "description": "Porcentaje estimado de grasa corporal reportado por la báscula.",
  "derived": false,
  "tone": "neutral",
  "reference_code": null,
  "reference_label": null,
  "trend": {
    "previous_value": 39.4,
    "delta": -1.4,
    "direction": "DOWN"
  }
}
```

Los códigos previstos son:

- `weight_kg`
- `bmi`
- `body_fat_percent`
- `muscle_percent`
- `lean_mass_kg`
- `subcutaneous_fat_percent`
- `visceral_fat`
- `water_percent`
- `skeletal_muscle_percent`
- `muscle_mass_kg`
- `bone_mass_kg`
- `protein_percent`
- `bmr_kcal`
- `metabolic_age`

La aplicación debe ignorar códigos desconocidos para mantener compatibilidad hacia adelante.

## Indicador de peso

`weight_scale` se entrega únicamente cuando existen peso y estatura. Incluye límites, posición del marcador y cuatro segmentos visuales. La interfaz no debe recalcular esos valores; debe usar el contrato entregado por el servidor.

## Series e historial

`series` está ordenado de la medición más antigua a la más reciente para dibujar gráficas. `history` está ordenado de la medición más reciente a la más antigua para listas y tablas.

## Compatibilidad móvil

`GET /api/v1/mobile/context` publica:

```json
{
  "features": {
    "contact_measurement_dashboard_v1": true
  }
}
```

La app debe comprobar esta bandera antes de habilitar la pantalla visual.
