# RHINO Quick Nutrition Plan Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generar y entregar un Plan Alimenticio Rápido desde un pesaje RHINO existente, sin formulario nutricional adicional, disponible en CRM y Kiosco como PDF o imágenes.

**Architecture:** Un servicio backend único recibe `measurement_id`, crea un snapshot biométrico y reutiliza el motor nutricional RHINO. Una única plantilla Blade estilo FitDoctor alimenta tanto Dompdf PDF como el backend GD por página; Android y CRM solo disparan/descargan resultados.

**Tech Stack:** Laravel 13, PHP 8.3+, MySQL/MariaDB, Dompdf 3.1, PHP GD, JavaScript RHINO CRM, Android/Kotlin.

## Global Constraints

- No volver a pedir biométricos ya capturados por Kiosco.
- WhatsApp es el único dato adicional permitido en Kiosco y solo cuando falta.
- Mantener Plan Rápido y Plan Completo como productos separados.
- No agregar migraciones destructivas.
- No distribuir secretos ni credenciales.
- Conservar aislamiento multi-tenant; acceso cross-partner solo para colaboradores activos del evento de la medición.

---

### Task 1: Snapshot y recomendaciones rápidas

**Files:**
- Create: `app/Modules/NutritionEngine/Support/QuickNutritionMeasurementSnapshot.php`
- Create: `app/Modules/NutritionEngine/Support/QuickNutritionRecommendations.php`
- Test: `tests/Standalone/QuickNutritionFeatureTest.php`

**Interfaces:**
- Consumes: `biometrics_raw`, `biometrics_calculated`, edad/sexo/talla del contacto.
- Produces: snapshot normalizado y recomendaciones de hidratación/reinicio.

- [x] Escribir prueba contractual que preserve las métricas del Kiosco y falle sin helpers.
- [x] Ejecutar y confirmar RED.
- [x] Implementar normalización/recomendaciones mínimas.
- [x] Ejecutar y confirmar GREEN.

### Task 2: Generación desde measurement_id

**Files:**
- Create: `app/Modules/NutritionEngine/Services/QuickNutritionPlanService.php`
- Modify: `app/Modules/NutritionEngine/Services/NutritionPlanEngineService.php`
- Modify: `config/rhino.php`

**Interfaces:**
- Consumes: `generateForMeasurement(string $measurementId, ?string $whatsapp)`.
- Produces: plan persistido `source_type=QUICK_MEASUREMENT` y snapshot inmutable.

- [x] Añadir contrato de rutas/servicio.
- [x] Reutilizar BMR real y actividad Moderada sin formulario.
- [x] Persistir contexto de medición/campaña/dispositivo.
- [x] Registrar timeline del Plan Rápido.

### Task 3: Documento FitDoctor y formatos

**Files:**
- Create: `resources/views/reports/nutrition-quick-plan.blade.php`
- Modify: `app/Modules/NutritionEngine/Services/NutritionPlanPdfService.php`
- Create: `app/Modules/NutritionEngine/Services/NutritionPlanImageService.php`

**Interfaces:**
- Consumes: plan/snapshot/medición.
- Produces: PDF y manifiesto de páginas JPG/PNG con el mismo HTML.

- [x] Añadir contrato para termómetro y biométricos superiores.
- [x] Crear plantilla de tres páginas basada en el estilo FitDoctor 8.
- [x] Reutilizar plantilla en PDF y backend GD.
- [x] Cachear imágenes por plan/formato.

### Task 4: API y autorización

**Files:**
- Modify: `routes/api_v1.php`
- Modify: `app/Modules/NutritionEngine/Controllers/NutritionEngineController.php`
- Modify: `app/Modules/NutritionEngine/Services/NutritionPlanPdfService.php`

**Interfaces:**
- Produces: POST quick-nutrition; GET PDF; GET images manifest; GET image page para CRM/mobile.

- [x] Añadir endpoints mobile y CRM.
- [x] Preservar códigos 403/404/409/422/503.
- [x] Limitar acceso colaborativo a Plan Rápido + `measurement.campaign_id` autorizado.
- [x] Mantener planes estándar bajo tenant/equipo normal.

### Task 5: CRM web

**Files:**
- Modify: `public/assets/rhino-crm.js`

**Interfaces:**
- Consumes: `latest_measurement_id`.
- Produces: botones Plan Rápido, PDF e imágenes.

- [x] Añadir acción rápida sin formulario.
- [x] Mantener Plan Completo.
- [x] Añadir descarga de imágenes autenticada.

### Task 6: Kiosco Android 2.6.8

**Files:**
- Modify: `app/build.gradle.kts`
- Modify: `app/src/main/AndroidManifest.xml`
- Modify: `app/src/main/res/layout/activity_kiosk.xml`
- Modify: `app/src/main/java/com/activa7/rhinocrm/KioskActivity.kt`
- Modify: `app/src/main/java/com/activa7/rhinocrm/crm/CrmApiClient.kt`
- Create: `app/src/main/java/com/activa7/rhinocrm/crm/QuickNutritionClient.kt`

**Interfaces:**
- Consumes: `savedMeasurement.id`, prospecto y WhatsApp existente.
- Produces: generación remota + share PDF/múltiples JPG.

- [x] Añadir botón en resultado.
- [x] Solicitar solo WhatsApp si falta.
- [x] Sincronizar el mismo measurement de forma idempotente.
- [x] Generar plan por API.
- [x] Compartir PDF mediante FileProvider.
- [x] Compartir imágenes mediante ACTION_SEND_MULTIPLE.

### Task 7: Regresión y entrega

**Files:**
- Create: `tools/validate_quick_nutrition_268.py`
- Create: `tools/validate_multi_scale_268.py`
- Create: `tools/kiosk_268_ticket_socials_validation.py`
- Create: `README-ACTUALIZACION-PLAN-RAPIDO-FITDOCTOR8-2026-08-08.md`

- [x] Validar contratos PHP/Blade/JS.
- [x] Validar rutas Laravel.
- [x] Validar Kiosco 2.6.8 y regresión multi-báscula/ticket/redes.
- [ ] Compilar APK cuando exista Android SDK y acceso/caché de distribución Gradle.
- [ ] Ejecutar render físico PDF/JPG en servidor con `ext-dom` + `ext-gd` habilitadas.
