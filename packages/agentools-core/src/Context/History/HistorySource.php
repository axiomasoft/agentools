<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** External owner (e.g. Chatom), current visibility and exact version/hash reads. */
interface HistorySource
{
    public function recent(ExecutionContext $context,Ref $branch,int $afterTurn,int $limit): HistoryPage;
    public function manifest(ExecutionContext $context,Ref $branch,int $throughTurn): SourceManifest;
    public function read(ExecutionContext $context,MessageRef $ref): MessageRead;
}
