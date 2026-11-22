# Hotfix: menú Nutrición para SUPER_ADMIN

Este paquete corrige la navegación del administrador global.

Cambios:

- Agrega `Nutrición` al menú de `SUPER_ADMIN`.
- Al abrir Nutrición como administrador global, carga directamente `Comidas`.
- Mantiene disponibles las pestañas `Comidas` y `Plantillas de dietas`.
- Oculta `Personas y planes` al administrador global porque esa vista depende del contexto de un socio.
- Actualiza la versión del service worker para invalidar la caché anterior.

No contiene migraciones ni cambios de base de datos.
