<?php

declare(strict_types=1);

return static function (array $context = []): array {
    return [[
        'slug' => 'debug_text',
        'label' => 'Debug Text',
        'editor' => 'plaintext',
    ]];
};
