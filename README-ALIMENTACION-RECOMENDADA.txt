RHINO CRM — INFORME CON ALIMENTACIÓN RECOMENDADA
Versión: 2026.07.22-nutrition-guidance.1

OBJETIVO
--------
Esta actualización corrige el alcance del informe personal.

El PDF individual ahora incluye:

- Historial de pesajes.
- Comparación con el primer registro.
- Alimentación recomendada para 7 días.
- Desayuno, comida, cena y colaciones según el perfil.
- Guía visual de porciones.
- Sustituciones.
- Lista de compras.
- Hidratación.
- Rango energético estimado únicamente cuando existen datos suficientes
  y no se detectan criterios que requieran valoración profesional.
- Aviso para consultar a un médico o profesional de nutrición.

El plan utiliza los datos disponibles de:

- Edad.
- Sexo registrado.
- Estatura.
- Peso.
- IMC estimado.
- Grasa corporal y masa libre de grasa estimadas.
- Metabolismo basal estimado.
- Objetivo.
- Actividad física.
- Número de comidas.
- Preferencias.
- Alergias.
- Condiciones declaradas.
- Medicamentos.
- Horarios.

Cuando el cuestionario está incompleto, se genera un menú general.
Cuando se detecta una condición que requiere atención profesional, el
documento se marca expresamente como orientación preliminar.

NO ES
-----
- Diagnóstico.
- Prescripción médica.
- Dieta clínica.
- Sustituto de una consulta.

INSTALACIÓN
-----------
Esta es una actualización combinada. Incluye la actualización anterior
de informes personales por evento y WhatsApp, más la alimentación
recomendada.

Respaldar MySQL y los archivos del CRM.

Subir este ZIP a:

/home2/manue221/rhinocrm/

Ejecutar:

cd /home2/manue221/rhinocrm

cp .env ".env.backup-nutrition-guidance-$(date +%Y%m%d-%H%M%S)"

unzip -o Rhino_CRM_Event_Reports_With_Nutrition_Guidance_Update_2026-07-22.zip

composer update dompdf/dompdf \
  --with-all-dependencies \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader

php artisan migrate --force
php artisan optimize:clear
php artisan rhino:contacts-backfill-codes

CONFIGURACIÓN
-------------
Actualizar o agregar:

RHINO_VERSION=2026.07.22-nutrition-guidance.1
RHINO_PERSONAL_REPORT_ALGORITHM=rhino-food-guidance-v2
RHINO_NUTRITION_ALGORITHM=rhino-food-guidance-v2

Después:

php artisan optimize:clear

MIGRACIÓN NUEVA
---------------
2026_07_22_000700_add_nutrition_recommendations_to_reports

La migración es incremental. No elimina tablas ni registros.

NO EJECUTAR
-----------
php artisan migrate:fresh
php artisan migrate:reset
php artisan migrate:rollback
php artisan db:wipe

PRUEBA
------
1. Crear un evento de prueba.
2. Registrar un participante con pesaje.
3. Vincular su código por WhatsApp.
4. Cerrar el evento.
5. Pulsar Enviar informes.
6. Abrir el PDF recibido.
7. Confirmar que contiene:
   - pesaje;
   - evolución;
   - menú de siete días;
   - porciones;
   - sustituciones;
   - lista de compras;
   - advertencia profesional.

LIMITACIÓN ACTUAL
-----------------
Todavía falta una interfaz completa en la app CRM para que el responsable
capture o edite el cuestionario nutricional. Hasta entonces, el menú usa
los campos disponibles en nutrition_profiles y un plan general cuando el
perfil está incompleto.
