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
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ShowAuditLogController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        
        // Check permission
        $actor->assertCan('ghostchu-openaicontentaudit.viewAuditLogs');

        $id = $request->getAttribute('id');
        $log = AuditLog::findOrFail($id);

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

        if ($actor->can('ghostchu-openaicontentaudit.viewFullAuditLogs')) {
            $attributes['auditedContent'] = $log->audited_content;
            $attributes['apiRequest'] = $log->api_request;
            $attributes['apiResponse'] = $log->api_response;
        }

        return new JsonResponse([
            'data' => [
                'type' => 'audit-logs',
                'id' => (string) $log->id,
                'attributes' => $attributes,
            ],
        ]);
    }
}
