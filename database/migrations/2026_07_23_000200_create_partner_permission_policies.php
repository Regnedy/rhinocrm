<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('partner_permission_policies')) {
            Schema::create('partner_permission_policies', function (Blueprint $table): void {
                $table->string('partner_id', 64)->primary();
                $table->json('permissions');
                $table->string('updated_by', 128)->nullable()->index();
                $table->string('change_reason', 1000)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_permission_policies');
    }
};
