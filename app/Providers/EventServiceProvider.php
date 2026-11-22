<?php
namespace App\Providers;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
final class EventServiceProvider extends ServiceProvider
{protected $listen=[
 \App\Modules\IoT\Events\MeasurementReceivedEvent::class=>[\App\Modules\CRM\Listeners\ProcessMeasurementReceived::class],
 \App\Modules\CRM\Events\ContactMeasurementProcessedEvent::class=>[\App\Modules\CRM\Listeners\AssignAdvisorListener::class,\App\Modules\CRM\Listeners\RecordMeasurementTimelineListener::class,\App\Modules\Workflows\Listeners\TriggerMeasurementWorkflows::class],
 \App\Modules\CRM\Events\AdvisorAssignedEvent::class=>[\App\Modules\CRM\Listeners\CreateInitialFollowUpTaskListener::class,\App\Modules\Workflows\Listeners\TriggerAdvisorAssignedWorkflows::class],
 \App\Modules\CRM\Events\ProspectStageChangedEvent::class=>[\App\Modules\Workflows\Listeners\CancelWorkflowsOnStageChange::class,\App\Modules\Analytics\Listeners\UpdateFunnelAnalytics::class,\App\Modules\Workflows\Listeners\TriggerStageChangeWorkflows::class],
 \App\Modules\Sales\Events\SaleCompletedEvent::class=>[\App\Modules\CRM\Listeners\PromoteContactToCustomer::class,\App\Modules\Analytics\Listeners\UpdateSalesAnalytics::class],
 \App\Modules\Sales\Events\SaleRefundedEvent::class=>[\App\Modules\Analytics\Listeners\UpdateRefundAnalytics::class],
 \App\Modules\Integrations\Whatsapp\Events\WhatsappMessageStatusChangedEvent::class=>[\App\Modules\CRM\Listeners\RecordWhatsappActivity::class],
 ];}
