<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval;

interface Cache
{
    public function get(string $key): ?Selection;
    public function put(string $key, Selection $selection): void;
}
