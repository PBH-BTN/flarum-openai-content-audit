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

/**
 * @deprecated Flarum 2.x removed the tobscure/json-api-document AbstractSerializer layer.
 *
 * Serialization is now handled by AuditLogResource (src/Api/Resource/AuditLogResource.php)
 * which uses the new tobyz/json-api-server based Resource API.
 *
 * This class is kept as an empty stub to avoid breaking any third-party code that may
 * reference the class name. It can be safely removed once all consumers are updated.
 *
 * @see \Ghostchu\Openaicontentaudit\Api\Resource\AuditLogResource
 */
class AuditLogSerializer
{
}
