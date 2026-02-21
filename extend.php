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
use Ghostchu\Openaicontentaudit\Api\Controller\ListAuditLogsController;
use Ghostchu\Openaicontentaudit\Api\Controller\RetryAuditController;
use Ghostchu\Openaicontentaudit\Api\Controller\ShowAuditLogController;
use Ghostchu\Openaicontentaudit\Listener\QueueContentAudit;
use Ghostchu\Openaicontentaudit\Model\AuditLog;
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

    // API Routes
    (new Extend\Routes('api'))
        ->get('/audit-logs', 'audit-logs.index', ListAuditLogsController::class)
        ->get('/audit-logs/{id}', 'audit-logs.show', ShowAuditLogController::class)
        ->post('/audit-logs/{id}/retry', 'audit-logs.retry', RetryAuditController::class),

    // Policies
    (new Extend\Policy())
        ->modelPolicy(AuditLog::class, AuditLogPolicy::class),

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
        ->default('ghostchu.openaicontentaudit.suspend_days', 7)
        ->default('ghostchu.openaicontentaudit.system_user_id', 1)
        ->serializeToForum('ghostchu-openai-content-audit.preApproveEnabled', 'ghostchu.openaicontentaudit.pre_approve_enabled', 'boolval'),
];
