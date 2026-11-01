<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Profiles;

use AgenToolsPlatform\Core\Common\{ContractViolation, IntegrityConflict};
use AgenToolsPlatform\Core\Profiles\{BundleRef, BundleState, CompiledProfile, UseBoundary};
use AgenToolsPlatform\Postgres\Profiles\Schema;
use PDOException;

final class PublicationTest extends DatabaseCase
{
    public function testLifecycleAndPublishedImmutability(): void
    {
        $m = $this->f->publish(); $c = $this->f->context();
        self::assertSame(BundleState::Published, $this->f->bundles->inspect($c, $m->bundle)->state);
        $this->rejects(fn () => $this->f->bundles->draft($c, $m, 3), IntegrityConflict::class);
        $changed = $this->f->core->manifest(compiled: new CompiledProfile('{"tone":"edited"}', '{}', $this->f->core->json));
        $this->rejects(fn () => $this->f->bundles->draft($c, $changed, 3), IntegrityConflict::class);
        foreach (["UPDATE profile_bundles SET manifest=jsonb_set(manifest,'{compiled,effective_fields,tone}', '\"edited\"'),revision=revision+1",
            "DELETE FROM profile_bundles", "UPDATE profile_bundles SET state='draft',revision=revision+1"] as $sql) {
            $this->rejects(fn () => $this->database->pdo->exec($sql), PDOException::class);
        }
        self::assertSame($m->bundle->hash->value, $this->f->bundles->inspect($c, $m->bundle)->manifest->bundle->hash->value);
    }

    public function testValidationFailureStaysDraft(): void
    {
        $f = $this->f; $c = $f->context();
        $bad = $f->core->manifest(compiled: new CompiledProfile('{"tone":"forged"}', '{}', $f->core->json));
        $f->bundles->draft($c, $bad);
        $result = $f->bundles->validate($c, $bad->bundle, 1);
        self::assertSame(BundleState::Draft, $result->state);
        self::assertSame(['COMPILER_OUTPUT_MISMATCH'], $result->issues);
        $this->rejects(fn () => $f->bundles->publish($c, $bad->bundle, 2), ContractViolation::class);
        $good = $f->core->manifest(); $f->bundles->draft($c, $good, 2);
        $f->authority->badProvenance = true;
        self::assertSame(['PROVENANCE_INVALID'], $f->bundles->validate($c, $good->bundle, 3)->issues);
        $f->authority->badProvenance = false;
        self::assertSame(BundleState::Validated, $f->bundles->validate($c, $good->bundle, 4)->state);
        $f->authority->badProvenance = true;
        $this->rejects(fn () => $f->bundles->publish($c, $good->bundle, 5), ContractViolation::class);
        $f->authority->badProvenance = false; $f->authority->provenanceConflict = true;
        $other = $f->core->manifest(8); $f->bundles->draft($c, $other);
        self::assertSame(['PROVENANCE_INVALID'], $f->bundles->validate($c, $other->bundle, 1)->issues);
        self::assertSame(BundleState::Draft, $f->bundles->inspect($c, $other->bundle)->state);
    }

    public function testPublishRechecksDependencies(): void
    {
        $f = $this->f; $m = $f->core->manifest(); $c = $f->context();
        $f->bundles->draft($c, $m); $f->bundles->validate($c, $m->bundle, 1);
        $f->core->overlay->revoked = true;
        $this->rejects(fn () => $f->bundles->publish($c, $m->bundle, 2), ContractViolation::class);
        self::assertSame(BundleState::Validated, $f->bundles->inspect($c, $m->bundle)->state);
        $f->core->overlay->revoked = false; $f->bundles->publish($c, $m->bundle, 2);
        $f->core->overlay->revoked = true;
        foreach (UseBoundary::cases() as $boundary) { $this->rejects(fn () => $f->bundles->use($c, $m->bundle, $boundary), ContractViolation::class); }
        self::assertCount(3, $f->bundles->inspect($c, $m->bundle)->manifest->dependencies);
    }

    public function testIdentityHashAndCasConflict(): void
    {
        $f = $this->f; $m = $f->core->manifest(); $c = $f->context();
        $f->bundles->draft($c, $m);
        $this->rejects(fn () => $f->bundles->draft($c, $m, 0), ContractViolation::class);
        $this->rejects(fn () => $f->bundles->validate($c, $m->bundle, 0), ContractViolation::class);
        $wrong = new BundleRef($m->bundle->ref, 7, \AgenToolsPlatform\Tests\Profiles\Fixture::hash('wrong'));
        $this->rejects(fn () => $f->bundles->inspect($c, $wrong), IntegrityConflict::class);
        $f->bundles->validate($c, $m->bundle, 1);
        $this->rejects(fn () => $f->bundles->publish($c, $m->bundle, 1), ContractViolation::class);
        self::assertSame(2, $f->bundles->inspect($c, $m->bundle)->revision);
    }

    public function testLifecycleRetainsHistory(): void
    {
        $f = $this->f; $m = $f->publish(); $c = $f->context();
        $f->bundles->advance($c, $m->bundle, 3, BundleState::Deprecated);
        self::assertSame($m->bundle->toArray(), $f->bundles->use($c, $m->bundle, UseBoundary::Invocation)->bundle->toArray());
        $this->rejects(fn () => $f->bundles->use($c, $m->bundle, UseBoundary::NewSession), ContractViolation::class);
        $f->bundles->advance($c, $m->bundle, 4, BundleState::Retired);
        $this->rejects(fn () => $f->bundles->use($c, $m->bundle, UseBoundary::Invocation), ContractViolation::class);
        self::assertSame($m->provenance->toArray(), $f->bundles->inspect($c, $m->bundle)->manifest->provenance->toArray());
        $this->rejects(fn () => $f->bundles->advance($c, $m->bundle, 5, BundleState::Published), ContractViolation::class);
    }

    public function testTenantAndCurrentOwner(): void
    {
        $f = $this->f; $m = $f->publish(); $c = $f->context();
        $other = new Fixture($this->database->pdo, $this->schema, 'tenant-b');
        $this->rejects(fn () => $f->bundles->inspect($other->context(), $m->bundle), ContractViolation::class);
        $otherManifest = $other->publish();
        self::assertNotSame($m->bundle->hash->value, $otherManifest->bundle->hash->value);
        $f->authority->denied = true;
        $this->rejects(fn () => $f->bundles->publish($c, $m->bundle, 2), ContractViolation::class);
        $f->authority->denied = false; $f->host->denied = true;
        $this->rejects(fn () => $f->bundles->inspect($c, $m->bundle), ContractViolation::class);
    }

    public function testSchemaInstallVersioned(): void
    {
        Schema::install($this->database->pdo, $this->schema);
        self::assertSame(1, $this->scalar('SELECT count(*) FROM profile_schema_versions'));
        $this->database->pdo->exec("UPDATE profile_schema_versions SET source_hash='wrong'");
        $this->rejects(fn () => Schema::install($this->database->pdo, $this->schema), IntegrityConflict::class);
    }
}
