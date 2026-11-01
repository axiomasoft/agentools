<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Models;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Models\{ContentPart, ModelMessage, ModelRequest, MessageRole};
use AgenToolsPlatform\Core\Runtime\InvocationPlan;

/** Independent headless batch composition: no HTTP/Chatom/Laravel entrypoint dependency. */
final class SecondHostFixture
{
    public static function configure(TestRig $rig): void
    {
        $base = $rig->plan;
        $request = new ModelRequest($base->request->invocationId,
            [new ModelMessage(MessageRole::User, [ContentPart::text('Summarize the authorized read result for a batch job')])],
            $base->snapshot->ref, $base->snapshot->contentHash, $base->request->credentialRef,
            $base->request->deadline, $rig->guard, system: 'Headless batch report', tools: []);
        $rig->plan = new InvocationPlan($base->runId, $base->turnId, $base->stepKey, $request,
            new Ref($base->runId->tenant, 'batch-request:' . $request->invocationId->value), $base->deployment,
            $base->snapshot, $base->bundle, new Ref($base->runId->tenant, 'batch-business-operation'),
            new Ref($base->runId->tenant, 'batch-results:job-17'), [], $base->bounds, 10, null, null, $rig->fixture->hasher);
        $rig->provider->tools = false;
    }
}
