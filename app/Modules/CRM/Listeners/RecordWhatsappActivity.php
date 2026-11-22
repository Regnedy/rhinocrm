<?php
namespace App\Modules\CRM\Listeners;
use App\Modules\Auth\Services\TenantContextRunner;
use App\Modules\CRM\Services\WhatsappTimelineService;
use App\Modules\Integrations\Whatsapp\Events\WhatsappMessageStatusChangedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
final class RecordWhatsappActivity implements ShouldQueue
{public int $tries=3;public function __construct(private TenantContextRunner $runner){}public function handle(WhatsappMessageStatusChangedEvent $e):void{$this->runner->runAsSystem($e->partnerId,fn()=>app(WhatsappTimelineService::class)->record($e));}}
