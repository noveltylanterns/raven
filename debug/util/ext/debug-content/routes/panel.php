<?php

declare(strict_types=1);

use Raven\Lib\Routing\Router;

return static function (Router $router, array $context): void {
    /** @var callable(): void $requirePanelLogin */
    $requirePanelLogin = is_callable($context['requirePanelLogin'] ?? null)
        ? $context['requirePanelLogin']
        : static function (): void {};

    $router->add('GET', '/debug-content', static function () use ($requirePanelLogin): void {
        $requirePanelLogin();
        echo '<section class="card"><div class="card-body"><h1>Debug Content Demo</h1><p class="text-muted mb-0">Debug dummy extension route is active.</p></div></section>';
    });
};
