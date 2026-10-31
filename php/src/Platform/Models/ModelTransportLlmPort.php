<?php

declare(strict_types=1);

namespace AgenTools\Platform\Models;

use AgenTools\Contracts\LlmPort;
use AgenToolsPlatform\Core\Models\Deployment;
use AgenToolsPlatform\Core\Models\ModelTransport;

/** Optional bridge. Existing LlmPort implementations and consumers require no changes. */
final readonly class ModelTransportLlmPort implements LlmPort
{
    public function __construct(private ModelTransport $transport, private Deployment $deployment, private LegacyModelMapper $mapper) {}

    public function respond(array $input): array
    {
        $request = $this->mapper->request($input);
        $response = $this->transport->generate($request, $this->deployment);
        $response->assertFor($request, $this->deployment);
        return $this->mapper->response($response);
    }
}
