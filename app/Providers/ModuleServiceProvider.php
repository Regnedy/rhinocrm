<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $bindings = [
            \App\Modules\Auth\Contracts\UserRepositoryInterface::class => \App\Modules\Auth\Repositories\SqlUserRepository::class,
            \App\Modules\Partners\Contracts\PartnerRepositoryInterface::class => \App\Modules\Partners\Repositories\SqlPartnerRepository::class,
            \App\Modules\Partners\Contracts\LocationRepositoryInterface::class => \App\Modules\Partners\Repositories\SqlLocationRepository::class,
            \App\Modules\Partners\Contracts\PlanRepositoryInterface::class => \App\Modules\Partners\Repositories\SqlPlanRepository::class,
            \App\Modules\IoT\Contracts\DeviceRepositoryInterface::class => \App\Modules\IoT\Repositories\SqlDeviceRepository::class,
            \App\Modules\IoT\Contracts\MeasurementRepositoryInterface::class => \App\Modules\IoT\Repositories\SqlMeasurementRepository::class,
            \App\Modules\CRM\Contracts\ContactRepositoryInterface::class => \App\Modules\CRM\Repositories\SqlContactRepository::class,
            \App\Modules\CRM\Contracts\TaskRepositoryInterface::class => \App\Modules\CRM\Repositories\SqlTaskRepository::class,
            \App\Modules\CRM\Contracts\TimelineActivityRepositoryInterface::class => \App\Modules\CRM\Repositories\SqlTimelineActivityRepository::class,
            \App\Modules\Workflows\Contracts\WorkflowRepositoryInterface::class => \App\Modules\Workflows\Repositories\SqlWorkflowRepository::class,
            \App\Modules\Workflows\Contracts\WorkflowExecutionRepositoryInterface::class => \App\Modules\Workflows\Repositories\SqlWorkflowExecutionRepository::class,
            \App\Modules\Workflows\Contracts\WorkflowLogRepositoryInterface::class => \App\Modules\Workflows\Repositories\SqlWorkflowLogRepository::class,
            \App\Modules\Integrations\Whatsapp\Contracts\WhatsappChannelResolverInterface::class => \App\Modules\Integrations\Whatsapp\Services\PartnerWhatsappChannelResolver::class,
            \App\Modules\Integrations\Whatsapp\Contracts\WhatsappProviderInterface::class => \App\Modules\Integrations\Whatsapp\Providers\MetaWhatsappProvider::class,
            \App\Modules\Integrations\Whatsapp\Contracts\WhatsappMessageRepositoryInterface::class => \App\Modules\Integrations\Whatsapp\Repositories\SqlWhatsappMessageRepository::class,
            \App\Modules\Sales\Contracts\ProductRepositoryInterface::class => \App\Modules\Sales\Repositories\SqlProductRepository::class,
            \App\Modules\Sales\Contracts\SaleRepositoryInterface::class => \App\Modules\Sales\Repositories\SqlSaleRepository::class,
            \App\Modules\Finance\Contracts\LedgerRepositoryInterface::class => \App\Modules\Finance\Repositories\SqlLedgerRepository::class,
            \App\Modules\Inventory\Contracts\InventoryTransactionRepositoryInterface::class => \App\Modules\Inventory\Repositories\SqlInventoryTransactionRepository::class,
        ];

        foreach ($bindings as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }
    }
}
