<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2024 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ghostchu\Openaicontentaudit\Exception;

use RuntimeException;

/**
 * Exception thrown when content (post, discussion, user, etc.) is not found.
 * This typically indicates the content was deleted after the audit job was queued.
 * This exception should not trigger job retries as the content will not reappear.
 */
class ContentNotFoundException extends RuntimeException
{
}
