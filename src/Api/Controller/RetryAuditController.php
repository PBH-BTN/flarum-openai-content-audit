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
use Ghostchu\Openaicontentaudit\Job\AuditContentJob;
use Ghostchu\Openaicontentaudit\Model\AuditLog;
use Illuminate\Contracts\Queue\Queue;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RetryAuditController implements RequestHandlerInterface
{
    public function __construct(
        private Queue $queue
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        
        // Check permission
        $actor->assertCan('ghostchu-openaicontentaudit.retryAudit');

        $id = $request->getAttribute('id');
        $log = AuditLog::findOrFail($id);

        // Mark as retrying
        $log->markAsRetrying();

        // Queue new audit job
        $this->queue->push(new AuditContentJob(
            $log->content_type,
            $log->content_id,
            $log->user_id,
            $log->audited_content['content'] ?? []
        ));

        return new JsonResponse([
            'data' => [
                'type' => 'audit-logs',
                'id' => (string) $log->id,
                'attributes' => [
                    'status' => $log->status,
                    'retryCount' => $log->retry_count,
                ],
            ],
        ]);
    }
}
