<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BroadcastController;
use App\Http\Controllers\Api\CalendarFeedController;
use App\Http\Controllers\Api\CallController;
use App\Http\Controllers\Api\CallNotesController;
use App\Http\Controllers\Api\ConnectedAccountController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DashboardBuilderController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\EmailTrackingController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\InboundWebhookController;
use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Api\LeadCaptureController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\LeadImportController;
use App\Http\Controllers\Api\LeadWorkspaceController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\PrivacyController;
use App\Http\Controllers\Api\PublicFormController;
use App\Http\Controllers\Api\PublicQuoteController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RestHookController;
use App\Http\Controllers\Api\SavedReportController;
use App\Http\Controllers\Api\SavedViewController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\Settings;
use App\Http\Controllers\Api\SsoController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public
    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/two-factor-challenge', [AuthController::class, 'twoFactorChallenge']);
        Route::post('auth/sso', [SsoController::class, 'start']);
        Route::post('auth/sso/exchange', [AuthController::class, 'ssoExchange']);
    });
    Route::get('sso/callback', [SsoController::class, 'callback'])->middleware('throttle:30,1');
    Route::get('connected-accounts/callback', [ConnectedAccountController::class, 'callback'])->middleware('throttle:30,1');

    // Hosted web-to-lead forms (public, by slug)
    Route::get('forms/{slug}', [PublicFormController::class, 'show']);
    Route::post('forms/{slug}', [PublicFormController::class, 'submit'])->middleware('throttle:capture');

    // Vendor callbacks (secret token in the URL identifies the organization)
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('public/quotes/{token}', [PublicQuoteController::class, 'show'])->where('token', '[A-Za-z0-9]{40}');
        Route::post('public/unsubscribe/{lead}/{signature}', [PrivacyController::class, 'unsubscribe'])->whereNumber('lead')->where('signature', '[a-f0-9]{32}');
        Route::get('public/book/{slug}', [BookingController::class, 'publicShow'])->where('slug', '[a-z0-9-]+');
        Route::post('public/book/{slug}', [BookingController::class, 'book'])->where('slug', '[a-z0-9-]+')->middleware('throttle:10,1');
        Route::get('public/calendar/{token}.ics', [CalendarFeedController::class, 'feed'])->where('token', '[A-Za-z0-9]{40}');
        Route::post('public/quotes/{token}/respond', [PublicQuoteController::class, 'respond'])->where('token', '[A-Za-z0-9]{40}');
    });

    // Campaign email tracking (open pixel, signed click redirects)
    Route::middleware('throttle:600,1')->where(['token' => '[A-Za-z0-9]{40}'])->group(function () {
        Route::get('t/o/{token}.gif', [EmailTrackingController::class, 'open']);
        Route::get('t/c/{token}/{signature}', [EmailTrackingController::class, 'click'])->where('signature', '[a-f0-9]{24}');
    });

    Route::middleware('throttle:600,1')->prefix('webhooks')->group(function () {
        Route::post('voice/{provider}/{token}', [InboundWebhookController::class, 'voice'])->whereIn('provider', ['vapi', 'retell', 'bland']);
        Route::post('messaging/twilio/{token}', [InboundWebhookController::class, 'twilio']);
    });

    // Web forms / external systems (organization API key)
    Route::post('capture/leads', LeadCaptureController::class)->middleware(['api.key', 'throttle:capture']);
    Route::middleware(['api.key', 'throttle:120,1'])->prefix('hooks')->group(function () {
        Route::get('me', [RestHookController::class, 'me']);
        Route::post('subscriptions', [RestHookController::class, 'subscribe']);
        Route::delete('subscriptions/{id}', [RestHookController::class, 'unsubscribe'])->whereNumber('id');
        Route::get('samples/{event}', [RestHookController::class, 'samples']);
        Route::get('leads', [RestHookController::class, 'leads']);
    });

    Route::middleware(['auth:sanctum', 'tenant', 'writable'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::patch('auth/profile', [AuthController::class, 'updateProfile']);
        Route::put('auth/password', [AuthController::class, 'updatePassword']);
        Route::get('auth/notification-preferences', [NotificationPreferenceController::class, 'show']);
        Route::put('auth/notification-preferences', [NotificationPreferenceController::class, 'update']);
        Route::post('notifications/test', [NotificationPreferenceController::class, 'test']);

        // AI calling
        Route::get('calls', [CallController::class, 'index']);
        Route::get('calls/stats', [CallController::class, 'stats']);
        Route::get('calls/{id}', [CallController::class, 'show'])->whereNumber('id');
        Route::post('calls/{id}/cancel', [CallController::class, 'cancel'])->whereNumber('id');
        Route::post('leads/{leadId}/calls', [CallController::class, 'callLead'])->whereNumber('leadId');
        Route::post('ai-agents/{agentId}/campaign', [CallController::class, 'campaign'])->whereNumber('agentId');
        Route::post('ai-agents/{agentId}/simulate-inbound', [CallController::class, 'simulateInbound'])->whereNumber('agentId')->middleware('throttle:20,1');
        Route::get('settings/ai-agents', [Settings\AiAgentController::class, 'index']);
        Route::get('settings/products', [Settings\ProductController::class, 'index']);

        Route::get('meta', MetaController::class);
        Route::get('search', SearchController::class);
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('reports/leads', [ReportController::class, 'leads']);
        Route::get('reports/catalog', [ReportController::class, 'catalog']);
        Route::post('reports/run', [ReportController::class, 'run'])->middleware('throttle:120,1');
        Route::get('reports/attribution', [ReportController::class, 'attribution']);
        Route::get('reports/type/{type}', [ReportController::class, 'type']);
        Route::post('reports/ask', [ReportController::class, 'ask'])->middleware('throttle:30,1');
        Route::get('dashboards', [DashboardBuilderController::class, 'index']);
        Route::post('dashboards', [DashboardBuilderController::class, 'store']);
        Route::get('dashboards/{id}', [DashboardBuilderController::class, 'show'])->whereNumber('id');
        Route::patch('dashboards/{id}', [DashboardBuilderController::class, 'update'])->whereNumber('id');
        Route::delete('dashboards/{id}', [DashboardBuilderController::class, 'destroy'])->whereNumber('id');
        Route::get('saved-reports', [SavedReportController::class, 'index']);
        Route::post('saved-reports', [SavedReportController::class, 'store']);
        Route::patch('saved-reports/{id}', [SavedReportController::class, 'update'])->whereNumber('id');
        Route::delete('saved-reports/{id}', [SavedReportController::class, 'destroy'])->whereNumber('id');
        Route::get('saved-reports/{id}/run', [SavedReportController::class, 'run'])->whereNumber('id');
        Route::post('saved-reports/{id}/send', [SavedReportController::class, 'send'])->whereNumber('id')->middleware('throttle:10,1');
        Route::get('goals', [GoalController::class, 'index']);
        Route::post('goals', [GoalController::class, 'store']);
        Route::patch('goals/{id}', [GoalController::class, 'update'])->whereNumber('id');
        Route::delete('goals/{id}', [GoalController::class, 'destroy'])->whereNumber('id');
        Route::get('activities/feed', [ActivityController::class, 'feed']);

        // Leads
        Route::get('leads/board', [LeadController::class, 'board']);
        Route::get('leads/export', [LeadController::class, 'export']);
        Route::get('leads/duplicates', [LeadController::class, 'duplicates']);
        Route::get('leads/import/template', [LeadImportController::class, 'template']);
        Route::post('leads/import', [LeadImportController::class, 'store']);
        Route::post('leads/bulk', [LeadController::class, 'bulk']);
        Route::get('leads/queue', [LeadWorkspaceController::class, 'queue']);
        Route::get('leads/trash', [LeadWorkspaceController::class, 'trash']);
        Route::post('leads/trash/{id}/restore', [LeadWorkspaceController::class, 'restore'])->whereNumber('id');
        Route::delete('enrollments/{id}', [LeadWorkspaceController::class, 'stopEnrollment'])->whereNumber('id');
        Route::apiResource('leads', LeadController::class)->parameters(['leads' => 'id'])->whereNumber('id');
        Route::whereNumber('id')->prefix('leads/{id}')->group(function () {
            Route::post('status', [LeadController::class, 'changeStatus']);
            Route::post('assign', [LeadController::class, 'assign']);
            Route::post('convert', [LeadController::class, 'convert']);
            Route::get('score', [LeadController::class, 'score']);
            Route::post('score', [LeadController::class, 'adjustScore']);
            Route::get('history', [LeadController::class, 'history']);
            Route::post('claim', [LeadWorkspaceController::class, 'claim']);
            Route::post('merge', [LeadWorkspaceController::class, 'merge']);
            Route::put('qualification', [LeadWorkspaceController::class, 'qualification']);
            Route::get('insights', [LeadWorkspaceController::class, 'insights']);
            Route::post('email', [LeadWorkspaceController::class, 'sendEmail']);
            Route::post('email/preview', [LeadWorkspaceController::class, 'previewEmail']);
            Route::post('message', [LeadWorkspaceController::class, 'sendMessage']);
            Route::post('ai-brief', [LeadWorkspaceController::class, 'aiBrief'])->middleware('throttle:30,1');
            Route::get('enrollments', [LeadWorkspaceController::class, 'enrollments']);
            Route::post('enrollments', [LeadWorkspaceController::class, 'enroll']);
        });

        // Timeline & notes for any CRM record
        Route::whereIn('type', ['leads', 'deals', 'contacts', 'accounts'])->whereNumber('id')->group(function () {
            Route::get('{type}/{id}/activities', [ActivityController::class, 'index']);
            Route::post('{type}/{id}/activities', [ActivityController::class, 'store']);
            Route::get('{type}/{id}/notes', [NoteController::class, 'index']);
            Route::post('{type}/{id}/notes', [NoteController::class, 'store']);
        });
        Route::delete('activities/{id}', [ActivityController::class, 'destroy'])->whereNumber('id');
        Route::patch('notes/{id}', [NoteController::class, 'update'])->whereNumber('id');
        Route::delete('notes/{id}', [NoteController::class, 'destroy'])->whereNumber('id');

        // Tasks & follow-ups
        Route::get('tasks/summary', [TaskController::class, 'summary']);
        Route::apiResource('tasks', TaskController::class)->except('show')->parameters(['tasks' => 'id'])->whereNumber('id');
        Route::post('tasks/{id}/toggle', [TaskController::class, 'toggle'])->whereNumber('id');

        // CRM
        Route::get('deals/board', [DealController::class, 'board']);
        Route::get('deals/forecast', [DealController::class, 'forecast']);
        Route::middleware('role:admin,manager')->group(function () {
            Route::get('broadcasts', [BroadcastController::class, 'index']);
            Route::post('broadcasts', [BroadcastController::class, 'store']);
            Route::post('broadcasts/audience', [BroadcastController::class, 'audience']);
            Route::whereNumber('id')->group(function () {
                Route::get('broadcasts/{id}', [BroadcastController::class, 'show']);
                Route::put('broadcasts/{id}', [BroadcastController::class, 'update']);
                Route::delete('broadcasts/{id}', [BroadcastController::class, 'destroy']);
                Route::post('broadcasts/{id}/launch', [BroadcastController::class, 'launch'])->middleware('throttle:20,1');
                Route::post('broadcasts/{id}/cancel', [BroadcastController::class, 'cancel']);
                Route::post('broadcasts/{id}/pick-winner', [BroadcastController::class, 'pickWinner']);
                Route::get('broadcasts/{id}/recipients', [BroadcastController::class, 'recipients']);
            });
        });
        Route::get('connected-accounts', [ConnectedAccountController::class, 'index']);
        Route::post('connected-accounts/{provider}/connect', [ConnectedAccountController::class, 'connect'])->whereIn('provider', ['google', 'microsoft']);
        Route::patch('connected-accounts/{id}', [ConnectedAccountController::class, 'update'])->whereNumber('id');
        Route::post('connected-accounts/{id}/sync', [ConnectedAccountController::class, 'sync'])->whereNumber('id')->middleware('throttle:10,1');
        Route::delete('connected-accounts/{id}', [ConnectedAccountController::class, 'destroy'])->whereNumber('id');
        Route::get('inbox', [InboxController::class, 'index']);
        Route::get('inbox/summary', [InboxController::class, 'summary']);
        Route::get('inbox/{leadId}', [InboxController::class, 'show'])->whereNumber('leadId');
        Route::get('booking-page', [BookingController::class, 'mine']);
        Route::put('booking-page', [BookingController::class, 'save']);
        Route::get('booking-page/suggest', [BookingController::class, 'suggestSlug']);
        Route::get('auth/two-factor', [TwoFactorController::class, 'show']);
        Route::post('auth/two-factor', [TwoFactorController::class, 'setup']);
        Route::post('auth/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:10,1');
        Route::post('auth/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate']);
        Route::delete('auth/two-factor', [TwoFactorController::class, 'destroy']);
        Route::get('auth/calendar-feed', [CalendarFeedController::class, 'token']);
        Route::post('auth/calendar-feed', [CalendarFeedController::class, 'token']);
        Route::put('leads/{id}/consent', [PrivacyController::class, 'consent'])->whereNumber('id');
        Route::get('leads/{id}/export', [PrivacyController::class, 'export'])->whereNumber('id');
        Route::post('leads/{id}/erase', [PrivacyController::class, 'erase'])->whereNumber('id');
        Route::post('leads/{leadId}/call-notes', [CallNotesController::class, 'store'])->whereNumber('leadId')->middleware('throttle:30,1');
        Route::get('deals/{dealId}/quotes', [QuoteController::class, 'index'])->whereNumber('dealId');
        Route::post('deals/{dealId}/quotes', [QuoteController::class, 'store'])->whereNumber('dealId');
        Route::get('quotes/{id}', [QuoteController::class, 'show'])->whereNumber('id');
        Route::put('quotes/{id}', [QuoteController::class, 'update'])->whereNumber('id');
        Route::delete('quotes/{id}', [QuoteController::class, 'destroy'])->whereNumber('id');
        Route::post('quotes/{id}/send', [QuoteController::class, 'send'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('quotes/{id}/duplicate', [QuoteController::class, 'duplicate'])->whereNumber('id');
        Route::post('deals/{id}/move', [DealController::class, 'move'])->whereNumber('id');
        Route::apiResource('deals', DealController::class)->parameters(['deals' => 'id'])->whereNumber('id');
        Route::apiResource('contacts', ContactController::class)->parameters(['contacts' => 'id'])->whereNumber('id');
        Route::apiResource('accounts', AccountController::class)->parameters(['accounts' => 'id'])->whereNumber('id');

        // Common features
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'read']);
        Route::get('saved-views', [SavedViewController::class, 'index']);
        Route::post('saved-views', [SavedViewController::class, 'store']);
        Route::delete('saved-views/{id}', [SavedViewController::class, 'destroy'])->whereNumber('id');

        // Directory data every role can read; changes are restricted below.
        Route::get('settings/campaigns', [Settings\CampaignController::class, 'index']);
        Route::get('settings/teams', [Settings\TeamController::class, 'index']);
        Route::get('settings/users', [Settings\UserController::class, 'index']);
        Route::get('settings/email-templates', [Settings\EmailTemplateController::class, 'index']);
        Route::get('settings/sequences', [Settings\SequenceController::class, 'index']);

        Route::middleware('role:admin,manager')->group(function () {
            Route::apiResource('settings/campaigns', Settings\CampaignController::class)->except('index')->parameters(['campaigns' => 'id'])->whereNumber('id');
            Route::apiResource('settings/email-templates', Settings\EmailTemplateController::class)->except('index')->parameters(['email-templates' => 'id'])->whereNumber('id');
            Route::apiResource('settings/sequences', Settings\SequenceController::class)->except('index')->parameters(['sequences' => 'id'])->whereNumber('id');
            Route::apiResource('settings/products', Settings\ProductController::class)->except('index')->parameters(['products' => 'id'])->whereNumber('id');
            Route::apiResource('settings/ai-agents', Settings\AiAgentController::class)->except('index')->parameters(['ai-agents' => 'id'])->whereNumber('id');
            Route::get('audit-logs', [AuditLogController::class, 'index']);
        });

        Route::middleware('role:admin')->prefix('settings')->group(function () {
            Route::get('organization', [Settings\OrganizationController::class, 'show']);
            Route::patch('organization', [Settings\OrganizationController::class, 'update']);

            Route::get('integrations', [Settings\IntegrationController::class, 'index']);
            Route::post('integrations', [Settings\IntegrationController::class, 'store']);
            Route::patch('integrations/{id}', [Settings\IntegrationController::class, 'update'])->whereNumber('id');
            Route::delete('integrations/{id}', [Settings\IntegrationController::class, 'destroy'])->whereNumber('id');
            Route::post('integrations/{id}/test', [Settings\IntegrationController::class, 'test'])->whereNumber('id');
            Route::get('field-permissions', [Settings\FieldPermissionController::class, 'show']);
            Route::put('field-permissions', [Settings\FieldPermissionController::class, 'update']);
            Route::get('layouts', [Settings\LayoutController::class, 'index']);
            Route::put('layouts/{entity}', [Settings\LayoutController::class, 'update']);
            Route::delete('layouts/{entity}', [Settings\LayoutController::class, 'destroy']);
            Route::post('lead-statuses/reorder', [Settings\LeadStatusController::class, 'reorder']);
            Route::post('scoring-rules/recalculate', [Settings\ScoringRuleController::class, 'recalculate']);
            Route::get('automation-rules/{id}/executions', [Settings\AutomationRuleController::class, 'executions'])->whereNumber('id');
            Route::post('webhooks/{id}/test', [Settings\WebhookController::class, 'test'])->whereNumber('id');

            foreach ([
                'lead-statuses' => Settings\LeadStatusController::class,
                'lead-sources' => Settings\LeadSourceController::class,
                'pipeline-stages' => Settings\PipelineStageController::class,
                'pipelines' => Settings\PipelineController::class,
                'tags' => Settings\TagController::class,
                'custom-fields' => Settings\CustomFieldController::class,
                'assignment-rules' => Settings\AssignmentRuleController::class,
                'scoring-rules' => Settings\ScoringRuleController::class,
                'automation-rules' => Settings\AutomationRuleController::class,
                'webhooks' => Settings\WebhookController::class,
                'web-forms' => Settings\WebFormController::class,
            ] as $uri => $controller) {
                Route::apiResource($uri, $controller)->parameters([$uri => 'id'])->whereNumber('id');
            }

            Route::apiResource('teams', Settings\TeamController::class)->except('index')->parameters(['teams' => 'id'])->whereNumber('id');
            Route::apiResource('users', Settings\UserController::class)->only(['store', 'update', 'destroy'])->parameters(['users' => 'id'])->whereNumber('id');
            Route::get('api-keys', [Settings\ApiKeyController::class, 'index']);
            Route::post('api-keys', [Settings\ApiKeyController::class, 'store']);
            Route::delete('api-keys/{id}', [Settings\ApiKeyController::class, 'destroy'])->whereNumber('id');
        });
    });
});
