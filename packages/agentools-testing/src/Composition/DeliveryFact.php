<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Common\DeliveryIntent;

final readonly class DeliveryFact { public function __construct(public DeliveryIntent $intent,public DeliveryResult $result) {} }
