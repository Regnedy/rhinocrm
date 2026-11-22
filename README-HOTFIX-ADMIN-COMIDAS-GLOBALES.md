# Hotfix: administración global de comidas

Este paquete corrige la biblioteca de comidas para que el usuario `SUPER_ADMIN` vea y pueda utilizar las acciones **Editar**, **Aprobar** y **Quitar** sobre las comidas de origen **Global**.

También cambia la creación realizada por `SUPER_ADMIN`: las nuevas comidas se guardan como globales y quedan disponibles para todos los socios después de aprobarlas.

## Reglas después del hotfix

- `SUPER_ADMIN`: puede agregar, editar, aprobar y retirar comidas globales.
- Socios con `nutrition.pills.manage`: pueden agregar, editar y retirar las comidas de su propia organización.
- Los socios no pueden modificar directamente una comida global; pueden duplicarla para personalizarla.
- Al editar una comida, queda como `DRAFT` y debe aprobarse nuevamente.
- Al quitar una comida usada en planes históricos, se cambia a `ARCHIVED`; no se destruye el historial.

## Requisitos

Deben estar instalados previamente:

- `Rhino_CRM_Nutrition_Management_PDF_Update_2026-07-23.zip`
- `Rhino_CRM_Partner_Permissions_Admin_Update_2026-07-23.zip`

No requiere migraciones ni cambios en Composer.

## Instalación

Subir el ZIP a `/home2/manue221/rhinocrm` y ejecutar los comandos indicados en la conversación.
