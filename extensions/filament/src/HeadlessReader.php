<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament;

use AgenToolsPlatform\Core\Actions\ActionApplication;
use AgenToolsPlatform\Core\Common\RunId;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Laravel\Managed\HostBindings;
use AgenToolsPlatform\Postgres\Actions\SubmissionLifecycle;
use AgenToolsPlatform\Postgres\Runtime\{RepositoryContext, RunRepository};

/** This adapter calls only the existing headless read methods. No SQL or execution loop. */
final readonly class HeadlessReader
{
    private RunRepository $runs;
    private ActionApplication $actions;

    public function __construct(HostBindings $ports)
    {
        $environment = new RepositoryContext($ports->connection, $ports->hasher, $ports->authorization, $ports->revisions);
        $this->runs = new RunRepository($environment);
        $this->actions = new ActionApplication($ports->registry, $ports->policy, $ports->authorization, $ports->schemas,
            new SubmissionLifecycle($environment, $ports->registry, $ports->policy, $ports->schemas));
    }

    public function read(ExecutionContext $context, Target $target): Inspection
    {
        if ($target->id instanceof RunId) {
            $run = $this->runs->get($context, $target->id);
            if ($run === null) { return new Inspection('not_found'); }
            return new Inspection('ok', ['tenant_id' => $run->id->tenant->value, 'run_id' => $run->id->value,
                'turn_id' => $run->turn->value, 'status' => $run->status->value, 'version' => $run->version,
                'fencing_epoch' => $run->fencingEpoch]);
        }
        $action = $this->actions->status($target->id, $context);
        return new Inspection('ok', ['tenant_id' => $action->receipt->actionId->tenant->value,
            'action_id' => $action->receipt->actionId->value, 'status' => $action->receipt->wireStatus,
            'version' => $action->receipt->version, 'owner_outcome' => $action->outcome()?->value,
            'safe_next_step' => $action->safeNextStep, 'definition_id' => $action->definition->id,
            'definition_version' => $action->definition->version, 'definition_hash' => $action->definition->hash->value]);
    }
}
