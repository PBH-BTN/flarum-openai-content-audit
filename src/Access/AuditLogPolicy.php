<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2024 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ghostchu\Openaicontentaudit\Access;

use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;
use Ghostchu\Openaicontentaudit\Model\AuditLog;

class AuditLogPolicy extends AbstractPolicy
{
    /**
     * Check if user can view audit logs.
     *
     * @param User $actor
     * @param string $ability
     * @return bool|null
     */
    public function can(User $actor, string $ability): ?bool
    {
        if ($ability === 'ghostchu-openaicontentaudit.viewAuditLogs') {
            return $actor->hasPermission('ghostchu-openaicontentaudit.viewAuditLogs');
        }

        if ($ability === 'ghostchu-openaicontentaudit.viewFullAuditLogs') {
            return $actor->isAdmin() || $actor->hasPermission('ghostchu-openaicontentaudit.viewFullAuditLogs');
        }

        if ($ability === 'ghostchu-openaicontentaudit.retryAudit') {
            return $actor->hasPermission('ghostchu-openaicontentaudit.retryAudit');
        }

        return null;
    }
}
