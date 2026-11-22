# RHINO CRM — Gobierno de socios, jerarquía y movimientos

**Versión:** `2026.07.22-partner-governance.1`

Esta actualización acumulativa agrega control organizacional y trazabilidad tipo ERP
al CRM web/PWA. No modifica la aplicación RHINO Kiosco.

## Alcance

- Socio ID numérico, único y visible.
- Nombre del socio separado de su identificador.
- Catálogo configurable de rangos sin porcentajes, comisiones ni compensaciones.
- Patrocinador directo y línea upline.
- Historial de rangos y correcciones de patrocinador.
- Credencial digital verificable.
- Solicitudes de apoyo dirigidas primero al upline.
- Movimientos de negocio con código y folio `RH-MOV-AAAA-######`.
- Registro de usuario, IP, navegador/app, objeto afectado y metadatos.
- Pantalla de dispositivos: apps, computadoras, navegadores y básculas.
- Suspensión y reactivación reversible de socios sin borrar información.
- Separación entre “Prospecto registrado por” y “Socio responsable”.
- Reasignación de prospectos mediante checklist conservando el socio que los registró.

## Rangos iniciales

1. Afiliado
2. Afiliado Calificado
3. Aprendiz
4. Aprendiz Élite
5. Supervisor
6. Bronce
7. Plata
8. Oro
9. Platino
10. Zafiro
11. Rubí
12. Esmeralda
13. Diamante
14. Doble Diamante
15. Triple Diamante
16. Diamante Corona

`Oro Rosado` no se precarga porque no apareció en la tabla definitiva recibida. El
SUPER_ADMIN puede agregarlo posteriormente y definir su posición jerárquica.

## Sin porcentajes

Este módulo no almacena ni calcula porcentajes, comisiones, bonos o compensaciones.
Esos procesos permanecen en el sistema actual de la compañía.

## Movimientos incluidos

```text
101 Entrada de inventario
102 Reverso de entrada
201 Salida de inventario
202 Reverso de salida
301 Transferencia de inventario
401 Venta contabilizada
402 Reembolso o reverso de venta
501 Registro de prospecto
541 Registro de socio
542 Asignación de patrocinador
543 Corrección de patrocinador
544 Actualización de Socio ID
545 Actualización de datos del socio
551 Cambio de rango
561 Cambio de socio responsable
571 Solicitud de apoyo al upline
572 Escalamiento de solicitud
601 Registro de pesaje
602 Generación de plan alimentario
603 Actualización de plan alimentario
701 Inclusión en campaña
702 Invitación enviada
703 Confirmación recibida
801 Dispositivo registrado
802 Dispositivo autorizado
803 Dispositivo revocado o suspendido
901 Socio suspendido
902 Socio reactivado
911 Credencial emitida
912 Credencial revocada
```

Los folios se generan en secuencia anual:

```text
RH-MOV-2027-000001
RH-MOV-2027-000002
```

## Migración

```text
2026_07_22_001000_create_partner_governance_and_movements
```

La migración es incremental. Su método `down()` es deliberadamente no destructivo.
