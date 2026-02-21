<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2024 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::addColumns('oaicontaudit_logs', [
    'response_format_version' => ['string', 'length' => 20, 'nullable' => true, 'default' => 'json_schema', 'after' => 'api_response'],
]);
