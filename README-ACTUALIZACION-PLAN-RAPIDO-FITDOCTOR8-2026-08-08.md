# RHINO CRM — Plan Alimenticio Rápido FitDoctor 8

Versión objetivo CRM: `2026.08.08-quick-plan-fitdoctor8.1`
Versión objetivo Kiosco: `2.6.8-quick-nutrition`
Fecha: 2026-08-08

## Qué cambia

El pesaje del Kiosco puede convertirse inmediatamente en un Plan Alimenticio Rápido sin volver a capturar datos biométricos. RHINO reutiliza el `measurement_id` y todos los valores ya sincronizados, genera el plan con el motor nutricional RHINO y lo presenta con una plantilla adaptada al estilo PHP de FitDoctor 8.

Salidas disponibles:

- PDF.
- Imágenes JPG/PNG, una por página, generadas desde el mismo HTML del PDF.

## Flujo Kiosco

1. Termina el pesaje.
2. En resultados pulsa **GENERAR PLAN ALIMENTICIO RÁPIDO**.
3. Si ya hay WhatsApp, no se pregunta nada.
4. Si falta, se solicita solamente WhatsApp con código de país.
5. RHINO sincroniza el mismo pesaje y genera el plan.
6. El operador/prospecto elige **PDF** o **IMÁGENES** y Android abre el selector de compartir.

## Flujo CRM

En Alimentación del contacto, si existe una medición, aparece **⚡ Plan rápido del pesaje** además de **Plan completo**. El plan rápido no abre el formulario nutricional.

## Datos utilizados

El documento muestra todo valor disponible del pesaje, incluyendo peso, IMC, grasa corporal, masa muscular, porcentaje muscular, agua, grasa visceral, BMR, grasa subcutánea, músculo esquelético, masa magra, masa ósea, proteína, edad metabólica, estatura y edad.

Los valores ausentes no se inventan: se muestran como no disponibles/son omitidos por la lógica correspondiente.

## Instalación CRM

Aplicar el contenido del parche sobre la raíz de `rhinocrm080826` conservando las rutas del ZIP.

Después:

```bash
php -m | grep -Ei 'dom|gd|mbstring'
php artisan optimize:clear
php artisan rhino:nutrition-seed-library
php artisan route:list | grep -E 'quick-nutrition|nutrition-plans/.*/images'
php artisan optimize:clear
```

No requiere una migración nueva.

### Variables recomendadas

Si producción tiene estos valores fijados en `.env`, actualizar manualmente:

```env
RHINO_VERSION=2026.08.08-quick-plan-fitdoctor8.1
RHINO_NUTRITION_ALGORITHM=rhino-nutrition-engine-v2
RHINO_NUTRITION_LIBRARY_VERSION=2026.08.08-fitdoctor8-ref-v2
RHINO_NUTRITION_QUICK_PLAN_VERSION=fitdoctor8-kiosk-v1
RHINO_NUTRITION_IMAGE_DPI=120
RHINO_NUTRITION_IMAGE_QUALITY=88
```

No reemplazar el `.env` de producción con ningún archivo del parche.

## Requisitos del servidor para formatos

- PDF: PHP `ext-dom`.
- Imágenes: PHP `ext-gd` + `ext-dom`.
- Texto/Unicode: PHP `ext-mbstring`.

Si GD no está activo, la API de imágenes responde 503 en vez de generar un archivo inválido.

## Endpoints agregados

### Kiosco/mobile

- `POST /api/v1/mobile/measurements/{measurementId}/quick-nutrition`
- `GET /api/v1/mobile/nutrition-plans/{id}/pdf`
- `GET /api/v1/mobile/nutrition-plans/{id}/images?format=jpg`
- `GET /api/v1/mobile/nutrition-plans/{id}/images/{page}?format=jpg`

### CRM

- `POST /api/v1/nutrition-engine/measurements/{measurementId}/quick-nutrition`
- `GET /api/v1/nutrition-engine/plans/{id}/pdf`
- `GET /api/v1/nutrition-engine/plans/{id}/images?format=jpg`
- `GET /api/v1/nutrition-engine/plans/{id}/images/{page}?format=jpg`

## Seguridad

- Sin campaña: tenant normal.
- En evento colaborativo: solo el Plan Rápido vinculado al `campaign_id` del pesaje puede ser consumido por un colaborador activo del evento.
- Esta excepción no abre planes estándar ni planes de otros eventos.
- El paquete no debe contener `.env`, `firebase-admin.json`, `google-services.json`, bases SQL, logs ni archivos privados.

## Nota sobre “desintoxicación”

El documento conserva el bloque solicitado **Desintoxicación / reinicio de hábitos**, pero el texto lo presenta como una fase breve de reorganización de hábitos, hidratación, alimentos simples y reducción de ultraprocesados; no afirma eliminar toxinas ni sustituir atención médica.
