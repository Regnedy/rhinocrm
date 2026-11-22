# Rhino CRM Mobile App v1

## Objetivo

Ofrecer una aplicación móvil operativa sobre la misma API, reglas multiempresa, permisos y propiedad de contactos del CRM web.

## Endpoints agregados

```text
GET  /api/v1/mobile/app/config
GET  /api/v1/mobile/app/access
GET  /api/v1/mobile/app/bootstrap
GET  /api/v1/mobile/app/dashboard
GET  /api/v1/mobile/app/notifications
PUT  /api/v1/mobile/app/device
```

Todos requieren sesión Bearer y permiso `mobile.context`.

## Contrato bootstrap

`rhino.mobile.bootstrap.v1` agrupa:

- Estado de acceso.
- Configuración móvil.
- Sesión y socio.
- Dashboard.
- Notificaciones.

## Aplicación

La app consume también los módulos existentes:

- Contactos.
- Eventos colaborativos.
- Pesaje visual.
- Nutrición.
- Seguimientos.
- Ventas del evento.

## Facturación

El servidor tiene la estructura preparada pero devuelve acceso de desarrollo cuando las banderas están apagadas. Ninguna ruta móvil inicia compras.
