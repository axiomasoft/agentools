<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament\Tests;

use AgenToolsPlatform\Core\Common\{InstanceId, Ref, SessionId};
use AgenToolsPlatform\Core\Profiles\{BundleManifest, UseBoundary};
use AgenToolsPlatform\Core\Profiles\Composition\LayerRole;
use AgenToolsPlatform\Core\Security\{Correlation, ExecutionContext};
use AgenToolsPlatform\Filament\Pages\Profiles;
use AgenToolsPlatform\Filament\Profiles\{Admin, Bindings, Operation, Request};
use AgenToolsPlatform\Laravel\Managed\HostBindings;
use PHPUnit\Framework\TestCase;

final class ProfilePublishTest extends TestCase
{
    private ProfileFixture $f;
    protected function setUp(): void { $this->f = new ProfileFixture(); }
    protected function tearDown(): void { $this->f->close(); }

    public function testDraftValidatePublishInspect(): void
    {
        $m = $this->f->merge->bundle(); $this->f->publish($m);
        $view = $this->f->call(Operation::Inspect, $m);
        self::assertSame('ok', $view->code); self::assertSame('published', $view->fields['state']);
        self::assertSame(3, $view->fields['revision']);
        self::assertSame($m->provenance->toArray(), $view->fields['provenance']);
        self::assertIsArray($view->fields['dependencies']); self::assertCount(count($m->dependencies), $view->fields['dependencies']);
        $json = json_encode($view, JSON_THROW_ON_ERROR);
        foreach (['effective_fields','resolved_restrictions','search_only','Friendly'] as $secret) { self::assertStringNotContainsString($secret, $json); }
        $events = $this->f->access->events;
        self::assertSame('actor', $events[0]['actor']); self::assertSame('reason:change', $events[0]['reason']);
        self::assertSame([], $this->f->base->runtime->trace->requests);
    }

    public function testPublishedImmutableNewDraftVersion(): void
    {
        $seven = $this->f->merge->bundle(); $this->f->publish($seven);
        self::assertSame('integrity_conflict', $this->f->call(Operation::Draft, $seven, 3)->code);
        $eight = $this->f->merge->bundle(version:8);
        self::assertSame('ok', $this->f->call(Operation::Draft, $eight)->code);
        self::assertSame('published', $this->f->call(Operation::Inspect, $seven)->fields['state']);
        self::assertSame($seven->bundle->toArray(), $this->f->call(Operation::Inspect, $seven)->fields['bundle']);
    }

    public function testTypedConflictAndProvenanceRemainDraft(): void
    {
        $good = $this->f->merge->bundle();
        $input = $this->f->merge->input([LayerRole::Tenant->value => $this->f->merge->layer(LayerRole::Tenant,
            $this->f->merge->hard(regions:['EU']), version:'2')]);
        $this->f->merge->catalog->register($input);
        $bad = new BundleManifest($good->bundle->ref, 7, $good->compiler, $good->definition, $good->profile,
            $input->dependencies(), $good->compiled, $good->provenance, $this->f->merge->json);
        self::assertSame('ok', $this->f->call(Operation::Draft, $bad)->code);
        $result = $this->f->call(Operation::Validate, $bad, 1);
        self::assertSame('validation_failed', $result->code); self::assertSame('draft', $result->fields['state']);
        self::assertContains('COMPILATION_FAILED', $result->issues);
        self::assertNotNull($result->conflict); self::assertSame('REGION_INTERSECTION_EMPTY', $result->conflict->code);
        self::assertNotEmpty($result->conflict->sourceRefs); self::assertNotEmpty($result->conflict->sourceValuesHashes);
        self::assertSame('policy_conflict', $this->f->call(Operation::Publish, $bad, 2)->code);
        $eight = $this->f->merge->bundle(version:8); $this->f->call(Operation::Draft, $eight);
        $this->f->authority->badProvenance = true;
        $failed = $this->f->call(Operation::Validate, $eight, 1);
        self::assertContains('PROVENANCE_INVALID', $failed->issues); self::assertSame('draft', $failed->fields['state']);
    }

    public function testCrossTenantAndActorRevocation(): void
    {
        $m = $this->f->merge->bundle(); $this->f->call(Operation::Draft, $m); $this->f->call(Operation::Validate, $m, 1);
        $this->f->access->allowed = false;
        self::assertSame('access_denied', $this->f->call(Operation::Publish, $m, 2)->code);
        self::assertSame([], $this->f->call(Operation::Inspect, $m)->fields);
        $this->f->access->allowed = true;
        $j = $this->f->base->runtime->current(); $j->composition->host->actor = 'revoked-actor';
        self::assertSame('access_denied', $this->f->call(Operation::Publish, $m, 2)->code);
        $j->composition->host->actor = 'actor';
        $foreign = (new \AgenToolsPlatform\Tests\Profiles\Merge\Fixture('beta'))->bundle();
        self::assertSame('invalid_input', $this->f->call(Operation::Draft, $foreign)->code);
        // Change current tenant while retaining the old service: refs are re-scoped and cannot find alpha.
        $this->f->base->runtime->tenant = 'beta'; $this->f->base->runtime->instance = 'beta-profile';
        $this->f->base->runtime->bindings(); $beta = $this->f->base->runtime->current();
        $this->f->access->current = $beta->context;
        $denied = $this->f->call(Operation::Inspect, $m);
        self::assertContains($denied->code, ['unavailable','access_denied']); self::assertSame([], $denied->fields);
    }

