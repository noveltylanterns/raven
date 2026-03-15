<?php

declare(strict_types=1);

return static function (array $context = []): array {
    $extension = trim((string) ($context['extension'] ?? 'debug-helper'));
    if ($extension === '') {
        $extension = 'debug-helper';
    }

    return [[
        'label' => 'Debug shortcode (' . $extension . ')',
        'shortcode' => '[' . $extension . ']',
    ]];
};
