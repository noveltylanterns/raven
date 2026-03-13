<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

interface CsrfTokenStoreInterface
{
    public function get(string $key): ?string;

    public function set(string $key, string $value): void;

    public function remove(string $key): void;
}

