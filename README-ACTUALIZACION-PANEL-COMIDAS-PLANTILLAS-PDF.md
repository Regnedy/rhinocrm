# Actualización RHINO CRM: comidas, plantillas de dietas y PDF

Fecha: 23 de julio de 2026

## Alcance

Esta actualización incorpora dos módulos administrativos dentro de **RHINO Nutrición**:

1. **Comidas**
   - Alta manual de preparaciones.
   - Edición de nombre, porción, ingredientes, preparación, clasificación, alérgenos y estimación nutricional.
   - Duplicación de comidas globales o propias.
   - Aprobación de borradores.
   - Eliminación de registros sin historial.
   - Archivado automático cuando una comida ya fue utilizada en un plan, para no romper versiones anteriores.

2. **Plantillas de dietas**
   - Creación, edición, duplicación, activación y retiro de plantillas.
   - Reglas configurables del motor: comidas por día, repeticiones, tiempo máximo, patrones alimentarios, presupuesto, grupos preferidos y distribución de macros.
   - Editor del contenido del PDF: títulos, reglas, hidratación, seguimiento, alimentos a limitar, aportes, recetario, nota al pie y visibilidad de secciones.
   - Versionado de plantillas mediante `nutrition_template_versions`.

## Nuevo diseño del PDF

El plan alimenticio adopta una estructura visual de tarjetas y páginas separadas:

- Resumen personal.
- Metas calóricas y macros.
- Guía rápida de porciones.
- Tabla semanal flexible en dos páginas.
- Hidratación y recomendaciones de seguimiento.
- Alimentos a limitar.
- Aportes de alimentos.
- Recetario.

La palabra solicitada para omisión no aparece en la plantilla nueva. El encabezado inicial es **“1. Resumen Personal”**.

## Instalación recomendada

1. Realizar respaldo de la base de datos y del código actual.
2. Descomprimir el paquete de actualización sobre la raíz del proyecto RHINO CRM, conservando la estructura de carpetas.
3. Ejecutar:

```bash
php artisan migrate --force
php artisan optimize:clear
```

4. Confirmar que el servidor PHP tenga habilitada la extensión `ext-dom`, requerida por Dompdf para generar los PDF.
5. Ingresar con un usuario `PARTNER_ADMIN` y abrir:
   - `RHINO Nutrición > Comidas`
   - `RHINO Nutrición > Plantillas de dietas`

## Permisos

Se agregó el permiso:

```text
nutrition.templates.manage
```

Los permisos predeterminados de `PARTNER_ADMIN` ya lo incluyen. La migración también lo agrega a usuarios administradores que tengan una lista explícita de permisos.

## Comportamiento de eliminación

La eliminación es conservadora:

- Una comida o plantilla sin referencias se elimina físicamente.
- Una comida o plantilla utilizada por planes previos se archiva y deja de participar en nuevas generaciones.

Así se preserva la integridad de los planes históricos.

## Archivos principales modificados

- `public/assets/rhino-crm.js`
- `public/assets/rhino-crm.css`
- `routes/api_v1.php`
- `resources/views/reports/nutrition-plan.blade.php`
- `app/Modules/NutritionEngine/Controllers/NutritionEngineController.php`
- `app/Modules/NutritionEngine/Services/NutritionPillService.php`
- `app/Modules/NutritionEngine/Services/NutritionTemplateService.php`
- `app/Modules/NutritionEngine/Services/NutritionPlanEngineService.php`
- `app/Modules/NutritionEngine/Services/NutritionPlanPdfService.php`
- `app/Modules/NutritionEngine/Support/NutritionDocumentTemplate.php`
- `app/Modules/Auth/Services/RolePermissionService.php`
- `database/migrations/2026_07_23_000100_add_nutrition_library_management.php`

## Validaciones realizadas

- Sintaxis PHP de los archivos modificados.
- Sintaxis JavaScript con `node --check`.
- Registro de las 34 rutas del motor nutricional con `php artisan route:list`.
- Renderizado visual de una vista previa de 10 páginas y revisión de tablas, saltos de página y tipografía.

El contenedor de preparación no dispone de `ext-dom` ni del controlador PDO SQLite; por ello no fue posible ejecutar Dompdf ni la suite completa de pruebas dentro de este entorno. La vista previa se validó con un motor HTML-to-PDF compatible. El servidor de producción debe conservar `ext-dom`, requisito ya asociado al generador Dompdf existente.
