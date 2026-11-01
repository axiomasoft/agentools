<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

enum EntryPoint: string { case Text='text';case Raw='raw';case Build='build';case Stream='stream';case Voice='voice';case Background='background';case Scheduled='scheduled'; }
