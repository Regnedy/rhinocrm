<?php
namespace App\Modules\CRM\Listeners;
use App\Modules\Auth\Services\TenantContextRunner;
use App\Modules\CRM\Events\ContactMeasurementProcessedEvent;
use App\Modules\CRM\Services\MeasurementTimelineService;
use Illuminate\Contracts\Queue\ShouldQueue;
final class RecordMeasurementTimelineListener implements ShouldQueue
{public int $tries=3;public function __construct(private TenantContextRunner $runner){}public function handle(ContactMeasurementProcessedEvent $e):void{$this->runner->runAsSystem($e->partnerId,fn()=>app(MeasurementTimelineService::class)->record($e));}}
