<?php

namespace App\Modules\Auth\Services;

use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PartnerPermissionPolicyService
{
    /**
     * @return array<int, array{code:string,label:string,description:string,permissions:array<int, array{code:string,label:string,description:string,depends_on:string[]}>}>
     */
    public function catalog(): array
    {
        return [
            [
                'code' => 'accounts',
                'label' => 'Usuarios y acceso',
                'description' => 'Usuarios del socio, sesiones, ubicaciones, equipos y objetivos.',
                'permissions' => [
                    $this->permission('users.read', 'Consultar usuarios', 'Ver las cuentas registradas en la organización.'),
                    $this->permission('users.create', 'Crear usuarios', 'Dar de alta responsables, coordinadores y operadores.', ['users.read']),
                    $this->permission('users.suspend', 'Suspender usuarios', 'Suspender o reactivar cuentas de la organización.', ['users.read']),
                    $this->permission('sessions.read', 'Consultar sesiones', 'Ver sesiones y dispositivos de acceso activos.'),
                    $this->permission('sessions.revoke', 'Cerrar sesiones', 'Revocar sesiones de usuarios.', ['sessions.read']),
                    $this->permission('locations.read', 'Consultar ubicaciones', 'Ver ubicaciones técnicas del socio.'),
                    $this->permission('locations.create', 'Crear ubicaciones', 'Registrar nuevas ubicaciones.', ['locations.read']),
                    $this->permission('teams.read', 'Consultar equipos', 'Ver equipos y sus integrantes.'),
                    $this->permission('teams.create', 'Crear equipos', 'Crear equipos de trabajo.', ['teams.read']),
                    $this->permission('teams.update', 'Editar equipos', 'Modificar equipos e integrantes.', ['teams.read']),
                    $this->permission('goals.manage', 'Administrar metas', 'Definir y actualizar objetivos de trabajo.', ['teams.read']),
                ],
            ],
            [
                'code' => 'organization',
                'label' => 'Organización del socio',
                'description' => 'Información organizacional, credenciales, solicitudes de apoyo y movimientos.',
                'permissions' => [
                    $this->permission('organization.read', 'Consultar organización', 'Ver rango, patrocinador, línea upline y datos organizacionales.'),
                    $this->permission('organization.support.create', 'Solicitar apoyo', 'Crear solicitudes de apoyo a la organización.', ['organization.read']),
                    $this->permission('organization.support.manage', 'Administrar solicitudes', 'Escalar y resolver solicitudes de apoyo.', ['organization.read']),
                    $this->permission('organization.whatsapp.manage', 'Administrar WhatsApp Business', 'Conectar y mantener el canal de WhatsApp Business propio del socio.', ['organization.read']),
                    $this->permission('organization.kiosk.manage', 'Administrar Kioscos', 'Editar la configuración compartida por todos los Kioscos del socio.', ['organization.read']),
                    $this->permission('credentials.read', 'Consultar credencial', 'Ver la credencial digital del socio.', ['organization.read']),
                    $this->permission('movements.read', 'Consultar movimientos', 'Ver el historial de movimientos y folios RH-MOV.'),
                ],
            ],
            [
                'code' => 'devices',
                'label' => 'Aplicación móvil y dispositivos',
                'description' => 'Contexto móvil, mediciones y básculas vinculadas.',
                'permissions' => [
                    $this->permission('mobile.context', 'Usar aplicación móvil', 'Cargar el contexto operativo de la aplicación.'),
                    $this->permission('mobile.measurements.create', 'Registrar mediciones móviles', 'Capturar mediciones desde la aplicación.', ['mobile.context']),
                    $this->permission('devices.read', 'Consultar dispositivos', 'Ver básculas y dispositivos asociados.'),
                    $this->permission('devices.create', 'Registrar dispositivos', 'Vincular nuevas básculas.', ['devices.read']),
                    $this->permission('devices.suspend', 'Administrar dispositivos', 'Suspender, reactivar o rotar secretos de básculas.', ['devices.read']),
                ],
            ],
            [
                'code' => 'campaigns',
                'label' => 'Eventos y campañas',
                'description' => 'Jornadas de pesaje, Home Parties y reportes de campañas.',
                'permissions' => [
                    $this->permission('campaigns.read', 'Consultar eventos', 'Ver eventos y participantes.'),
                    $this->permission('campaigns.create', 'Crear eventos', 'Registrar nuevas campañas o jornadas.', ['campaigns.read']),
                    $this->permission('campaigns.update', 'Editar eventos', 'Modificar o cancelar campañas.', ['campaigns.read']),
                    $this->permission('campaigns.activate', 'Activar y cerrar eventos', 'Cambiar el estado operativo de una campaña.', ['campaigns.read']),
                    $this->permission('campaigns.participants.create', 'Registrar participantes', 'Agregar personas a los eventos.', ['campaigns.read']),
                    $this->permission('campaigns.report', 'Consultar reportes de eventos', 'Ver métricas y generar reportes de campaña.', ['campaigns.read']),
                    $this->permission('campaigns.invite', 'Invitar colaboradores', 'Invitar a otro usuario de Rhino CRM y administrar su acceso temporal.', ['campaigns.read']),
                    $this->permission('campaigns.collaborate', 'Trabajar en eventos compartidos', 'Abrir prospectos del evento, registrar pesajes, perfiles y dietas.', ['campaigns.read']),
                    $this->permission('campaigns.sales', 'Registrar ventas del evento', 'Registrar productos vendidos y atribuirlos al vendedor.', ['campaigns.read', 'sales.read', 'sales.create']),
                    $this->permission('campaigns.close', 'Cerrar eventos', 'Confirmar productos vendidos, guardar notas y asignar contactos según la dieta.', ['campaigns.read']),
                ],
            ],
            [
                'code' => 'nutrition',
                'label' => 'Nutrición',
                'description' => 'Perfiles, planes alimenticios, comidas, plantillas e inteligencia artificial.',
                'permissions' => [
                    $this->permission('nutrition.read', 'Consultar nutrición', 'Acceder al módulo de nutrición y a los planes.'),
                    $this->permission('nutrition.profiles.update', 'Editar perfiles nutricionales', 'Actualizar datos y objetivos nutricionales.', ['nutrition.read']),
                    $this->permission('nutrition.plans.generate', 'Generar planes alimenticios', 'Crear planes personalizados.', ['nutrition.read']),
                    $this->permission('nutrition.plans.update', 'Editar planes alimenticios', 'Modificar planes ya generados.', ['nutrition.read']),
                    $this->permission('nutrition.pills.read', 'Consultar comidas', 'Ver la biblioteca de comidas y recetas.', ['nutrition.read']),
                    $this->permission('nutrition.pills.manage', 'Administrar comidas', 'Agregar, editar, duplicar, archivar o eliminar comidas.', ['nutrition.read', 'nutrition.pills.read']),
                    $this->permission('nutrition.templates.manage', 'Administrar plantillas de dietas', 'Crear, editar, duplicar, activar, archivar o eliminar plantillas.', ['nutrition.read']),
                    $this->permission('nutrition.ai.manage', 'Configurar nutrición con IA', 'Administrar funciones de inteligencia artificial del motor nutricional.', ['nutrition.read']),
                ],
            ],
            [
                'code' => 'nutrition_followup',
                'label' => 'Seguimiento nutricional',
                'description' => 'Campañas de seguimiento, invitaciones, etapas y resultados.',
                'permissions' => [
                    $this->permission('nutrition_campaigns.read', 'Consultar seguimientos', 'Ver campañas y participantes del seguimiento nutricional.'),
                    $this->permission('nutrition_campaigns.create', 'Crear seguimientos', 'Crear campañas de seguimiento.', ['nutrition_campaigns.read']),
                    $this->permission('nutrition_campaigns.update', 'Editar seguimientos', 'Modificar campañas de seguimiento.', ['nutrition_campaigns.read']),
                    $this->permission('nutrition_campaigns.invite', 'Enviar invitaciones', 'Enviar invitaciones y mensajes de seguimiento.', ['nutrition_campaigns.read']),
                    $this->permission('nutrition_campaigns.stage', 'Cambiar etapas', 'Actualizar la etapa de cada participante.', ['nutrition_campaigns.read']),
                    $this->permission('nutrition_campaigns.report', 'Consultar reportes de seguimiento', 'Ver resultados y métricas del seguimiento.', ['nutrition_campaigns.read']),
                ],
            ],
            [
                'code' => 'crm',
                'label' => 'Prospectos, clientes y agenda',
                'description' => 'Contactos, pipeline, asignaciones y tareas.',
                'permissions' => [
                    $this->permission('contacts.read', 'Consultar contactos', 'Ver prospectos y clientes.'),
                    $this->permission('contacts.create', 'Crear contactos', 'Registrar nuevos prospectos o clientes.', ['contacts.read']),
                    $this->permission('contacts.update', 'Editar contactos', 'Actualizar información, notas y estado de contactos.', ['contacts.read']),
                    $this->permission('contacts.update_stage', 'Mover contactos en el pipeline', 'Cambiar la etapa comercial de un contacto.', ['contacts.read']),
                    $this->permission('contacts.assign', 'Asignar contactos', 'Asignar contactos a responsables o equipos.', ['contacts.read']),
                    $this->permission('tasks.read', 'Consultar agenda', 'Ver tareas y seguimientos.'),
                    $this->permission('tasks.create', 'Crear tareas', 'Agregar tareas y recordatorios.', ['tasks.read']),
                    $this->permission('tasks.update', 'Actualizar tareas', 'Completar o reprogramar tareas.', ['tasks.read']),
                ],
            ],
            [
                'code' => 'commerce',
                'label' => 'Productos, inventario y ventas',
                'description' => 'Catálogo, existencias, ajustes, ventas y devoluciones.',
                'permissions' => [
                    $this->permission('products.read', 'Consultar productos', 'Ver el catálogo de productos.'),
                    $this->permission('products.create', 'Crear productos', 'Registrar productos en el catálogo.', ['products.read']),
                    $this->permission('inventory.read', 'Consultar inventario', 'Ver existencias y movimientos de inventario.'),
                    $this->permission('inventory.adjust', 'Ajustar inventario', 'Registrar entradas, salidas y correcciones.', ['inventory.read']),
                    $this->permission('sales.read', 'Consultar ventas', 'Ver ventas y sus detalles.'),
                    $this->permission('sales.create', 'Registrar ventas', 'Crear nuevas ventas.', ['sales.read']),
                    $this->permission('sales.refund', 'Cancelar o devolver ventas', 'Procesar cancelaciones y devoluciones.', ['sales.read']),
                ],
            ],
            [
                'code' => 'automation_reports',
                'label' => 'Automatización y reportes',
                'description' => 'Flujos de trabajo, analítica y documentos exportables.',
                'permissions' => [
                    $this->permission('workflows.read', 'Consultar automatizaciones', 'Ver flujos de trabajo.'),
                    $this->permission('workflows.create', 'Crear automatizaciones', 'Configurar nuevos flujos.', ['workflows.read']),
                    $this->permission('analytics.read', 'Consultar analítica', 'Ver métricas y tableros analíticos.'),
                    $this->permission('reports.read', 'Consultar reportes', 'Ver y descargar reportes.'),
                    $this->permission('reports.create', 'Generar reportes', 'Crear reportes exportables.', ['reports.read']),
                ],
            ],
        ];
    }

    /** @return string[] */
    public function codes(): array
    {
        $codes = [];
        foreach ($this->catalog() as $group) {
            foreach ($group['permissions'] as $permission) {
                $codes[] = $permission['code'];
            }
        }

        return $codes;
    }

    /** @param string[] $permissions @return string[] */
    public function normalize(array $permissions): array
    {
        $known = array_fill_keys($this->codes(), true);
        $selected = [];
        foreach ($permissions as $permission) {
            $code = trim((string) $permission);
            if ($code !== '' && isset($known[$code])) {
                $selected[$code] = true;
            }
        }

        $dependencies = $this->dependencies();
        do {
            $changed = false;
            foreach (array_keys($selected) as $code) {
                foreach ($dependencies[$code] ?? [] as $dependency) {
                    if (!isset($selected[$dependency])) {
                        $selected[$dependency] = true;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return array_values(array_filter(
            $this->codes(),
            static fn (string $code): bool => isset($selected[$code]),
        ));
    }

    /** @param string[] $permissions @return string[] */
    public function apply(?string $partnerId, array $permissions): array
    {
        $base = array_values(array_unique(array_filter(array_map('strval', $permissions))));
        if ($partnerId === null || $partnerId === '' || !$this->tableAvailable()) {
            return $base;
        }

        $row = DB::table('partner_permission_policies')->where('partner_id', $partnerId)->first();
        if ($row === null) {
            return $base;
        }

        $allowed = array_fill_keys($this->normalize(SqlValue::json($row->permissions)), true);

        return array_values(array_filter(
            $base,
            static fn (string $permission): bool => isset($allowed[$permission]),
        ));
    }

    /** @return array{custom:bool,permissions:string[],updated_at:mixed,updated_by:mixed,change_reason:mixed} */
    public function policy(string $partnerId): array
    {
        if (!$this->tableAvailable()) {
            return [
                'custom' => false,
                'permissions' => $this->codes(),
                'updated_at' => null,
                'updated_by' => null,
                'change_reason' => null,
            ];
        }

        $row = DB::table('partner_permission_policies')->where('partner_id', $partnerId)->first();
        if ($row === null) {
            return [
                'custom' => false,
                'permissions' => $this->codes(),
                'updated_at' => null,
                'updated_by' => null,
                'change_reason' => null,
            ];
        }

        return [
            'custom' => true,
            'permissions' => $this->normalize(SqlValue::json($row->permissions)),
            'updated_at' => $row->updated_at,
            'updated_by' => $row->updated_by,
            'change_reason' => $row->change_reason,
        ];
    }

    private function tableAvailable(): bool
    {
        return Schema::hasTable('partner_permission_policies');
    }

    /** @return array<string, string[]> */
    private function dependencies(): array
    {
        $dependencies = [];
        foreach ($this->catalog() as $group) {
            foreach ($group['permissions'] as $permission) {
                $dependencies[$permission['code']] = $permission['depends_on'];
            }
        }

        return $dependencies;
    }

    /** @param string[] $dependsOn @return array{code:string,label:string,description:string,depends_on:string[]} */
    private function permission(string $code, string $label, string $description, array $dependsOn = []): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'description' => $description,
            'depends_on' => $dependsOn,
        ];
    }
}
