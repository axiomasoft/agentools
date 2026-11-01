<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament\Tests;

use AgenToolsPlatform\Filament\{InspectionFailure, Inspector, InspectorPlugin, Pages\Inspect};
use AgenToolsPlatform\Postgres\Runtime\RunRepository;
use PHPUnit\Framework\TestCase;

final class InspectorTest extends TestCase
{
    private Fixture $f;
    protected function setUp(): void { $this->f = new Fixture(); }
    protected function tearDown(): void { $this->f->close(); }

    public function testAuthorizedReadOnly(): void
    {
        $j = $this->f->runtime->current();
        $j->accept();
        $before = (new RunRepository($j->environment))->get($j->context, $j->run);
        $result = $this->f->inspector()->inspect('run', $j->run->value);
        self::assertSame('ok', $result->code);
        self::assertSame(['tenant_id','run_id','turn_id','status','version','fencing_epoch'], array_keys($result->fields));
        self::assertSame('accepted', $result->fields['status']);
        self::assertEquals($before, (new RunRepository($j->environment))->get($j->context, $j->run));
        $receipt = $j->actions->submit($j->composition->submission(context: $j->context));
        $action = $this->f->inspector()->inspect('action', $receipt->view->receipt->actionId->value);
        self::assertSame('ok', $action->code);
        self::assertSame('created', $action->fields['status']);
        self::assertNull($action->fields['owner_outcome']);
        self::assertSame($j->composition->definition->ref->hash->value, $action->fields['definition_hash']);
        self::assertCount(2, $this->f->access->events);
        self::assertSame('actor', $this->f->access->events[0]['actor']);
        self::assertSame(0, $this->f->diagnostics->reads);
        self::assertSame([], $this->f->runtime->trace->requests);
    }

