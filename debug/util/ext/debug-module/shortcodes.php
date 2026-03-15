<?php

declare(strict_types=1);

return static function (array $context = []): array {
    $extension = trim((string) ($context['extension'] ?? 'debug-module'));
    if ($extension === '') {
        $extension = 'debug-module';
    }

    return [[
        'label' => 'Debug shortcode (' . $extension . ')',
        'shortcode' => '[' . $extension . ']',
    ]];
};
