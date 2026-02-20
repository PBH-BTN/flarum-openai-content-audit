<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2024 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ghostchu\Openaicontentaudit\Api\Controller;

use Flarum\Http\RequestUtil;
use Ghostchu\Openaicontentaudit\Model\AuditLog;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListAuditLogsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        
        // Check permission
        $actor->assertCan('ghostchu-openaicontentaudit.viewAuditLogs');

        $params = $request->getQueryParams();
        $filters = Arr::get($params, 'filter', []);
        $sort = Arr::get($params, 'sort', '-createdAt');
        $limit = Arr::get($params, 'page.limit', 20);
        $offset = Arr::get($params, 'page.offset', 0);

        $query = AuditLog::query();

        // Apply filters
        if (!empty($filters['contentType'])) {
            $query->where('content_type', $filters['contentType']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['userId'])) {
            $query->where('user_id', $filters['userId']);
        }

        if (!empty($filters['minConfidence'])) {
            $query->where('confidence', '>=', (float) $filters['minConfidence']);
        }

        // Apply sorting
        $sortField = ltrim($sort, '-+');
        $sortDirection = str_starts_with($sort, '-') ? 'desc' : 'asc';

        $fieldMap = [
            'createdAt' => 'created_at',
            'confidence' => 'confidence',
            'status' => 'status',
        ];

        if (isset($fieldMap[$sortField])) {
            $query->orderBy($fieldMap[$sortField], $sortDirection);
        }

        // Get total count
        $total = $query->count();

        // Paginate
        $results = $query->skip($offset)->take($limit)->with('user')->get();

        // Transform to JSON:API format
        $data = $results->map(function ($log) use ($actor) {
            return $this->serializeLog($log, $actor);
        })->toArray();

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'total' => $total,
            ],
        ]);
    }

    private function serializeLog(AuditLog $log, $actor): array
    {
        $attributes = [
            'contentType' => $log->content_type,
            'contentId' => $log->content_id,
            'userId' => $log->user_id,
            'confidence' => $log->confidence ? (float) $log->confidence : null,
            'actionsTaken' => $log->actions_taken,
            'conclusion' => $log->conclusion,
            'status' => $log->status,
            'retryCount' => $log->retry_count,
            'errorMessage' => $log->error_message,
            'createdAt' => $log->created_at?->toIso8601String(),
            'updatedAt' => $log->updated_at?->toIso8601String(),
        ];

        // Only include sensitive data if user has permission
        if ($actor->can('ghostchu-openaicontentaudit.viewFullAuditLogs')) {
            $attributes['auditedContent'] = $log->audited_content;
            $attributes['apiRequest'] = $log->api_request;
            $attributes['apiResponse'] = $log->api_response;
        }

        return [
            'type' => 'audit-logs',
            'id' => (string) $log->id,
            'attributes' => $attributes,
            'relationships' => [
                'user' => [
                    'data' => $log->user ? [
                        'type' => 'users',
                        'id' => (string) $log->user_id,
                    ] : null,
                ],
            ],
        ];
    }
}
