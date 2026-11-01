<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

enum Trigger: string { case Threshold='threshold'; case Segment='segment'; case Topic='topic'; case Session='session'; case Model='model'; case PeriodicRebuild='periodic_rebuild'; }
