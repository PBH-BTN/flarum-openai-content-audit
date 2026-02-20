<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2024 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ghostchu\Openaicontentaudit\Provider;

use Flarum\Foundation\AbstractServiceProvider;
use Ghostchu\Openaicontentaudit\Service\AuditResultHandler;
use Ghostchu\Openaicontentaudit\Service\ContentExtractor;
use Ghostchu\Openaicontentaudit\Service\MessageNotifier;
use Ghostchu\Openaicontentaudit\Service\OpenAIClient;

class AuditServiceProvider extends AbstractServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->container->singleton(OpenAIClient::class, function ($container) {
            return new OpenAIClient(
                $container->make('flarum.settings'),
                $container->make('log')
            );
        });

        $this->container->singleton(ContentExtractor::class, function ($container) {
            return new ContentExtractor(
                $container->make('flarum.settings'),
                $container->make('log')
            );
        });

        $this->container->singleton(MessageNotifier::class, function ($container) {
            return new MessageNotifier(
                $container->make('flarum.settings'),
                $container->make('events'),
                $container->make('log')
            );
        });

        $this->container->singleton(AuditResultHandler::class, function ($container) {
            return new AuditResultHandler(
                $container->make('flarum.settings'),
                $container->make('events'),
                $container->make('log'),
                $container->make(MessageNotifier::class)
            );
        });
    }
}
