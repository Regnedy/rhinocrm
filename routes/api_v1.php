<?php

use App\Modules\Administration\Controllers\AdminSecurityController;
use App\Modules\Administration\Controllers\AdminDeviceController;
use App\Modules\Analytics\Controllers\DashboardController;
use App\Modules\Analytics\Controllers\DashboardGadgetController;
use App\Modules\Analytics\Controllers\ReportController;
use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\SessionController;
use App\Modules\Auth\Controllers\UserController;
use App\Modules\Campaigns\Controllers\CampaignController;
use App\Modules\Campaigns\Controllers\CampaignCollaborationController;
use App\Modules\CRM\Controllers\ContactController;
use App\Modules\CRM\Controllers\PipelineController;
use App\Modules\CRM\Controllers\TaskController;
use App\Modules\CRM\Controllers\TimelineController;
use App\Modules\DeveloperConsole\Controllers\DeveloperConsoleController;
use App\Modules\Devices\Controllers\PersonalDeviceController;
use App\Modules\Integrations\Whatsapp\Controllers\PartnerWhatsappChannelController;
use App\Modules\Mobile\Controllers\PartnerKioskSettingsController;
use App\Modules\Integrations\Whatsapp\Controllers\WhatsappWebhookController;
use App\Modules\Inventory\Controllers\InventoryController;
use App\Modules\IoT\Controllers\DeviceController;
use App\Modules\IoT\Controllers\MeasurementController;
use App\Modules\Mobile\Controllers\MobileController;
use App\Modules\Mobile\Controllers\MobileAppController;
use App\Modules\Mobile\Controllers\KioskUpdateController;
use App\Modules\Billing\Controllers\BillingAdminController;
use App\Modules\Movements\Controllers\MovementController;
use App\Modules\NutritionCampaigns\Controllers\NutritionCampaignController;
use App\Modules\NutritionEngine\Controllers\NutritionEngineController;
use App\Modules\Partners\Controllers\AdminOrganizationController;
use App\Modules\Partners\Controllers\LocationController;
use App\Modules\Partners\Controllers\OrganizationController;
use App\Modules\Partners\Controllers\PartnerController;
use App\Modules\Partners\Controllers\PlanController;
use App\Modules\Partners\Controllers\WorkModeController;
use App\Modules\PersonalReports\Controllers\PersonalReportController;
use App\Modules\Sales\Controllers\ProductController;
use App\Modules\Sales\Controllers\SaleController;
use App\Modules\Teams\Controllers\TeamController;
use App\Modules\Workflows\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [SessionController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/firebase/exchange', [SessionController::class, 'exchange'])->middleware('throttle:login');

    Route::post('/measurements', [MeasurementController::class, 'store'])->middleware(['throttle:iot', 'iot.signature']);
    Route::get('/webhooks/whatsapp', [WhatsappWebhookController::class, 'verify']);
    Route::post('/webhooks/whatsapp', [WhatsappWebhookController::class, 'handle']);
    Route::get('/public/credentials/{publicCode}', [OrganizationController::class, 'verifyCredential']);

    Route::middleware('tenant')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/mobile/context', [MobileController::class, 'context'])->middleware('permission:mobile.context');
        Route::get('/mobile/app/config', [MobileAppController::class, 'config'])->middleware('permission:mobile.context');
        Route::get('/mobile/app/access', [MobileAppController::class, 'access'])->middleware('permission:mobile.context');
        Route::get('/mobile/app/bootstrap', [MobileAppController::class, 'bootstrap'])->middleware('permission:mobile.context');
        Route::get('/mobile/app/dashboard', [MobileAppController::class, 'dashboard'])->middleware('permission:mobile.context');
        Route::get('/mobile/app/notifications', [MobileAppController::class, 'notifications'])->middleware('permission:mobile.context');
        Route::put('/mobile/app/device', [MobileAppController::class, 'registerDevice'])->middleware('permission:mobile.context');
        Route::get('/mobile/contacts/{id}/measurement-dashboard', [MobileController::class, 'contactMeasurementDashboard'])->middleware('permission:contacts.read');
        Route::post('/mobile/measurements', [MobileController::class, 'measurement'])->middleware('permission:mobile.measurements.create');
        Route::post('/mobile/measurements/{measurementId}/quick-nutrition', [NutritionEngineController::class, 'generateQuickPlan'])->middleware('permission:mobile.measurements.create');
        Route::get('/mobile/nutrition-plans/{id}/pdf', [NutritionEngineController::class, 'pdf'])->middleware('permission:mobile.measurements.create');
        Route::get('/mobile/nutrition-plans/{id}/images', [NutritionEngineController::class, 'images'])->middleware('permission:mobile.measurements.create');
        Route::get('/mobile/nutrition-plans/{id}/images/{page}', [NutritionEngineController::class, 'image'])->whereNumber('page')->middleware('permission:mobile.measurements.create');
        Route::post('/mobile/nutrition-plans/{id}/send-whatsapp', [NutritionEngineController::class, 'sendWhatsappMobile'])->middleware('permission:mobile.measurements.create');
        Route::post('/mobile/whatsapp/test-delivery', [NutritionEngineController::class, 'testWhatsappDelivery'])->middleware('permission:mobile.context');
        Route::post('/auth/logout', [SessionController::class, 'logout']);

        Route::get('/plans', [PlanController::class, 'index']);
        Route::post('/partners', [PartnerController::class, 'store'])->middleware('permission:partners.create');
        Route::get('/locations', [LocationController::class, 'index'])->middleware('permission:locations.read');
        Route::post('/locations', [LocationController::class, 'store'])->middleware('permission:locations.create');
        Route::get('/work-mode', [WorkModeController::class, 'show']);
        Route::put('/work-mode', [WorkModeController::class, 'update']);

        Route::get('/organization/ranks', [OrganizationController::class, 'ranks'])->middleware('permission:organization.read');
        Route::get('/organization/workspace', [OrganizationController::class, 'workspace'])->middleware('permission:organization.read');
        Route::get('/organization/support', [OrganizationController::class, 'support'])->middleware('permission:organization.read');
        Route::post('/organization/support', [OrganizationController::class, 'createSupport'])->middleware('permission:organization.support.create');
        Route::post('/organization/support/{id}/escalate', [OrganizationController::class, 'escalateSupport'])->middleware('permission:organization.support.manage');
        Route::post('/organization/support/{id}/resolve', [OrganizationController::class, 'resolveSupport'])->middleware('permission:organization.support.manage');
        Route::get('/organization/kiosk-settings', [PartnerKioskSettingsController::class, 'show'])->middleware('permission:mobile.context');
        Route::put('/organization/kiosk-settings', [PartnerKioskSettingsController::class, 'update'])->middleware('permission:organization.kiosk.manage');
        Route::post('/organization/kiosk-settings/reset', [PartnerKioskSettingsController::class, 'reset'])->middleware('permission:organization.kiosk.manage');
        Route::get('/organization/whatsapp-channel', [PartnerWhatsappChannelController::class, 'show'])->middleware('permission:organization.read');
        Route::get('/organization/whatsapp-channel/embedded-signup/config', [PartnerWhatsappChannelController::class, 'embeddedSignupConfig'])->middleware('permission:organization.whatsapp.manage');
        Route::post('/organization/whatsapp-channel/embedded-signup/complete', [PartnerWhatsappChannelController::class, 'completeEmbeddedSignup'])->middleware('permission:organization.whatsapp.manage');
        Route::put('/organization/whatsapp-channel', [PartnerWhatsappChannelController::class, 'update'])->middleware('permission:organization.whatsapp.manage');
        Route::post('/organization/whatsapp-channel/test', [PartnerWhatsappChannelController::class, 'test'])->middleware('permission:organization.whatsapp.manage');
        Route::post('/organization/whatsapp-channel/disable', [PartnerWhatsappChannelController::class, 'disable'])->middleware('permission:organization.whatsapp.manage');

        Route::get('/movements/types', [MovementController::class, 'types'])->middleware('permission:movements.read');
        Route::get('/movements', [MovementController::class, 'index'])->middleware('permission:movements.read');

        Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create');

        Route::get('/devices', [DeviceController::class, 'index'])->middleware('permission:devices.read');
        Route::post('/devices', [DeviceController::class, 'store'])->middleware('permission:devices.create');
        Route::get('/personal-devices', [PersonalDeviceController::class, 'index'])->middleware('permission:mobile.context');
        Route::post('/personal-devices', [PersonalDeviceController::class, 'store'])->middleware('permission:mobile.context');
        Route::delete('/personal-devices/{id}', [PersonalDeviceController::class, 'destroy'])->middleware('permission:mobile.context');

        Route::get('/kiosk/update/check', [KioskUpdateController::class, 'check'])->middleware('permission:mobile.context');
        Route::get('/kiosk/update/download/{id}', [KioskUpdateController::class, 'download'])->middleware('permission:mobile.context');
        Route::post('/kiosk/update/installations', [KioskUpdateController::class, 'installations'])->middleware('permission:mobile.context');

        Route::get('/contacts', [ContactController::class, 'index'])->middleware('permission:contacts.read');
        Route::post('/contacts', [ContactController::class, 'store'])->middleware('permission:contacts.create');
        Route::get('/contacts/{id}', [ContactController::class, 'show'])->middleware('permission:contacts.read');
        Route::patch('/contacts/{id}', [ContactController::class, 'update'])->middleware('permission:contacts.update');
        Route::get('/contacts/{id}/measurements', [ContactController::class, 'measurements'])->middleware('permission:contacts.read');
        Route::get('/contacts/{id}/measurement-dashboard', [ContactController::class, 'measurementDashboard'])->middleware('permission:contacts.read');
        Route::get('/contacts/{contactId}/timeline', [TimelineController::class, 'index'])->middleware('permission:contacts.read');
        Route::post('/contacts/{contactId}/timeline', [TimelineController::class, 'store'])->middleware('permission:contacts.update');

        Route::get('/pipeline/stages', [PipelineController::class, 'stages'])->middleware('permission:contacts.read');
        Route::get('/pipeline', [PipelineController::class, 'index'])->middleware('permission:contacts.read');
        Route::patch('/pipeline/{id}/stage', [PipelineController::class, 'changeStage'])->middleware('permission:contacts.update_stage');

        Route::get('/tasks', [TaskController::class, 'index'])->middleware('permission:tasks.read');
        Route::post('/tasks', [TaskController::class, 'store'])->middleware('permission:tasks.create');
        Route::post('/tasks/{id}/complete', [TaskController::class, 'complete'])->middleware('permission:tasks.update');
        Route::post('/tasks/{id}/reschedule', [TaskController::class, 'reschedule'])->middleware('permission:tasks.update');

        Route::get('/campaign-invitations', [CampaignCollaborationController::class, 'invitations'])->middleware('permission:campaigns.read');
        Route::post('/campaign-invitations/{id}/respond', [CampaignCollaborationController::class, 'respondInvitation'])->middleware('permission:campaigns.read');
        Route::post('/campaign-join/preview', [CampaignCollaborationController::class, 'previewJoinQr']);
        Route::post('/campaign-join/accept', [CampaignCollaborationController::class, 'acceptJoinQr']);
        Route::get('/campaigns', [CampaignController::class, 'index'])->middleware('permission:campaigns.read');
        Route::post('/campaigns', [CampaignController::class, 'store'])->middleware('permission:campaigns.create');
        Route::get('/campaigns/{id}', [CampaignController::class, 'show'])->middleware('permission:campaigns.read');
        Route::patch('/campaigns/{id}', [CampaignController::class, 'update'])->middleware('permission:campaigns.update');
        Route::post('/campaigns/{id}/activate', [CampaignController::class, 'activate'])->middleware('permission:campaigns.activate');
        Route::get('/campaigns/{id}/close-preview', [CampaignCollaborationController::class, 'closePreview'])->middleware('permission:campaigns.close');
        Route::post('/campaigns/{id}/close', [CampaignCollaborationController::class, 'close'])->middleware('permission:campaigns.close');
        Route::post('/campaigns/{id}/cancel', [CampaignController::class, 'cancel'])->middleware('permission:campaigns.update');
        Route::get('/campaigns/{id}/invitations', [CampaignCollaborationController::class, 'campaignInvitations'])->middleware('permission:campaigns.read');
        Route::post('/campaigns/{id}/invitations', [CampaignCollaborationController::class, 'invite'])->middleware('permission:campaigns.invite');
        Route::delete('/campaigns/{id}/invitations/{invitationId}', [CampaignCollaborationController::class, 'revokeInvitation'])->middleware('permission:campaigns.invite');
        Route::post('/campaigns/{id}/join-qr', [CampaignCollaborationController::class, 'createJoinQr'])->middleware('permission:campaigns.invite');
        Route::delete('/campaigns/{id}/join-qr', [CampaignCollaborationController::class, 'revokeJoinQr'])->middleware('permission:campaigns.invite');
        Route::get('/campaigns/{id}/participants', [CampaignController::class, 'participants'])->middleware('permission:campaigns.read');
        Route::get('/campaigns/{id}/pipeline', [CampaignController::class, 'pipeline'])->middleware('permission:campaigns.read');
        Route::post('/campaigns/{id}/participants', [CampaignController::class, 'registerParticipant'])->middleware('permission:campaigns.participants.create');
        Route::get('/campaigns/{id}/collaborators', [CampaignCollaborationController::class, 'collaborators'])->middleware('permission:campaigns.read');
        Route::get('/campaigns/{id}/available-devices', [PersonalDeviceController::class, 'campaign'])->middleware('permission:campaigns.read');
        Route::post('/campaigns/{id}/available-devices', [PersonalDeviceController::class, 'share'])->middleware('permission:campaigns.collaborate');
        Route::post('/campaigns/{id}/participants/{contactId}/contact-started', [CampaignCollaborationController::class, 'markContactStarted'])->middleware('permission:campaigns.collaborate');
        Route::post('/campaigns/{id}/participants/{contactId}/responded', [CampaignCollaborationController::class, 'markResponded'])->middleware('permission:campaigns.collaborate');
        Route::patch('/campaigns/{id}/participants/{contactId}/commercial-status', [CampaignCollaborationController::class, 'updateCommercialStatus'])->middleware('permission:campaigns.collaborate');
        Route::patch('/campaigns/{id}/participants/{contactId}/purchase-outcome', [CampaignCollaborationController::class, 'updatePurchaseOutcome'])->middleware('permission:campaigns.collaborate');
        Route::patch('/campaigns/{id}/participants/{contactId}/assignee', [CampaignCollaborationController::class, 'assignOwner'])->middleware('permission:campaigns.collaborate');
        Route::get('/campaigns/{id}/participants/{contactId}', [CampaignCollaborationController::class, 'participant'])->middleware('permission:campaigns.collaborate');
        Route::get('/campaigns/{id}/participants/{contactId}/history', [CampaignCollaborationController::class, 'history'])->middleware('permission:campaigns.collaborate');
        Route::get('/campaigns/{id}/participants/{contactId}/measurement-dashboard', [CampaignCollaborationController::class, 'participantMeasurementDashboard'])->middleware('permission:campaigns.collaborate');
        Route::get('/campaigns/{id}/participants/{contactId}/whatsapp-capture', [CampaignCollaborationController::class, 'whatsappCapture'])->middleware('permission:campaigns.collaborate');
        Route::post('/campaigns/{id}/participants/{contactId}/whatsapp-capture/confirm', [CampaignCollaborationController::class, 'confirmWhatsapp'])->middleware('permission:campaigns.collaborate');
        Route::put('/campaigns/{id}/participants/{contactId}/nutrition-profile', [CampaignCollaborationController::class, 'updateProfile'])->middleware('permission:campaigns.collaborate');
        Route::post('/campaigns/{id}/participants/{contactId}/diet', [CampaignCollaborationController::class, 'generateDiet'])->middleware('permission:campaigns.collaborate');
        Route::get('/campaigns/{id}/participants/{contactId}/plans/{planId}/pdf', [CampaignCollaborationController::class, 'planPdf'])->middleware('permission:campaigns.collaborate');
        Route::post('/campaigns/{id}/participants/{contactId}/plans/{planId}/send-whatsapp', [CampaignCollaborationController::class, 'sendPlanWhatsapp'])->middleware('permission:campaigns.collaborate');
        Route::get('/campaigns/{id}/products', [CampaignCollaborationController::class, 'products'])->middleware('permission:campaigns.collaborate');
        Route::post('/campaigns/{id}/participants/{contactId}/sales', [CampaignCollaborationController::class, 'sale'])->middleware('permission:campaigns.sales');
        Route::get('/campaigns/{id}/activity', [CampaignCollaborationController::class, 'activity'])->middleware('permission:campaigns.read');
        Route::get('/campaigns/{id}/report', [CampaignController::class, 'report'])->middleware('permission:campaigns.report');
        Route::get('/campaigns/{campaignId}/personal-reports', [PersonalReportController::class, 'summary'])->middleware('permission:campaigns.report');
        Route::post('/campaigns/{campaignId}/personal-reports/dispatch', [PersonalReportController::class, 'dispatch'])->middleware('permission:campaigns.report');


        Route::get('/nutrition-engine/summary', [NutritionEngineController::class, 'summary'])->middleware('permission:nutrition.read');
        Route::get('/nutrition-engine/templates', [NutritionEngineController::class, 'templates'])->middleware('permission:nutrition.read');
        Route::post('/nutrition-engine/templates', [NutritionEngineController::class, 'storeTemplate'])->middleware('permission:nutrition.templates.manage');
        Route::get('/nutrition-engine/templates/{id}', [NutritionEngineController::class, 'showTemplate'])->middleware('permission:nutrition.read');
        Route::patch('/nutrition-engine/templates/{id}', [NutritionEngineController::class, 'updateTemplate'])->middleware('permission:nutrition.templates.manage');
        Route::post('/nutrition-engine/templates/{id}/activate', [NutritionEngineController::class, 'activateTemplate'])->middleware('permission:nutrition.templates.manage');
        Route::post('/nutrition-engine/templates/{id}/archive', [NutritionEngineController::class, 'archiveTemplate'])->middleware('permission:nutrition.templates.manage');
        Route::post('/nutrition-engine/templates/{id}/duplicate', [NutritionEngineController::class, 'duplicateTemplate'])->middleware('permission:nutrition.templates.manage');
        Route::delete('/nutrition-engine/templates/{id}', [NutritionEngineController::class, 'deleteTemplate'])->middleware('permission:nutrition.templates.manage');
        Route::get('/nutrition-engine/contacts/{contactId}', [NutritionEngineController::class, 'workspace'])->middleware('permission:nutrition.read');
        Route::put('/nutrition-engine/contacts/{contactId}/profile', [NutritionEngineController::class, 'updateProfile'])->middleware('permission:nutrition.profiles.update');
        Route::post('/nutrition-engine/measurements/{measurementId}/quick-nutrition', [NutritionEngineController::class, 'generateQuickPlan'])->middleware('permission:nutrition.plans.generate');
        Route::post('/nutrition-engine/plans/generate', [NutritionEngineController::class, 'generatePlan'])->middleware('permission:nutrition.plans.generate');
        Route::get('/nutrition-engine/plans', [NutritionEngineController::class, 'plans'])->middleware('permission:nutrition.read');
        Route::get('/nutrition-engine/plans/{id}', [NutritionEngineController::class, 'showPlan'])->middleware('permission:nutrition.read');
        Route::get('/nutrition-engine/plans/{id}/pdf', [NutritionEngineController::class, 'pdf'])->middleware('permission:nutrition.read');
        Route::get('/nutrition-engine/plans/{id}/images', [NutritionEngineController::class, 'images'])->middleware('permission:nutrition.read');
        Route::get('/nutrition-engine/plans/{id}/images/{page}', [NutritionEngineController::class, 'image'])->whereNumber('page')->middleware('permission:nutrition.read');
        Route::get('/nutrition-engine/plans/{id}/share', [NutritionEngineController::class, 'shareInfo'])->middleware('permission:nutrition.read');
        Route::post('/nutrition-engine/plans/{id}/share', [NutritionEngineController::class, 'recordShare'])->middleware('permission:nutrition.plans.update');
        Route::post('/nutrition-engine/plans/{id}/send-whatsapp', [NutritionEngineController::class, 'sendWhatsapp'])->middleware('permission:nutrition.plans.update');
        Route::patch('/nutrition-engine/plans/{id}/delivery', [NutritionEngineController::class, 'markDelivery'])->middleware('permission:nutrition.plans.update');
        Route::post('/nutrition-engine/plans/{id}/feedback', [NutritionEngineController::class, 'feedback'])->middleware('permission:nutrition.plans.update');

        Route::get('/nutrition-engine/pills', [NutritionEngineController::class, 'pills'])->middleware('permission:nutrition.pills.read');
        Route::post('/nutrition-engine/pills', [NutritionEngineController::class, 'storePill'])->middleware('permission:nutrition.pills.manage');
        Route::get('/nutrition-engine/pills/{id}', [NutritionEngineController::class, 'showPill'])->middleware('permission:nutrition.pills.read');
        Route::patch('/nutrition-engine/pills/{id}', [NutritionEngineController::class, 'updatePill'])->middleware('permission:nutrition.pills.manage');
        Route::post('/nutrition-engine/pills/{id}/approve', [NutritionEngineController::class, 'approvePill'])->middleware('permission:nutrition.pills.manage');
        Route::post('/nutrition-engine/pills/{id}/archive', [NutritionEngineController::class, 'archivePill'])->middleware('permission:nutrition.pills.manage');
        Route::post('/nutrition-engine/pills/{id}/duplicate', [NutritionEngineController::class, 'duplicatePill'])->middleware('permission:nutrition.pills.manage');
        Route::delete('/nutrition-engine/pills/{id}', [NutritionEngineController::class, 'deletePill'])->middleware('permission:nutrition.pills.manage');

        Route::get('/nutrition-engine/ai/batches', [NutritionEngineController::class, 'aiBatches'])->middleware('permission:nutrition.ai.manage');
        Route::post('/nutrition-engine/ai/batches', [NutritionEngineController::class, 'createAiBatch'])->middleware('permission:nutrition.ai.manage');
        Route::get('/nutrition-engine/ai/batches/{id}', [NutritionEngineController::class, 'showAiBatch'])->middleware('permission:nutrition.ai.manage');
        Route::post('/nutrition-engine/ai/batches/{id}/import', [NutritionEngineController::class, 'importAiResponse'])->middleware('permission:nutrition.ai.manage');
        Route::post('/nutrition-engine/ai/batches/{id}/proposals/{proposalId}/approve', [NutritionEngineController::class, 'approveAiProposal'])->middleware('permission:nutrition.ai.manage');
        Route::post('/nutrition-engine/ai/batches/{id}/proposals/{proposalId}/reject', [NutritionEngineController::class, 'rejectAiProposal'])->middleware('permission:nutrition.ai.manage');

        Route::get('/nutrition-campaigns', [NutritionCampaignController::class, 'index'])->middleware('permission:nutrition_campaigns.read');
        Route::post('/nutrition-campaigns', [NutritionCampaignController::class, 'store'])->middleware('permission:nutrition_campaigns.create');
        Route::post('/nutrition-campaigns/candidates/search', [NutritionCampaignController::class, 'searchCandidates'])->middleware('permission:nutrition_campaigns.create');
        Route::get('/nutrition-campaigns/{id}', [NutritionCampaignController::class, 'show'])->middleware('permission:nutrition_campaigns.read');
        Route::patch('/nutrition-campaigns/{id}', [NutritionCampaignController::class, 'update'])->middleware('permission:nutrition_campaigns.update');
        Route::post('/nutrition-campaigns/{id}/members', [NutritionCampaignController::class, 'addMembers'])->middleware('permission:nutrition_campaigns.update');
        Route::delete('/nutrition-campaigns/{id}/members/{memberId}', [NutritionCampaignController::class, 'removeMember'])->middleware('permission:nutrition_campaigns.update');
        Route::post('/nutrition-campaigns/{id}/activate', [NutritionCampaignController::class, 'activate'])->middleware('permission:nutrition_campaigns.update');
        Route::post('/nutrition-campaigns/{id}/close', [NutritionCampaignController::class, 'close'])->middleware('permission:nutrition_campaigns.update');
        Route::post('/nutrition-campaigns/{id}/dispatch', [NutritionCampaignController::class, 'dispatchInvitations'])->middleware('permission:nutrition_campaigns.invite');
        Route::patch('/nutrition-campaigns/{id}/members/{memberId}/stage', [NutritionCampaignController::class, 'changeStage'])->middleware('permission:nutrition_campaigns.stage');
        Route::get('/nutrition-campaigns/{id}/report', [NutritionCampaignController::class, 'report'])->middleware('permission:nutrition_campaigns.report');

        Route::get('/teams', [TeamController::class, 'index'])->middleware('permission:teams.read');
        Route::post('/teams', [TeamController::class, 'store'])->middleware('permission:teams.create');
        Route::get('/teams/reports/summary', [TeamController::class, 'summary'])->middleware('permission:analytics.read');
        Route::get('/teams/{id}', [TeamController::class, 'show'])->middleware('permission:teams.read');
        Route::patch('/teams/{id}', [TeamController::class, 'update'])->middleware('permission:teams.update');
        Route::post('/teams/{id}/members', [TeamController::class, 'addMember'])->middleware('permission:teams.update');
        Route::delete('/teams/{id}/members/{userId}', [TeamController::class, 'removeMember'])->middleware('permission:teams.update');
        Route::put('/teams/{id}/goals', [TeamController::class, 'setGoal'])->middleware('permission:goals.manage');
        Route::get('/teams/{id}/report', [TeamController::class, 'report'])->middleware('permission:analytics.read');

        Route::get('/products', [ProductController::class, 'index'])->middleware('permission:products.read');
        Route::post('/products', [ProductController::class, 'store'])->middleware('permission:products.create');
        Route::get('/inventory', [InventoryController::class, 'index'])->middleware('permission:inventory.read');
        Route::get('/inventory/movements', [InventoryController::class, 'movements'])->middleware('permission:inventory.read');
        Route::post('/inventory/adjustments', [InventoryController::class, 'adjust'])->middleware('permission:inventory.adjust');

        Route::get('/sales', [SaleController::class, 'index'])->middleware('permission:sales.read');
        Route::get('/sales/{id}', [SaleController::class, 'show'])->middleware('permission:sales.read');
        Route::post('/sales', [SaleController::class, 'store'])->middleware('permission:sales.create');
        Route::post('/sales/{id}/refund', [SaleController::class, 'cancel'])->middleware('permission:sales.refund');

        Route::get('/workflows', [WorkflowController::class, 'index'])->middleware('permission:workflows.read');
        Route::post('/workflows', [WorkflowController::class, 'store'])->middleware('permission:workflows.create');

        Route::get('/dashboard/workspace', [DashboardGadgetController::class, 'workspace']);
        Route::put('/dashboard/layout', [DashboardGadgetController::class, 'saveLayout']);
        Route::get('/analytics/dashboard/summary', [DashboardController::class, 'getSummary'])->middleware('permission:analytics.read');
        Route::post('/reports', [ReportController::class, 'store'])->middleware('permission:reports.create');
        Route::get('/reports/{id}', [ReportController::class, 'show'])->middleware('permission:reports.read');
        Route::get('/reports/{id}/download', [ReportController::class, 'download'])->middleware('permission:reports.read');

        Route::prefix('security')->group(function (): void {
            Route::get('/users', [AdminSecurityController::class, 'users'])->middleware('permission:users.read');
            Route::post('/users/{id}/suspend', [AdminSecurityController::class, 'suspendUser'])->middleware('permission:users.suspend');
            Route::post('/users/{id}/activate', [AdminSecurityController::class, 'activateUser'])->middleware('permission:users.suspend');
            Route::post('/users/{id}/revoke-sessions', [AdminSecurityController::class, 'revokeUserSessions'])->middleware('permission:sessions.revoke');
            Route::get('/sessions', [AdminSecurityController::class, 'sessions'])->middleware('permission:sessions.read');
            Route::post('/sessions/{id}/revoke', [AdminSecurityController::class, 'revokeSession'])->middleware('permission:sessions.revoke');
            Route::get('/devices', [AdminSecurityController::class, 'devices'])->middleware('permission:devices.read');
            Route::get('/devices-overview', [AdminSecurityController::class, 'deviceOverview'])->middleware('permission:devices.read');
            Route::post('/devices/{id}/suspend', [AdminSecurityController::class, 'suspendDevice'])->middleware('permission:devices.suspend');
            Route::post('/devices/{id}/activate', [AdminSecurityController::class, 'activateDevice'])->middleware('permission:devices.suspend');
            Route::post('/devices/{id}/revoke', [AdminSecurityController::class, 'revokeDevice'])->middleware('permission:devices.suspend');
            Route::post('/devices/{id}/rotate-secret', [AdminSecurityController::class, 'rotateDeviceSecret'])->middleware('permission:devices.suspend');
            Route::post('/contacts/{id}/block', [AdminSecurityController::class, 'blockContact'])->middleware('permission:contacts.update');
            Route::post('/contacts/{id}/activate', [AdminSecurityController::class, 'activateContact'])->middleware('permission:contacts.update');
            Route::get('/audit', [AdminSecurityController::class, 'audit'])->middleware('permission:analytics.read');
        });

        Route::prefix('admin')->group(function (): void {
            Route::get('/partners', [AdminSecurityController::class, 'partners']);
            Route::get('/kiosk-versions', [KioskUpdateController::class, 'index']);
            Route::post('/devices/{id}/unassign', [AdminDeviceController::class, 'unassign']);
            Route::post('/kiosk-versions', [KioskUpdateController::class, 'publish']);
            Route::post('/kiosk-versions/{id}/revoke', [KioskUpdateController::class, 'revoke']);
            Route::get('/billing/overview', [BillingAdminController::class, 'overview']);
            Route::get('/billing/plans', [BillingAdminController::class, 'plans']);
            Route::get('/billing/subscriptions', [BillingAdminController::class, 'subscriptions']);
            Route::post('/billing/access-grants', [BillingAdminController::class, 'grant']);
            Route::delete('/billing/access-grants/{id}', [BillingAdminController::class, 'revoke']);
            Route::get('/permissions/catalog', [AdminSecurityController::class, 'partnerPermissionCatalog']);
            Route::get('/partners/{id}/permissions', [AdminSecurityController::class, 'partnerPermissions']);
            Route::put('/partners/{id}/permissions', [AdminSecurityController::class, 'updatePartnerPermissions']);
            Route::delete('/partners/{id}/permissions', [AdminSecurityController::class, 'resetPartnerPermissions']);
            Route::post('/partners/{id}/suspend', [AdminSecurityController::class, 'suspendPartner']);
            Route::post('/partners/{id}/activate', [AdminSecurityController::class, 'activatePartner']);
            Route::get('/partners/{id}/primary-access/email-status', [AdminSecurityController::class, 'partnerPrimaryEmailStatus']);
            Route::post('/partners/{id}/primary-access', [AdminSecurityController::class, 'createPartnerPrimaryAccess']);
            Route::post('/partners/{id}/primary-access/transfer-existing', [AdminSecurityController::class, 'transferPartnerPrimaryAccess']);
            Route::post('/partners/{id}/primary-access/resend-verification', [AdminSecurityController::class, 'resendPartnerVerification']);
            Route::get('/partners/{id}/primary-access/verification-link', [AdminSecurityController::class, 'partnerVerificationLink']);
            Route::post('/partners/{id}/primary-access/refresh-firebase', [AdminSecurityController::class, 'refreshPartnerFirebase']);
            Route::put('/partners/{id}/primary-access/password', [AdminSecurityController::class, 'changePartnerPrimaryPassword']);

            Route::patch('/partners/{id}/identity', [AdminOrganizationController::class, 'updateIdentity']);
            Route::get('/partners/{id}/contacts', [AdminOrganizationController::class, 'contacts']);
            Route::post('/contacts/reassign', [AdminOrganizationController::class, 'reassignContacts']);
            Route::post('/partners/{id}/credentials', [AdminOrganizationController::class, 'issueCredential']);
            Route::post('/credentials/{id}/revoke', [AdminOrganizationController::class, 'revokeCredential']);
            Route::post('/ranks', [AdminOrganizationController::class, 'storeRank']);
            Route::patch('/ranks/{id}', [AdminOrganizationController::class, 'updateRank']);

            Route::prefix('developer-console')->middleware(['developer.console', 'throttle:developer-console'])->group(function (): void {
                Route::get('/status', [DeveloperConsoleController::class, 'status']);
                Route::get('/log-files', [DeveloperConsoleController::class, 'logFiles']);
                Route::get('/logs', [DeveloperConsoleController::class, 'logs']);
                Route::get('/commands', [DeveloperConsoleController::class, 'commands']);
                Route::post('/commands/{action}', [DeveloperConsoleController::class, 'executeCommand']);
                Route::get('/source-files', [DeveloperConsoleController::class, 'sourceFiles']);
                Route::get('/source', [DeveloperConsoleController::class, 'source']);
                Route::get('/actions', [DeveloperConsoleController::class, 'actions']);
                Route::get('/ai/history', [DeveloperConsoleController::class, 'aiHistory']);
                Route::post('/ai/analyze', [DeveloperConsoleController::class, 'analyze'])->middleware('throttle:developer-ai');
            });
        });
    });
});
