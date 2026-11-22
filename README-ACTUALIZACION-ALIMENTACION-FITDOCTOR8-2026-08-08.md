# RHINO CRM — Actualización de Alimentación FitDoctor 8

**Base compatible:** `rhinocrm080826`  
**Release:** `2026.08.08-nutrition-fitdoctor8.1`  
**Motor:** `rhino-nutrition-engine-v2`  
**Biblioteca:** `2026.08.08-fitdoctor8-ref-v2`

## Qué actualiza

- Conserva el motor nutricional RHINO y sus controles de seguridad; no adopta el BMR fijo de FitDoctor 8.
- Añade 35 preparaciones estructuradas de referencia FitDoctor 8: 7 desayunos, 7 comidas, 7 cenas y 14 colaciones.
- Mantiene las preparaciones previas de RHINO. La biblioteca pasa de 106 a 141 píldoras.
- Mejora el PDF con resumen, referencia IMC no diagnóstica, objetivos, porciones, hidratación, límites, lista de compras, plan semanal, beneficios, recetas, estrategias ante antojos y seguimiento.
- La lista de compras queda visible por defecto en documentos nuevos/no personalizados.
- Añade un bloque editable y desactivable de estrategias ante antojos con lenguaje conductual neutro.
- Añade detección de alérgeno `SESAME` para sésamo, ajonjolí y tahini.
- Corrige el versionado del seeder: cada snapshot usa `library_version` del JSON y conserva `BUILTIN_V1`/`BUILTIN_V2`.
- `--force` ya no puede sobrescribir por accidente registros GLOBAL que no sean `BUILTIN_*`.
- No incorpora afirmaciones de detox, eliminación de toxinas, grasa visceral ni promesas médicas de FitDoctor 8.

## Instalación en producción

1. Haz respaldo del código y de MySQL antes de reemplazar archivos.
2. Descomprime este ZIP sobre la raíz del proyecto RHINO, conservando las rutas.
3. Revisa si `.env` tiene valores explícitos antiguos para estas variables. Si no existen, los nuevos defaults de `config/rhino.php` se aplican automáticamente. Si existen, actualízalos a:

```env
RHINO_VERSION=2026.08.08-nutrition-fitdoctor8.1
RHINO_NUTRITION_ALGORITHM=rhino-nutrition-engine-v2
RHINO_NUTRITION_LIBRARY_VERSION=2026.08.08-fitdoctor8-ref-v2
RHINO_PERSONAL_REPORT_ALGORITHM=rhino-nutrition-engine-v2
```

4. Limpia cachés y carga las nuevas preparaciones:

```bash
php artisan optimize:clear
php artisan rhino:nutrition-seed-library
php artisan optimize:clear
```

No se requiere una migración nueva para esta actualización.

## Prueba rápida obligatoria

1. Abre un contacto con perfil suficiente para generar alimentación.
2. Genera un plan nuevo.
3. Verifica que el plan tenga opciones reales de desayuno, colación, comida y cena.
4. Descarga el PDF y confirma que incluya lista de compras antes del plan semanal.
5. Confirma que aparezca la sección `Estrategias ante Antojos` salvo que la plantilla la tenga desactivada.
6. Confirma que el PDF mantenga el aviso de que el IMC y el plan son orientativos y no diagnósticos.
7. Prueba un perfil que declare alergia a sésamo/ajonjolí y confirma que no se seleccionen preparaciones marcadas con `SESAME`.

## Compatibilidad con plantillas existentes

- Plantillas que nunca personalizaron el documento reciben los nuevos defaults.
- Configuraciones ya personalizadas continúan respetando sus flags, por ejemplo una lista de compras desactivada manualmente.
- Los PDFs/planes históricos conservan su snapshot y no son reescritos por este parche.

## Rollback

Restaura el respaldo de código y, si necesitas volver exactamente al estado previo de datos, restaura también el respaldo de MySQL. No uses `migrate:rollback`, `migrate:reset`, `migrate:fresh` ni `db:wipe` para revertir este parche.

## Archivos sensibles

Este paquete no incluye `.env`, credenciales Firebase, logs, bases SQL ni documentos privados.
