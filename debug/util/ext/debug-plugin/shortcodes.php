<?php

declare(strict_types=1);

return static function (array $context = []): array {
    $extension = trim((string) ($context['extension'] ?? 'debug-plugin'));
    if ($extension === '') {
        $extension = 'debug-plugin';
    }

    return [[
        'label' => 'Debug shortcode (' . $extension . ')',
        'shortcode' => '[' . $extension . ']',
    ]];
};
