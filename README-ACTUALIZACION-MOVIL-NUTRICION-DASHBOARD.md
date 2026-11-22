# RHINO CRM — Nutrición móvil, PDF, WhatsApp y dashboard configurable

**Versión:** `2026.07.22-mobile-nutrition-share.1`

Esta actualización acumulativa agrega al CRM web/PWA las funciones necesarias para
trabajar el plan alimenticio desde la ficha del prospecto y prepara el contrato nativo
para la futura aplicación Android RHINO CRM.

No modifica RHINO Kiosco.

## Funciones principales

- México `+52` como prefijo telefónico predeterminado.
- Captura habitual de WhatsApp con solo 10 dígitos.
- Normalización interna al formato `+52##########`.
- Pestaña **Nutrición** funcional dentro del expediente del prospecto.
- Generación de un plan alimenticio desde el celular.
- Conservación de cada versión del plan.
- Visualización del menú y recetas con emojis.
- PDF privado con nombre, pesaje, plan, recetas, sustituciones, compras y recomendaciones.
- Compartición móvil mediante Web Share o puente nativo Android.
- Envío directo al número verificado mediante WhatsApp Cloud API.
- Registro de envío, entrega, lectura y fallo.
- Dashboard personalizable por usuario.
- Catálogo inicial de nueve gadgets.
- Diseño persistente entre navegador, PWA y futura app CRM.

## Compartición por WhatsApp

Existen dos modalidades:

### Compartir desde el teléfono

```text
Prospecto
→ Nutrición
→ Ver plan
→ Compartir PDF
```

En una futura APK, el puente nativo descargará el PDF autenticado, lo expondrá mediante
una URI segura y abrirá el selector de aplicaciones con el documento adjunto.

En la PWA, cuando el navegador admite compartir archivos, RHINO utiliza el selector
nativo. Como alternativa, descarga el PDF y abre el chat correspondiente para que el
usuario adjunte el archivo.

### Enviar directamente

```text
Prospecto
→ Nutrición
→ Enviar directo
```

RHINO utiliza WhatsApp Cloud API y envía el PDF al número verificado del expediente.
Este modo requiere consentimiento, configuración Meta y una conversación vigente o
una plantilla aprobada con encabezado de documento.

## Dashboard configurable

Cada usuario puede:

- Activar u ocultar gadgets.
- Cambiar su tamaño.
- Cambiar su orden.
- Guardar la distribución en su cuenta.

Gadgets iniciales:

1. Prospectos.
2. Actividades pendientes.
3. Planes alimentarios.
4. Pesajes recientes.
5. Próximos eventos.
6. Mi organización.
7. Dispositivos.
8. Movimientos recientes.
9. Resultados comerciales.

## Migración

```text
2026_07_22_001100_add_mobile_nutrition_pdf_and_dashboard_gadgets
```

Es incremental y su método `down()` es deliberadamente no destructivo.

## Códigos de movimiento

```text
604 PDF de plan alimentario generado
605 Compartición de plan iniciada
606 Plan alimentario enviado por WhatsApp
607 Plan alimentario entregado
608 Plan alimentario leído
609 Envío de plan alimentario fallido
```
