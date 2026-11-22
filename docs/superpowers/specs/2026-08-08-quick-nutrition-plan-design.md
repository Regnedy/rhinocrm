# RHINO Plan Alimenticio Rápido — Diseño aprobado

Fecha: 2026-08-08

## Objetivo

Convertir el pesaje recién terminado en RHINO Kiosco en un entregable inmediato que también cierre la captura del prospecto: generar un Plan Alimenticio Rápido desde el mismo `measurement_id`, sin volver a pedir biométricos ni completar un cuestionario nutricional.

## Alcance funcional

- El Kiosco muestra `GENERAR PLAN ALIMENTICIO RÁPIDO` en la pantalla final del pesaje.
- Se reutilizan todos los datos ya persistidos por el pesaje: edad, sexo, talla, peso, IMC, grasa, músculo, agua, grasa visceral, metabolismo basal, edad metabólica y cualquier métrica adicional disponible.
- Si el prospecto ya tiene WhatsApp válido, no se solicita ningún dato adicional.
- Si falta WhatsApp, Kiosco solicita únicamente el número con código de país.
- El mismo motor puede ejecutarse desde RHINO CRM sobre una medición existente.
- La salida puede entregarse como PDF o como imágenes JPG/PNG por página.
- PDF e imágenes utilizan exactamente el mismo HTML/Blade para que contenido, termómetro y diseño sean consistentes.
- El plan rápido no sustituye al Plan Nutricional Completo: es un flujo automático post-pesaje.

## Arquitectura

### Fuente de verdad

`measurement_id` es la fuente de verdad. El cliente nunca vuelve a enviar peso, IMC, grasa, agua u otros biométricos para calcular el plan. El backend lee la medición persistida y genera un snapshot inmutable dentro de la versión del plan.

### Motor

`QuickNutritionPlanService` resuelve medición/contacto, captura opcionalmente WhatsApp y llama a `NutritionPlanEngineService` con:

- BMR real de la báscula cuando está disponible.
- Actividad `Moderada` como supuesto del flujo rápido, evitando otro formulario.
- 5 tiempos de comida para mantener la estructura FitDoctor: desayuno, colación AM, comida, colación PM y cena.
- Objetivo inicial derivado del IMC, sin diagnóstico médico.

### Documento

`resources/views/reports/nutrition-quick-plan.blade.php` adapta el estilo PHP de FitDoctor 8:

- encabezado verde/gris;
- datos biométricos en la parte superior;
- termómetro de IMC;
- bloques numerados;
- metas calóricas/macros;
- hidratación dinámica;
- porciones;
- hábitos a limitar;
- bloque `Desintoxicación / reinicio de hábitos` redactado como reorganización de hábitos, no como afirmación de eliminación de toxinas ni tratamiento;
- menú semanal;
- lista de compras;
- recetas/preparaciones;
- seguimiento.

### Renderizado

- PDF: Dompdf PDF normal.
- Imágenes: Dompdf con backend GD, una imagen por página del mismo HTML.
- Se guarda un manifiesto de imágenes y se reutiliza en descargas posteriores.

## Flujo Kiosco

1. Finaliza el pesaje y se guarda `Patient + Measurement` localmente.
2. El botón Plan Rápido reutiliza el registro existente.
3. Si falta WhatsApp, solicita solo WhatsApp.
4. Kiosco fuerza sincronización idempotente del mismo `measurement.id` con RHINO CRM.
5. POST `/api/v1/mobile/measurements/{measurementId}/quick-nutrition`.
6. Backend genera/persiste el plan rápido.
7. Kiosco permite elegir `PDF` o `IMÁGENES`.
8. Los archivos se descargan autenticados a caché y se comparten mediante `FileProvider`.

## Flujo CRM

Desde la vista de alimentación del contacto, cuando existe una medición reciente:

- `⚡ Plan rápido del pesaje` genera directamente desde `latest_measurement_id`.
- `Plan completo` conserva el flujo nutricional actual.
- La vista del plan permite PDF e imágenes.

## Seguridad y multi-tenant

- Medición sin campaña: solo el socio propietario puede generar el plan, salvo SUPER_ADMIN.
- Medición de evento colaborativo: se usa `CampaignAccessService`; solo miembros/roles con acceso activo al evento pueden generar o recuperar el Plan Rápido asociado a esa medición.
- La excepción colaborativa no se aplica a planes nutricionales estándar ni a otros eventos.
- Un plan extranjero no autorizado responde como no encontrado para evitar enumeración cross-tenant.
- No se incorporan secretos, `.env`, Firebase Admin ni datos privados en los parches de distribución.

## Errores esperados

- 404: pesaje/plan inexistente o fuera de contexto no visible.
- 403: usuario sin acceso al evento/equipo.
- 409: pesaje sin prospecto, WhatsApp duplicado u otra inconsistencia de estado.
- 422: formato/entrada inválidos.
- 503: servidor sin PHP GD para renderizar imágenes.

## Dependencias operativas

- PHP 8.3+.
- `ext-dom` para Dompdf.
- `ext-gd` para PNG/JPG.
- `ext-mbstring` y extensiones ya requeridas por RHINO.
- No requiere migración nueva.

## Criterios de aceptación

1. Un pesaje existente genera plan sin formulario nutricional.
2. Los biométricos del pesaje aparecen arriba del documento.
3. El PDF mantiene termómetro/estructura visual FitDoctor.
4. PDF e imágenes salen del mismo HTML.
5. WhatsApp es el único dato que Kiosco puede pedir si falta.
6. Kiosco puede compartir PDF o múltiples imágenes.
7. CRM puede generar el mismo tipo de plan desde una medición.
8. El acceso multi-tenant/evento no permite leer planes ajenos fuera de una colaboración válida.
