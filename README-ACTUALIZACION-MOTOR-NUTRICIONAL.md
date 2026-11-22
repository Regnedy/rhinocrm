# Rhino CRM — RHINO Nutrición Engine

**Versión:** `2026.07.22-nutrition-engine.1`

Esta actualización agrega al CRM un motor autónomo para construir planes alimentarios
de orientación general a partir del perfil de la persona, su pesaje actual y su
historial reciente. La aplicación RHINO Kiosco no cambia.

## Alcance

- Perfil alimentario por prospecto.
- Biblioteca administrable de píldoras alimentarias.
- Biblioteca inicial de 106 preparaciones y 5 plantillas.
- Solo las píldoras aprobadas participan en los planes.
- Generación autónoma de menús de siete días.
- Exclusión por alergias, patrón alimentario y alimentos que la persona evita.
- Priorización por objetivo, actividad, presupuesto y tiempo de preparación.
- Comparación de hasta cuatro pesajes recientes.
- Versionado de planes, píldoras y decisiones del motor.
- Sustituciones, lista de compras, hidratación y guía de porciones.
- Estado de seguridad y señalamiento de revisión profesional.
- Laboratorio de prompts para ampliar la biblioteca.
- Validación y aprobación antes de incorporar contenido generado con IA.
- Integración con el PDF personal y las campañas de seguimiento nutricional.

## Biblioteca inicial

```text
Total de píldoras: 106
Aprobadas: 92
Borradores: 14
Desayunos: 28
Comidas: 25
Cenas: 28
Colaciones: 25
Plantillas activas: 5
```

Las 100 preparaciones provenientes del prototipo compartido se normalizaron como
datos estructurados. Se retiraron del motor los cálculos fijos, el lenguaje de
diagnóstico, los productos detox y la suplementación automática. Se agregaron seis
preparaciones generales para mejorar la cobertura de restricciones frecuentes.

Los 14 borradores no se utilizan en planes hasta que sean revisados y aprobados.

## Operación autónoma

Al generar un plan, RHINO no consulta una IA externa. Utiliza exclusivamente:

```text
Perfil del prospecto
+ pesaje actual
+ historial de pesajes
+ plantillas activas
+ píldoras aprobadas
+ reglas de seguridad
+ puntuación y validación
```

## Laboratorio de prompts

La IA se utiliza únicamente para proponer contenido nuevo para la biblioteca:

```text
RHINO crea el prompt
→ el administrador lo ejecuta en la IA elegida
→ pega en RHINO la respuesta JSON
→ RHINO valida cada propuesta
→ el administrador aprueba o rechaza
→ solo lo aprobado entra en la biblioteca
```

Esta versión usa `MANUAL_JSON`: no requiere una clave de IA y la generación normal
de planes continúa funcionando aunque no exista conexión con un proveedor externo.

## Migración

La migración nueva es:

```text
2026_07_22_000900_create_nutrition_engine
```

Es incremental. Su método `down()` no elimina tablas, columnas ni datos.

## Instalación resumida

```bash
cd /home2/manue221/rhinocrm

cp .env ".env.backup-nutrition-engine-$(date +%Y%m%d-%H%M%S)"

unzip -o Rhino_CRM_Nutrition_Engine_Update_2026-07-22.zip

composer update dompdf/dompdf   --with-all-dependencies   --no-dev   --prefer-dist   --optimize-autoloader

php artisan migrate --force
php artisan rhino:nutrition-seed-library
php artisan rhino:contacts-backfill-codes
php artisan optimize:clear
```

No ejecutar:

```text
php artisan migrate:fresh
php artisan migrate:reset
php artisan migrate:rollback
php artisan db:wipe
```
