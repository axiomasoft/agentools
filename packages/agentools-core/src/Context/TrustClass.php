<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

enum TrustClass: string { case Policy='policy'; case Owner='owner'; case Untrusted='untrusted'; }
