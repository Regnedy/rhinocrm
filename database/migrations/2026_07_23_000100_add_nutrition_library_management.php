<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nutrition_template_versions')) {
            Schema::create('nutrition_template_versions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('template_id', 100)->index();
                $table->unsignedInteger('version_no');
                $table->json('snapshot');
                $table->string('created_by_user_id', 128)->nullable()->index();
                $table->string('change_note', 1000)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['template_id', 'version_no'], 'nutrition_template_versions_unique');
            });
        }

        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'permissions')) return;

        DB::table('users')
            ->where('role', 'PARTNER_ADMIN')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $stored = json_decode((string) ($user->permissions ?? '[]'), true);
                    $permissions = is_array($stored) ? array_values(array_filter(array_map('strval', $stored))) : [];
                    if ($permissions === []) continue;
                    if (!in_array('nutrition.templates.manage', $permissions, true)) {
                        $permissions[] = 'nutrition.templates.manage';
                        DB::table('users')->where('id', $user->id)->update([
                            'permissions' => json_encode(array_values(array_unique($permissions)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }, 'id');
    }

    public function down(): void
    {
        // Política Rhino: no se eliminan tablas ni datos automáticamente.
    }
};
