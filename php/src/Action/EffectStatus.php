<?php

declare(strict_types=1);

namespace AgenTools\Action;

enum EffectStatus: string
{
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
}
