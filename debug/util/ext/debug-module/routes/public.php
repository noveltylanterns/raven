<?php

declare(strict_types=1);

use Raven\Lib\Routing\Router;

return static function (Router $router, array $context): void {
    $router->add('GET', '/debug-module/ping', static function (): void {
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'ok';
    });
};
