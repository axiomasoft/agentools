<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\Memory;

enum DeleteStatus: string { case Pending='pending';case Confirmed='confirmed';case Unknown='unknown';case Failed='failed'; }
