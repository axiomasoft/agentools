<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Common\PayloadHash;

final readonly class Record { public function __construct(public PayloadHash $hash,public mixed $value) {} }