    public function testRevokeBetweenAuditAndWrite(): void
    {
        $m = $this->f->merge->bundle(); $this->f->call(Operation::Draft, $m); $this->f->call(Operation::Validate, $m, 1);
        $this->f->access->duringAudit = function (): void { $this->f->access->allowed = false; };
        self::assertSame('access_denied', $this->f->call(Operation::Publish, $m, 2)->code);
        $this->f->access->duringAudit = null; $this->f->access->allowed = true;
        self::assertSame('validated', $this->f->call(Operation::Inspect, $m)->fields['state']);
        $this->f->access->revokeAt = $this->f->access->calls + 3;
        self::assertSame([], $this->f->call(Operation::Inspect, $m)->fields);
    }

    public function testAssignmentPinsAndExplicitMigration(): void
    {
        $seven = $this->f->merge->bundle(); $eight = $this->f->merge->bundle(version:8);
        $this->f->publish($seven); $this->f->publish($eight);
        self::assertSame('new_sessions', $this->f->call(Operation::Assign, $seven, command:'assign-seven')->fields['applies_to']);
        $j = $this->f->base->runtime->current(); $c = $j->context; $instance = new InstanceId($j->tenant, 'assistant');
        $pin = $this->f->sessions->pin($c, $instance);
        self::assertSame('ok', $this->f->call(Operation::Assign, $eight, 1, 'assign-eight')->code);
        self::assertEquals($pin, $this->f->sessions->current($c, UseBoundary::Invocation));
        $new = ExecutionContext::fromHost($j->composition->host, new SessionId($j->tenant, 'new-profile-session'), $c->purpose, new Correlation('trace','new'));
        self::assertSame(8, $this->f->sessions->pin($new, $instance)->bundle->version);
        $migration = $this->f->call(Operation::Migrate, $eight, 1, 'migrate-eight');
        self::assertSame('ok', $migration->code); self::assertSame('explicit_session_migration', $migration->fields['applies_to']);
        self::assertSame(8, $this->f->sessions->current($c, UseBoundary::Invocation)->bundle->version);
        self::assertSame('optimistic_conflict', $this->f->call(Operation::Migrate, $eight, 1, 'stale-migration')->code);
        $audit = $j->db->row('SELECT reason,actor_ref,compatibility_ref FROM '.$j->db->table('profile_session_versions').' WHERE revision=2');
        self::assertNotNull($audit); self::assertSame('reason:change', $audit['reason']); self::assertSame('actor', $audit['actor_ref']);
        self::assertSame('validation:session-compatibility', $audit['compatibility_ref']);
        self::assertSame(2, $this->f->sessions->current($c, UseBoundary::Invocation)->revision);
    }

    public function testCommandReplayConflictAndCas(): void
    {
        $m = $this->f->merge->bundle(); $this->f->publish($m);
        self::assertSame('optimistic_conflict', $this->f->call(Operation::Assign, $m, 3)->code);
        self::assertSame(1, $this->f->call(Operation::Assign, $m)->fields['revision']);
        self::assertSame(1, $this->f->call(Operation::Assign, $m)->fields['revision']);
        self::assertSame('integrity_conflict', $this->f->call(Operation::Assign, $m, instance:'different-instance')->code);
        $this->f->access->deniedInstance = 'restricted-instance';
        self::assertSame('access_denied', $this->f->call(Operation::Assign, $m, command:'restricted', instance:'restricted-instance')->code);
        $events = array_values(array_filter($this->f->access->events, static fn (array $event): bool => $event['code'] === 'ok' && $event['operation'] === 'assign'));
        self::assertNotEmpty($events); self::assertSame('assistant', $events[0]['instance']);
    }

    public function testCurrentEmergencyAndCompatibilityGuards(): void
    {
        $m = $this->f->merge->bundle(); $this->f->publish($m); $this->f->call(Operation::Assign, $m);
        $j = $this->f->base->runtime->current(); $pin = $this->f->sessions->pin($j->context, new InstanceId($j->tenant,'assistant'));
        $this->f->authority->emergency = true;
        self::assertSame('access_denied', $this->f->call(Operation::Assign, $m, 1, 'emergency')->code);
        self::assertSame('access_denied', $this->f->call(Operation::Publish, $m, 3)->code);
        try { $this->f->sessions->assertPin($j->context, $pin, UseBoundary::Invocation); self::fail('Emergency applies above pins'); }
        catch (\AgenToolsPlatform\Core\Common\ContractViolation $e) { self::assertSame(\AgenToolsPlatform\Core\Common\ErrorCode::AccessDenied, $e->errorCode); }
        $this->f->authority->emergency = false; $this->f->authority->incompatible = true;
        self::assertSame('policy_conflict', $this->f->call(Operation::Migrate, $m, 1)->code);
        self::assertSame(1, $this->f->sessions->current($j->context, UseBoundary::Invocation)->revision);
        $this->f->authority->denied = true;
        self::assertSame('access_denied', $this->f->call(Operation::Inspect, $m)->code);
        self::assertSame('access_denied', $this->f->call(Operation::Publish, $m, 3)->code);
    }

