<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2026 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Ghostchu\Openaicontentaudit\Api\Serializer;

use Flarum\Api\Serializer\AbstractSerializer;
use Ghostchu\Openaicontentaudit\Model\AuditLog;

class AuditLogSerializer extends AbstractSerializer
{
    /**
     * {@inheritdoc}
     */
    protected $type = 'audit-logs';

    /**
     * {@inheritdoc}
     */
    protected function getDefaultAttributes($model)
    {
        if (!($model instanceof AuditLog)) {
            throw new \InvalidArgumentException(
                'Model must be an instance of ' . AuditLog::class
            );
        }

        return [
            'id' => $model->id,
            'contentType' => $model->content_type,
            'contentId' => $model->content_id,
            'confidence' => $model->confidence,
            'conclusion' => $model->conclusion,
            'actionsTaken' => $model->actions_taken,
            'createdAt' => $this->formatDate($model->created_at),
            'updatedAt' => $this->formatDate($model->updated_at),
        ];
    }
}
