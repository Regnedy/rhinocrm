# Contrato nativo — RHINO CRM Android

## Objetivo

Permitir que la interfaz RHINO CRM prepare un PDF privado de plan alimenticio y que la
aplicación Android lo comparta de forma segura.

## Función expuesta a JavaScript

```text
RhinoCRM.sharePdf(pdfUrl, filename, phoneE164, message, bearerToken)
```

## Responsabilidades Android

- Aceptar únicamente URL HTTPS del dominio RHINO configurado.
- Descargar el archivo con `Authorization: Bearer <token>`.
- Validar `Content-Type: application/pdf`.
- Aplicar límite de tamaño.
- Guardar temporalmente en almacenamiento interno.
- Generar URI mediante AndroidX `FileProvider`.
- Agregar permiso temporal de lectura.
- Crear un `ACTION_SEND` de tipo `application/pdf`.
- Incluir mensaje y archivo.
- Abrir Android Sharesheet.
- No exponer rutas `file://`.
- No escribir el token, PDF o información clínica en logs.
- Eliminar archivos temporales.
- Registrar en el backend que la compartición fue iniciada.

## Contacto exacto

El selector nativo o WhatsApp controla la elección final del destinatario. El puente
puede sugerir o abrir WhatsApp, pero no debe depender de interfaces privadas para forzar
simultáneamente contacto exacto y archivo.

Para un envío al número exacto, la app debe usar el endpoint de envío directo del
backend:

```text
POST /api/v1/nutrition-engine/plans/{id}/send-whatsapp
```

## Seguridad de WebView

- Permitir navegación únicamente al dominio oficial.
- Bloquear contenido mixto.
- No habilitar acceso universal desde archivos.
- No exponer el puente a contenido no confiable.
- Invalidar la sesión cuando el servidor revoque el dispositivo o socio.
