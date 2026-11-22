<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('partner_kiosk_settings')) {
            return;
        }

        Schema::create('partner_kiosk_settings', function (Blueprint $table): void {
            $table->string('partner_id', 64)->primary();
            $table->text('whatsapp_waiting_template');
            $table->string('whatsapp_waiting_deadline', 60)->default('24 horas');
            $table->unsignedBigInteger('whatsapp_waiting_template_version')->default(1);
            $table->string('updated_by_user_id', 128)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Producción RHINO: migración aditiva. No destruir configuración del socio en rollback accidental.
    }
};
