<?php

namespace App\Console\Commands;

use App\Modules\PersonalReports\Services\ContactPublicCodeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BackfillContactCodesCommand extends Command
{
    protected $signature = 'rhino:contacts-backfill-codes {--partner= : Limitar a un socio} {--limit=0 : Máximo de contactos}';
    protected $description = 'Genera códigos públicos RHINO para contactos existentes sin modificar sus datos comerciales.';

    public function handle(ContactPublicCodeService $codes): int
    {
        $query = DB::table('contacts')->whereNull('public_code')->whereNull('deleted_at')->orderBy('id');
        $partner = trim((string) $this->option('partner'));
        if ($partner !== '') {
            $query->where('partner_id', $partner);
        }
        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $query->limit($limit);
        }

        $count = 0;
        foreach ($query->pluck('id') as $contactId) {
            $codes->ensure((string) $contactId);
            $count++;
            if ($count % 100 === 0) {
                $this->line("Códigos generados: {$count}");
            }
        }
        $this->info("Proceso completado. Códigos generados: {$count}");
        return self::SUCCESS;
    }
}