    public function testDeprecateRetire(): void
    {
        $m = $this->f->merge->bundle(); $this->f->publish($m);
        self::assertSame('policy_conflict', $this->f->call(Operation::Retire, $m, 3)->code);
        self::assertSame('deprecated', $this->f->call(Operation::Deprecate, $m, 3)->fields['state']);
        self::assertSame('unavailable', $this->f->call(Operation::Assign, $m)->code);
        self::assertSame('retired', $this->f->call(Operation::Retire, $m, 4)->fields['state']);
    }

    public function testAuditFailureAndUnknownOutcome(): void
    {
        $m = $this->f->merge->bundle(); $this->f->access->auditFails = true;
        self::assertSame('audit_failed', $this->f->call(Operation::Draft, $m)->code);
        $this->f->access->auditFails = false;
        self::assertSame('unavailable', $this->f->call(Operation::Inspect, $m)->code);
        $this->f->access->failCompletedAudit = true;
        $unknown = $this->f->call(Operation::Draft, $m);
        self::assertSame('outcome_unknown', $unknown->code); self::assertSame([], $unknown->fields);
        self::assertStringNotContainsString('sensitive', json_encode($unknown, JSON_THROW_ON_ERROR));
        $this->f->access->failCompletedAudit = false;
        self::assertSame('draft', $this->f->call(Operation::Inspect, $m)->fields['state']);
    }

    public function testScopedCleanupAndProductionBindings(): void
    {
        $m = $this->f->merge->bundle(); $old = $this->f->admin(); $this->f->call(Operation::Draft, $m);
        $this->f->base->runtime->events->dispatch('Illuminate\\Queue\\Events\\JobProcessed');
        self::assertSame(1, $this->f->access->resets);
        self::assertSame('scope_retired', $old->execute(new Request(Operation::Inspect))->code);
        $this->f->base->runtime->app->instance('agentools.testing', false);
        $this->expectException(\LogicException::class);
        $this->f->base->runtime->app->make(Bindings::class);
    }

    public function testPageDelegatesAndRefreshesAuthorization(): void
    {
        $m = $this->f->merge->bundle(); $this->f->publish($m);
        \Illuminate\Container\Container::setInstance($this->f->base->runtime->app);
        $page = new Profiles(); $page->target = $m->bundle->ref->value; $page->hash = $m->bundle->hash->value;
        $page->version = 7; $page->expected = 3; $page->reason = 'reason:page';
        $read = new \ReflectionMethod($page, 'getViewData');
        $result = $read->invoke($page); self::assertIsArray($result); self::assertInstanceOf(\AgenToolsPlatform\Filament\Profiles\Result::class, $result['result']); self::assertSame('ok', $result['result']->code);
        $this->f->access->allowed = false; $page->deprecate();
        $result = $read->invoke($page); self::assertIsArray($result); self::assertInstanceOf(\AgenToolsPlatform\Filament\Profiles\Result::class, $result['result']); self::assertSame('access_denied', $result['result']->code);
        self::assertSame([], $result['result']->fields);
        self::assertArrayNotHasKey('result', get_object_vars($page));
        $this->f->access->allowed = true;
        self::assertSame('published', $this->f->call(Operation::Inspect, $m)->fields['state']);
    }

    public function testUntrustedFormEnvelopeAndRequiredReason(): void
    {
        $m = $this->f->merge->bundle();
        $manifest = $m->toArray();
        $manifest['php_class'] = 'Untrusted\\Publisher'; $manifest['tools_url'] = 'https://untrusted.invalid/tools';
        $manifest['sql'] = 'UPDATE profile_bundles SET state=published';
        $invalid = $this->f->admin()->execute(new Request(Operation::Draft,
            manifest:json_encode($manifest, JSON_THROW_ON_ERROR), reason:'reason:untrusted'));
        self::assertSame('invalid_input', $invalid->code);
        self::assertSame('unavailable', $this->f->call(Operation::Inspect, $m)->code);
        $this->f->publish($m);
        $missingReason = $this->f->admin()->execute(new Request(Operation::Deprecate,
            $m->bundle->ref->value, 7, $m->bundle->hash->value, 3));
        self::assertSame('invalid_input', $missingReason->code);
        self::assertSame('published', $this->f->call(Operation::Inspect, $m)->fields['state']);
    }
}
