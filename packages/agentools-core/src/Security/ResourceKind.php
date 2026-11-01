<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

enum ResourceKind: string { case Tenant='tenant';case Actor='actor';case Credentials='credentials';case Policy='policy';case Profile='profile';case Retrieval='retrieval';case Payloads='payloads'; }
