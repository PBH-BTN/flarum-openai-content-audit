<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2026 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Ghostchu\Openaicontentaudit\Api\Resource;

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Ghostchu\Openaicontentaudit\Model\AuditLog;
use Illuminate\Database\Eloquent\Builder;

/**
 * Flarum 2.x JSON:API Resource for AuditLog records.
 *
 * Replaces the Flarum 1.x ListAuditLogsController and ShowAuditLogController
 * which used the legacy tobscure/json-api-document + AbstractSerializer pattern.
 *
 * Registered in extend.php via: new Extend\ApiResource(AuditLogResource::class)
 *
 * Endpoints provided:
 *   GET /api/audit-logs         → Index (requires viewAuditLogs permission)
 *   GET /api/audit-logs/{id}    → Show  (requires viewAuditLogs permission)
 *
 * The retry action (POST /api/audit-logs/{id}/retry) remains a standalone
 * PSR-15 route handler (RetryAuditController) registered via Extend\Routes.
 */
class AuditLogResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'audit-logs';
    }

    public function model(): string
    {
        return AuditLog::class;
    }

    /**
     * Scope the Eloquent query.
     *
     * Called for both Index and Show endpoints. We enforce the base `viewAuditLogs`
     * permission here so that the actor cannot even discover records without
     * the required permission.
     *
     * Request-level filters (contentType, status, userId, minConfidence) are also
     * applied here so they work for both Index (list) and scoped lookups.
     */
    public function scope(Builder $query, Context $context): void
    {
        // Enforce base permission: throws 403 if not allowed
        $context->getActor()->assertCan('ghostchu-openaicontentaudit.viewAuditLogs');

        // Apply optional request-level filters from query string:
        // GET /api/audit-logs?filter[contentType]=post&filter[status]=failed
        $filters = $context->request->getQueryParams()['filter'] ?? [];

        if (!empty($filters['contentType'])) {
            $query->where('content_type', $filters['contentType']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['userId'])) {
            $query->where('user_id', (int) $filters['userId']);
        }

        if (!empty($filters['minConfidence'])) {
            $query->where('confidence', '>=', (float) $filters['minConfidence']);
        }
    }

    /**
     * Define available API endpoints.
     *
     * Only Index and Show are exposed. Write operations are not needed since
     * audit logs are created internally by AuditContentJob, not via API.
     */
    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->paginate()
                ->defaultSort('-createdAt'),

            Endpoint\Show::make()
                ->authenticated(),
        ];
    }

    /**
     * Define sortable columns.
     *
     * Usage: GET /api/audit-logs?sort=-createdAt
     *        GET /api/audit-logs?sort=confidence
     */
    public function sorts(): array
    {
        return [
            SortColumn::make('createdAt')->column('created_at'),
            SortColumn::make('confidence'),
            SortColumn::make('status'),
        ];
    }

    /**
     * Define JSON:API resource attributes (fields).
     *
     * Sensitive fields (auditedContent, apiRequest, apiResponse) are only
     * visible to actors with the viewFullAuditLogs permission.
     *
     * Note on array/JSON fields: AuditLog's Eloquent model casts these columns
     * to PHP arrays automatically. Flarum 2.x's Schema\Attribute passes the value
     * through as-is, which PHP's JSON encoder will serialise as a JSON array/object.
     */
    public function fields(): array
    {
        return [
            // --- Identification ---
            Schema\Str::make('contentType')
                ->property('content_type'),

            Schema\Str::make('contentId')
                ->property('content_id'),

            Schema\Integer::make('userId')
                ->property('user_id'),

            // --- Audit result ---
            Schema\Number::make('confidence'),

            Schema\Str::make('conclusion'),

            // JSON array column — cast to PHP array by the model
            Schema\Attribute::make('actionsTaken')
                ->property('actions_taken')
                ->get(fn(AuditLog $model) => $model->actions_taken),

            // --- Status tracking ---
            Schema\Str::make('status'),

            Schema\Integer::make('retryCount')
                ->property('retry_count'),

            Schema\Str::make('errorMessage')
                ->property('error_message'),

            // --- Timestamps ---
            Schema\DateTime::make('createdAt')
                ->property('created_at'),

            Schema\DateTime::make('updatedAt')
                ->property('updated_at'),

            // --- Sensitive / full-log fields ---
            // Only exposed to actors with viewFullAuditLogs permission

            Schema\Attribute::make('auditedContent')
                ->property('audited_content')
                ->get(fn(AuditLog $model) => $model->audited_content)
                ->visible(fn(AuditLog $model, Context $context) =>
                    $context->getActor()->can('ghostchu-openaicontentaudit.viewFullAuditLogs')
                ),

            Schema\Attribute::make('apiRequest')
                ->property('api_request')
                ->get(fn(AuditLog $model) => $model->api_request)
                ->visible(fn(AuditLog $model, Context $context) =>
                    $context->getActor()->can('ghostchu-openaicontentaudit.viewFullAuditLogs')
                ),

            Schema\Attribute::make('apiResponse')
                ->property('api_response')
                ->get(fn(AuditLog $model) => $model->api_response)
                ->visible(fn(AuditLog $model, Context $context) =>
                    $context->getActor()->can('ghostchu-openaicontentaudit.viewFullAuditLogs')
                ),
        ];
    }
}
