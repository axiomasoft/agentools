<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Profiles\Merge;

use AgenToolsPlatform\Core\Common\{InstanceId, IntegrityConflict, Ref};
use AgenToolsPlatform\Core\Profiles\{BundleManifest, BundleState, BundleValidator, CompiledProfile, UseBoundary};
use AgenToolsPlatform\Core\Profiles\Composition\LayerRole;
use AgenToolsPlatform\Postgres\Profiles\{BundleRepository, SessionRepository};
use AgenToolsPlatform\Postgres\Tests\Profiles\DatabaseCase;
use PDOException;

final class PublicationMergeTest extends DatabaseCase
{
    private function publisher(Fixture $fixture): BundleRepository
    {
        return new BundleRepository($this->f->environment, new BundleValidator($fixture->catalog->layered(), $fixture->compiler), $this->f->authority, $fixture->json);
    }
    private function publish(BundleRepository $publisher, BundleManifest $manifest): void
    {
        $c = $this->f->context(); $publisher->draft($c, $manifest);
        self::assertSame(BundleState::Validated, $publisher->validate($c, $manifest->bundle, 1)->state);
        self::assertSame(BundleState::Published, $publisher->publish($c, $manifest->bundle, 2)->state);
    }
    public function testPinnedVersionReproducible(): void
    {
        $f = new Fixture(); $seven = $f->bundle(); $eight = $f->bundle(version:8); $publisher = $this->publisher($f); $this->publish($publisher, $seven);
        $sessions = new SessionRepository($this->f->environment, $publisher, $this->f->authority);
        $instance = new InstanceId($f->tenant, 'assistant'); $c = $this->f->context();
        $sessions->assign($c, new Ref($f->tenant, 'assign-seven'), $instance, $seven->definition, $seven->bundle, 0);
        $pin = $sessions->pin($c, $instance); $before = Proof::compiled($f->compiler->pinned($seven))->compiled;
        $this->publish($publisher, $eight);
        $sessions->assign($c, new Ref($f->tenant, 'assign-eight'), $instance, $eight->definition, $eight->bundle, 1);
        self::assertSame($pin->bundle->toArray(), $sessions->current($c, UseBoundary::Invocation)->bundle->toArray());
        self::assertSame(7, $pin->bundle->version);
        $after = Proof::compiled($f->compiler->pinned($publisher->inspect($c, $pin->bundle)->manifest));
        self::assertSame($before->effectiveJson, $after->compiled->effectiveJson);
        self::assertSame($before->restrictionsJson, $after->compiled->restrictionsJson);
        self::assertSame(8, $sessions->pin($this->f->context('new-session'), $instance)->bundle->version);
    }
    public function testCrossTenantPublicationDenied(): void
    {
        $alpha = new Fixture(); $beta = new Fixture('tenant-b'); $foreign = $beta->bundle();
        $candidate = new BundleManifest(new Ref($alpha->tenant, 'bundle.foreign'), 7, $alpha->compiler->version(),
            $foreign->definition, $foreign->profile, $foreign->dependencies, $foreign->compiled, Fixture::provenance(), $alpha->json);
        $publisher = $this->publisher($alpha); $c = $this->f->context(); $publisher->draft($c, $candidate);
        $result = $publisher->validate($c, $candidate->bundle, 1);
        self::assertSame(BundleState::Draft, $result->state); self::assertContains('DEPENDENCY_INVALID', $result->issues);
        self::assertSame(0, $this->scalar("SELECT count(*) FROM profile_bundles WHERE state='published'"));
        self::assertSame('tenant-b', $candidate->profile->owner->tenant?->value);
    }
    public function testPublishedHashMutationDenied(): void
    {
        $f = new Fixture(); $manifest = $f->bundle(); $publisher = $this->publisher($f); $this->publish($publisher, $manifest);
        try {
            $this->database->pdo->exec("UPDATE profile_bundles SET manifest=jsonb_set(manifest,'{compiled,effective_fields,style,tone}','\"forged\"'),revision=revision+1");
            self::fail('Published UPDATE must fail');
        } catch (PDOException $error) { self::assertSame('23514', (string)$error->getCode()); }
        $changed = new BundleManifest($manifest->bundle->ref, 7, $manifest->compiler, $manifest->definition, $manifest->profile, $manifest->dependencies,
            new CompiledProfile('{"style":"forged"}', $manifest->compiled->restrictionsJson, $f->json), $manifest->provenance, $f->json);
        $this->rejects(fn () => $publisher->draft($this->f->context(), $changed, 3), IntegrityConflict::class);
        self::assertSame($manifest->bundle->hash->value, $publisher->inspect($this->f->context(), $manifest->bundle)->manifest->bundle->hash->value);
    }
    public function testSameTenantPublication(): void
    {
        $f = new Fixture(); $manifest = $f->bundle(); $publisher = $this->publisher($f); $this->publish($publisher, $manifest);
        $stored = $publisher->inspect($this->f->context(), $manifest->bundle);
        self::assertSame($manifest->bundle->hash->value, $stored->manifest->bundle->hash->value);
        foreach ($stored->manifest->dependencies as $dependency) {
            self::assertContains($dependency->ref->owner->kind, ['platform','tenant']);
            if ($dependency->ref->owner->kind === 'tenant') { self::assertSame('tenant-a', $dependency->ref->owner->tenant?->value); }
        }
        self::assertSame($f->compiler->version()->toArray(), $stored->manifest->compiler->toArray());
        self::assertSame([], $stored->issues);
    }
    public function testCachedOwnerCorruption(): void
    {
        $alpha = new Fixture(); $manifest = $alpha->bundle(); $beta = new Fixture('tenant-b');
        $alpha->catalog->corrupted = $alpha->input([LayerRole::Profile->value=>$beta->layer(LayerRole::Profile, version:'7')]);
        $conflict = Proof::conflict($alpha->compiler->resolve($manifest));
        self::assertSame('PINNED_ROOT_MISMATCH', $conflict->code);
        self::assertStringContainsString('tenant-b', json_encode($conflict, JSON_THROW_ON_ERROR));
        $publisher = $this->publisher($alpha); $c = $this->f->context(); $publisher->draft($c, $manifest);
        $result = $publisher->validate($c, $manifest->bundle, 1);
        self::assertSame(BundleState::Draft, $result->state); self::assertContains('COMPILATION_FAILED', $result->issues);
        self::assertSame(0, $this->scalar("SELECT count(*) FROM profile_bundles WHERE state='published'"));
        self::assertSame($manifest->bundle->hash->value, $result->manifest->bundle->hash->value);
    }
}
