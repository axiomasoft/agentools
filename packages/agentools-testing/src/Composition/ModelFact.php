<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Models\{Deployment,ModelResponse};

final readonly class ModelFact { public function __construct(public Deployment $deployment,public ModelResponse $response) {} }
