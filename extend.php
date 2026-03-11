<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2026 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Ghostchu\Openaicontentaudit;

use Flarum\Extend;
use Ghostchu\Openaicontentaudit\Access\AuditLogPolicy;
use Ghostchu\Openaicontentaudit\Api\Controller\RetryAuditController;
use Ghostchu\Openaicontentaudit\Api\Resource\AuditLogResource;
use Ghostchu\Openaicontentaudit\Listener\QueueContentAudit;
use Ghostchu\Openaicontentaudit\Notification\ContentViolationBlueprint;
use Ghostchu\Openaicontentaudit\Provider\AuditServiceProvider;

return [
    // Frontend
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),
        
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    // Locales
    new Extend\Locales(__DIR__.'/locale'),

    // Event listeners
    (new Extend\Event())
        ->subscribe(QueueContentAudit::class),

    // Service provider
    (new Extend\ServiceProvider())
        ->register(AuditServiceProvider::class),

    // Policies
    (new Extend\Policy())
        ->globalPolicy(AuditLogPolicy::class),

    // API Resource — provides:
    //   GET  /api/audit-logs       (Index, paginated, filterable, sortable)
    //   GET  /api/audit-logs/{id}  (Show)
    // Replaces the Flarum 1.x ListAuditLogsController + ShowAuditLogController
    // + AuditLogSerializer (AbstractSerializer) pattern.
    new Extend\ApiResource(AuditLogResource::class),

    // Retry route: non-standard action, kept as a standalone PSR-15 handler
    (new Extend\Routes('api'))
        ->post('/audit-logs/{id}/retry', 'audit-logs.retry', RetryAuditController::class),

    // Settings with defaults
    (new Extend\Settings())
        ->default('ghostchu.openaicontentaudit.api_endpoint', 'https://api.openai.com/v1')
        ->default('ghostchu.openaicontentaudit.api_key', '')
        ->default('ghostchu.openaicontentaudit.model', 'gpt-4o')
        ->default('ghostchu.openaicontentaudit.temperature', 0.3)
        ->default('ghostchu.openaicontentaudit.max_tokens', 4096)
        ->default('ghostchu.openaicontentaudit.timeout', 60)
        ->default('ghostchu.openaicontentaudit.system_prompt', '')
        ->default('ghostchu.openaicontentaudit.confidence_threshold', 0.7)
        ->default('ghostchu.openaicontentaudit.pre_approve_enabled', false)
        ->default('ghostchu.openaicontentaudit.download_images', true)
        ->default('ghostchu.openaicontentaudit.default_display_name', '')
        ->default('ghostchu.openaicontentaudit.default_bio', '')
        ->default('ghostchu.openaicontentaudit.username_audit_enabled', true)
        ->default('ghostchu.openaicontentaudit.nickname_audit_enabled', true)
        ->default('ghostchu.openaicontentaudit.suspend_days', 7)
        ->default('ghostchu.openaicontentaudit.system_user_id', 1)
        ->default('ghostchu-openai-content-audit.upload_audit_enabled', false)
        ->default('ghostchu-openai-content-audit.upload_audit_image_max_size', 10)
        ->default('ghostchu-openai-content-audit.upload_audit_text_max_size', 64)
        ->serializeToForum('ghostchu-openai-content-audit.preApproveEnabled', 'ghostchu.openaicontentaudit.pre_approve_enabled', 'boolval'),

    // View namespace for email templates
    (new Extend\View())
        ->namespace('ghostchu-openai-content-audit', __DIR__.'/resources/views'),

    // Notification types
    // Flarum 2.x: the serializer (2nd) argument was removed. Serialization is now
    // handled by the registered ApiResource for the subject model (AuditLog → AuditLogResource).
    (new Extend\Notification())
        ->type(ContentViolationBlueprint::class, ['alert', 'email']),
];
