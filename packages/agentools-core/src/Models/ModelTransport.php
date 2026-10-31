<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

/** One exchange, proposal-only. Runtime alone maps durable proposals to Actions. */
interface ModelTransport
{
    public function generate(ModelRequest $request, Deployment $deployment): ModelResponse;

    /** @return iterable<ModelEvent> No callback executes tools or effects. */
    public function stream(ModelRequest $request, Deployment $deployment): iterable;
}
