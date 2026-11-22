<?php

namespace App\Modules\Auth\Services;

final class RolePermissionService
{
    public function __construct(private readonly PartnerPermissionPolicyService $partnerPolicies) {}

    /** @return string[] */
    public function defaults(string $role): array
    {
        return match ($role) {
            'SUPER_ADMIN' => ['*'],
            'PARTNER_ADMIN' => [
                'users.create', 'users.read', 'users.suspend', 'sessions.read', 'sessions.revoke',
                'organization.read', 'organization.support.create', 'organization.support.manage', 'organization.whatsapp.manage', 'organization.kiosk.manage', 'credentials.read', 'movements.read',
                'mobile.context', 'mobile.measurements.create',
                'locations.read', 'locations.create',
                'teams.create', 'teams.read', 'teams.update', 'goals.manage',
                'campaigns.read', 'campaigns.create', 'campaigns.update', 'campaigns.activate', 'campaigns.participants.create', 'campaigns.report', 'campaigns.invite', 'campaigns.close', 'campaigns.collaborate', 'campaigns.sales',
                'nutrition_campaigns.read', 'nutrition_campaigns.create', 'nutrition_campaigns.update', 'nutrition_campaigns.invite', 'nutrition_campaigns.stage', 'nutrition_campaigns.report',
                'nutrition.read', 'nutrition.profiles.update', 'nutrition.plans.generate', 'nutrition.plans.update',
                'nutrition.pills.read', 'nutrition.pills.manage', 'nutrition.templates.manage', 'nutrition.ai.manage',
                'devices.create', 'devices.read', 'devices.suspend',
                'contacts.read', 'contacts.create', 'contacts.update', 'contacts.update_stage', 'contacts.assign',
                'tasks.read', 'tasks.create', 'tasks.update',
                'products.read', 'products.create', 'inventory.read', 'inventory.adjust',
                'workflows.read', 'workflows.create', 'sales.read', 'sales.create', 'sales.refund',
                'analytics.read', 'reports.create', 'reports.read',
            ],
            'LOCATION_MANAGER', 'TEAM_LEADER' => [
                'organization.read', 'organization.support.create', 'credentials.read',
                'mobile.context', 'mobile.measurements.create',
                'users.read', 'locations.read', 'teams.read', 'teams.update', 'goals.manage', 'campaigns.read', 'campaigns.create', 'campaigns.update', 'campaigns.activate', 'campaigns.participants.create', 'campaigns.report', 'campaigns.invite', 'campaigns.close', 'campaigns.collaborate', 'campaigns.sales',
                'nutrition_campaigns.read', 'nutrition_campaigns.create', 'nutrition_campaigns.update', 'nutrition_campaigns.invite', 'nutrition_campaigns.stage', 'nutrition_campaigns.report',
                'nutrition.read', 'nutrition.profiles.update', 'nutrition.plans.generate', 'nutrition.plans.update', 'nutrition.pills.read',
                'devices.read', 'contacts.read', 'contacts.create',
                'contacts.update', 'contacts.update_stage', 'contacts.assign', 'tasks.read', 'tasks.create',
                'tasks.update', 'products.read', 'inventory.read', 'sales.read', 'sales.create',
                'analytics.read',
            ],
            'ADVISOR' => [
                'organization.read', 'organization.support.create', 'credentials.read',
                'mobile.context', 'mobile.measurements.create',
                'locations.read', 'campaigns.read', 'campaigns.participants.create', 'campaigns.collaborate', 'campaigns.sales',
                'nutrition_campaigns.read', 'nutrition_campaigns.stage',
                'nutrition.read', 'nutrition.profiles.update', 'nutrition.plans.generate', 'nutrition.plans.update', 'nutrition.pills.read',
                'contacts.read', 'contacts.create', 'contacts.update', 'contacts.update_stage',
                'tasks.read', 'tasks.create', 'tasks.update', 'products.read', 'sales.read', 'sales.create',
                'analytics.read',
            ],
            'OPERATOR' => ['organization.read', 'organization.support.create', 'credentials.read', 'mobile.context', 'mobile.measurements.create', 'locations.read', 'campaigns.read', 'campaigns.participants.create', 'campaigns.collaborate', 'contacts.read', 'contacts.create', 'tasks.read', 'products.read', 'inventory.read'],
            default => [],
        };
    }

    /** @param string[] $explicit @return string[] */
    public function resolve(string $role, array $explicit, ?string $partnerId = null): array
    {
        if ($role === 'SUPER_ADMIN') {
            return ['*'];
        }

        $values = $explicit !== [] ? $explicit : $this->defaults($role);
        $resolved = array_values(array_unique(array_filter(array_map('strval', $values))));

        return $this->partnerPolicies->apply($partnerId, $resolved);
    }
}