    public function testNoMutationOrHiddenDereference(): void
    {
        $j = $this->f->runtime->current(); $j->accept();
        $j->db->execute('UPDATE '.$j->db->table('runs').' SET version=version+1, checkpoint=checkpoint || CAST(:secret AS jsonb) WHERE tenant_id=:tenant AND id=:id',
            ['tenant' => $j->tenant->value, 'id' => $j->run->value, 'secret' => '{"nested":{"prompt":"alpha secret","diagnostic_ref":"secret:raw","exception":"secret exception"}}']);
        $result = $this->f->inspector()->inspect('run', $j->run->value);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['alpha secret', 'secret:raw', 'secret exception', 'nested', 'checkpoint'] as $secret) { self::assertStringNotContainsString($secret, $json); }
        self::assertSame(0, $this->f->diagnostics->reads);
        foreach (['publish','assign','retireProfile','submit','cancel','resolve','execute'] as $method) {
            self::assertFalse(method_exists(Inspector::class, $method));
            self::assertFalse(method_exists(Inspect::class, $method));
        }
        $receipt = $j->actions->submit($j->composition->submission(context: $j->context));
        $id = $receipt->view->receipt->actionId->value;
        try {
            $j->db->execute('UPDATE '.$j->db->table('action_current').' SET state=jsonb_set(state,\'{status}\',\'"success"\'::jsonb) WHERE tenant_id=:tenant AND id=:id',
                ['tenant' => $j->tenant->value, 'id' => $id]);
            self::fail('Direct DB success must be denied');
        } catch (\AgenToolsPlatform\Core\Common\ContractViolation $error) {
            self::assertSame(\AgenToolsPlatform\Core\Common\ErrorCode::InvalidInput, $error->errorCode);
        }
        $bad = $this->f->inspector()->inspect('action', $id);
        self::assertSame('ok', $bad->code); self::assertSame('created', $bad->fields['status']);
        self::assertSame([], $this->f->runtime->trace->requests);
    }

    public function testTypedFailuresAndRevocation(): void
    {
        $j = $this->f->runtime->current(); $j->accept(); $inspector = $this->f->inspector();
        self::assertSame('not_found', $inspector->inspect('run', 'missing')->code);
        self::assertSame('access_denied', $inspector->inspect('action', 'missing')->code);
        self::assertSame('invalid_target', $inspector->inspect('publish', $j->run->value)->code);
        $this->f->access->allowed = false;
        self::assertSame('access_denied', $inspector->inspect('run', $j->run->value)->code);
        $this->f->access->allowed = true;
        $this->f->access->revokeAt = $this->f->access->authorizations + 2;
        $revoked = $inspector->inspect('run', $j->run->value);
        self::assertSame('access_denied', $revoked->code); self::assertSame([], $revoked->fields);
        $this->f->access->revokeAt = $this->f->access->authorizations + 3;
        $revokedAfterAudit = $inspector->inspect('run', $j->run->value);
        self::assertSame('access_denied', $revokedAfterAudit->code); self::assertSame([], $revokedAfterAudit->fields);
        $this->f->access->revokeAt = null;
        $j->composition->host->actor = 'other-actor';
        self::assertSame('access_denied', $inspector->inspect('run', $j->run->value)->code);
        $j->composition->host->actor = 'actor';
        $this->f->access->revokeAt = null; $this->f->access->readFails = true;
        self::assertSame('read_failed', $inspector->inspect('run', $j->run->value)->code);
        self::assertStringNotContainsString('alpha secret', json_encode($this->f->access->events, JSON_THROW_ON_ERROR));
        $this->f->access->readFails = false;
        $j->db->execute('ALTER TABLE '.$j->db->table('runs').' RENAME TO unavailable_runs');
        try { self::assertSame('read_failed', $inspector->inspect('run', $j->run->value)->code); }
        finally { $j->db->execute('ALTER TABLE '.$j->db->table('unavailable_runs').' RENAME TO runs'); }
    }

    public function testAuditFailureClosesDisclosure(): void
    {
        $j = $this->f->runtime->current(); $j->accept(); $this->f->access->auditFails = true;
        try { $this->f->inspector()->inspect('run', $j->run->value); self::fail('Audit must fail closed'); }
        catch (InspectionFailure $error) {
            self::assertSame('audit_failed', $error->getMessage()); self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('secret', (string)$error);
        }
    }

    public function testSensitivePermissionAndTtl(): void
    {
        $j = $this->f->runtime->current(); $j->accept(); $inspector = $this->f->inspector();
        try { $inspector->diagnostic('run', $j->run->value, 30); self::fail('Separate permission required'); }
        catch (InspectionFailure $error) { self::assertSame('access_denied', $error->reason); }
        self::assertSame(0, $this->f->diagnostics->reads);
        $this->f->access->sensitive = true;
        foreach ([0, -1, 301] as $ttl) {
            try { $inspector->diagnostic('run', $j->run->value, $ttl); self::fail('Bounded TTL required'); }
            catch (InspectionFailure $error) { self::assertSame('diagnostics_unavailable', $error->reason); }
        }
        $before = $j->composition->host->now();
        $value = $inspector->diagnostic('run', $j->run->value, 30);
        self::assertSame('alpha sensitive diagnostic', $value->payload);
        self::assertGreaterThan($before, $value->expiresAt);
        self::assertSame(1, $this->f->diagnostics->reads);
        $last = array_key_last($this->f->access->events); self::assertNotNull($last);
        self::assertSame('authorized', $this->f->access->events[$last]['code']);
        $j->composition->host->instant = new \DateTimeImmutable('2030-01-01T00:00:00.500000Z');
        $this->f->diagnostics->duringRead = static function () use ($j): void {
            $j->composition->host->instant = new \DateTimeImmutable('2030-01-01T00:00:01.500000Z');
        };
        try { $inspector->diagnostic('run', $j->run->value, 1); self::fail('Expired diagnostic disclosure'); }
        catch (InspectionFailure $error) { self::assertSame('diagnostic_expired', $error->reason); }
        $j->composition->host->instant = null;
        $this->f->diagnostics->duringRead = function (): void { $this->f->access->allowed = false; };
        try { $inspector->diagnostic('run', $j->run->value, 30); self::fail('Revoked diagnostic disclosure'); }
        catch (InspectionFailure $error) { self::assertSame('access_denied', $error->reason); }
    }

    public function testTenantSwitchAndRetainedScope(): void
    {
        $j = $this->f->runtime->current(); $j->accept(); $old = $this->f->inspector();
        self::assertSame('ok', $old->inspect('run', $j->run->value)->code);
        $this->f->runtime->events->dispatch('Illuminate\\Queue\\Events\\JobProcessed');
        self::assertSame('scope_retired', $old->inspect('run', $j->run->value)->code);
        self::assertSame(1, $this->f->access->resets); self::assertSame(1, $this->f->diagnostics->resets);
        $this->f->runtime->tenant = 'beta'; $this->f->runtime->instance = 'beta-input';
        $this->f->runtime->app->make(\AgenToolsPlatform\Laravel\Managed\HostBindings::class);
        $beta = $this->f->runtime->current(); $beta->accept();
        $this->f->access = new HostAccess($beta->context);
        $new = $this->f->inspector();
        self::assertSame('not_found', $new->inspect('run', $j->run->value)->code);
        $visible = $new->inspect('run', $beta->run->value);
        self::assertSame('ok', $visible->code); self::assertSame('beta', $visible->fields['tenant_id']);
        self::assertStringNotContainsString('alpha', json_encode($visible, JSON_THROW_ON_ERROR));
    }

    public function testProductionRejectsTestingBindings(): void
    {
        $this->f->runtime->app->instance('env', 'production');
        try { $this->f->inspector(); self::fail('Testing environment mismatch'); }
        catch (\LogicException $error) { self::assertStringContainsString('testing environment', $error->getMessage()); }
        $this->f->runtime->app->instance('agentools.testing', false);
        $this->expectException(\LogicException::class);
        $this->f->inspector();
    }

    public function testUnknownOwnerOutcome(): void
    {
        $j = $this->f->runtime->current(); $j->accept(); $j->advance();
        $grant = $j->confirm(); $ledger = $j->ledger($grant);
        $ledger->prepare('documents.owner', \AgenToolsPlatform\Core\Actions\Effects\Strategy::NativeKey);
        $applying = $this->f->inspector()->inspect('action', $grant->action->value);
        self::assertSame('ok', $applying->code); self::assertSame('applying', $applying->fields['status']);
        self::assertSame('unknown', $applying->fields['owner_outcome']);
        self::assertSame('reconcile', $applying->fields['safe_next_step']);
        $ledger->unknown('owner_unavailable');
        $unknown = $this->f->inspector()->inspect('action', $grant->action->value);
        self::assertSame('reconciliation_required', $unknown->fields['status']);
        self::assertSame('unknown', $unknown->fields['owner_outcome']);
        self::assertSame(0, $j->count('example_owner_writes'));
    }

    public function testPartialResolutionCleanupFailure(): void
    {
        $app = $this->f->runtime->app;
        $app->make(\AgenToolsPlatform\Filament\Access::class);
        $app->make(\AgenToolsPlatform\Filament\Diagnostics::class);
        $this->f->access->resetFails = true;
        try {
            $this->f->runtime->events->dispatch('Illuminate\\Queue\\Events\\JobExceptionOccurred');
            self::fail('Reset failure must poison health');
        } catch (\AgenToolsPlatform\Laravel\Managed\LifecycleFailure $error) {
            self::assertStringNotContainsString('secret', (string)$error);
        }
        self::assertSame(1, $this->f->access->resets);
        self::assertSame(1, $this->f->diagnostics->resets);
        $this->expectException(\AgenToolsPlatform\Laravel\Managed\LifecycleFailure::class);
        $app->make(\AgenToolsPlatform\Laravel\Managed\LifecycleHealth::class)->assertUsable();
    }

    public function testPageUsesFreshReadAndEscapedView(): void
    {
        $j = $this->f->runtime->current(); $j->accept();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($this->f->runtime->app);
        \Illuminate\Container\Container::setInstance($this->f->runtime->app);
        $page = new Inspect(); $page->target = $j->run->value;
        $read = new \ReflectionMethod($page, 'getViewData');
        $first = $read->invoke($page);
        self::assertIsArray($first); self::assertInstanceOf(\AgenToolsPlatform\Filament\Inspection::class, $first['inspection']);
        self::assertSame('ok', $first['inspection']->code);
        $this->f->access->allowed = false;
        $second = $read->invoke($page);
        self::assertIsArray($second); self::assertInstanceOf(\AgenToolsPlatform\Filament\Inspection::class, $second['inspection']);
        self::assertSame('access_denied', $second['inspection']->code);
        self::assertSame([], $second['inspection']->fields);
        foreach (get_object_vars($page) as $property) { self::assertNotInstanceOf(\AgenToolsPlatform\Filament\Inspection::class, $property); }
    }
}
