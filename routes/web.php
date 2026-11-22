<?php

use App\Modules\Campaigns\Controllers\WhatsappCapturePageController;
use App\Modules\DeveloperConsole\Controllers\DeveloperConsolePageController;
use App\Modules\Web\Controllers\CrmPageController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'service' => 'Rhino CRM API',
    'status' => is_file(storage_path('app/installed.lock')) ? 'ok' : 'installation_required',
    'api' => '/api/v1',
    'installer' => is_file(storage_path('app/installed.lock')) ? null : '/install.php',
]));

Route::get('/', [CrmPageController::class, 'show'])->name('crm.home');
Route::get('/crm', [CrmPageController::class, 'show'])->name('crm');
Route::get('/admin/developer-console', [DeveloperConsolePageController::class, 'show'])->name('developer-console');

Route::get('/connect/{token}', [WhatsappCapturePageController::class, 'show'])->middleware('throttle:60,1')->name('whatsapp-connect.show');
Route::get('/connect/{token}/vcard', [WhatsappCapturePageController::class, 'vcard'])->middleware('throttle:20,1')->name('whatsapp-connect.vcard');
Route::get('/connect/{token}/whatsapp', [WhatsappCapturePageController::class, 'whatsapp'])->middleware('throttle:20,1')->name('whatsapp-connect.whatsapp');
