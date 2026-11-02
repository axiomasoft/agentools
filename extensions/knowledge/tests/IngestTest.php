<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests;

use AgenToolsPlatform\Knowledge\{Decision, EmbeddingSpace, Extraction, Extractor, GenericValidator, Integrity, Material,
    Pipeline, Release, Scope, SourceDocument, ValidationResult, Validator};
use AgenToolsPlatform\Knowledge\Adapters\PlainTextExtractor;
use PHPUnit\Framework\TestCase;

final class IngestTest extends TestCase
{
    public function testOwnerProjectionAndProvenance(): void
    {
        $f = new Fixture(); $release = $f->pipeline()->ingest($f->scope, 'corpus:school', 'release:1', $f->material);
        self::assertCount(1, $f->capture->published);
        self::assertSame(['extract', 'publish'], $f->owner->operations);
        $p = $release->projection();
        self::assertSame('library:algebra', $p['material']['ownerRef']);
        self::assertSame('license:school', $p['material']['licenseRef']);
        self::assertSame('policy:school', $p['material']['accessPolicyRef']);
        self::assertArrayNotHasKey('bytes', $p['material']);
        self::assertSame('validated', $p['artifacts'][1]['validation_status']);
        self::assertSame($f->material->sourceHash, $p['artifacts'][1]['sourceHash']);
        self::assertSame(1, $p['artifacts'][1]['page']);
        self::assertSame(strlen($f->source->document->pages[0]), $p['artifacts'][1]['end']);
        self::assertSame($release->hash(), Release::restore($release->json(), $release->hash())->hash());
    }

    public function testTenantAudienceScopeBeforeSource(): void
    {
        foreach ([new Scope('tenant:b', 'actor:a', 'students', 'education', ['read']),
            new Scope('tenant:a', 'actor:a', 'staff', 'education', ['read']),
            new Scope('tenant:a', 'actor:a', 'students', 'education', [])] as $scope) {
            $f = new Fixture();
            try { $f->pipeline()->ingest($scope, 'c', 'r', $f->material); self::fail('Scope accepted'); }
            catch (\DomainException) { self::assertSame(0, $f->source->loads); self::assertSame([], $f->capture->published); }
        }
    }

    public function testOwnerDeniedAndUnknown(): void
    {
        foreach ([Decision::Denied, Decision::Unknown] as $decision) {
            $f = new Fixture(); $f->owner->decision = $decision;
            try { $f->pipeline()->ingest($f->scope, 'c', 'r', $f->material); self::fail('Owner restriction accepted'); }
            catch (\DomainException) { self::assertSame(0, $f->source->loads); }
        }
    }

    public function testSourceRefAndHashMismatch(): void
    {
        $f = new Fixture();
        foreach ([new SourceDocument('files:wrong', $f->source->document->bytes, $f->source->document->pages),
            new SourceDocument($f->material->bytesRef, 'corrupted', $f->source->document->pages)] as $source) {
            self::assertContains('source_mapping', (new GenericValidator())->validate($f->material, $source, $f->extraction())->errors);
        }
    }

    public function testArtifactHashesAndSourceMapping(): void
    {
        $f = new Fixture(); $a = $f->extraction()->artifacts;
        foreach ([['sourceHash' => str_repeat('0', 64)], ['pageHash' => str_repeat('0', 64)],
            ['contentHash' => str_repeat('0', 64)], ['text' => 'invented', 'contentHash' => hash('sha256', 'invented')]] as $changes) {
            $result = (new GenericValidator())->validate($f->material, $f->source->document,
                new Extraction('v1', [$a[0], Fixture::artifact($a[1], $changes)]));
            self::assertSame('failed', $result->status);
        }
    }

    public function testUtf8AndCoordinateBoundaries(): void
    {
        $f = new Fixture(); $a = $f->extraction()->artifacts;
        foreach ([['page' => 0], ['page' => 2], ['start' => -1], ['end' => 10000], ['end' => 0],
            ['start' => 1], ['box' => [0.9, 0.0, 0.1, 1.0]], ['box' => [0.0, 0.0, INF, 1.0]]] as $changes) {
            self::assertSame('failed', (new GenericValidator())->validate($f->material, $f->source->document,
                new Extraction('v1', [$a[0], Fixture::artifact($a[1], $changes)]))->status);
        }
        $source = new SourceDocument($f->material->bytesRef, $f->source->document->bytes, ["\xff"]);
        self::assertContains('page_encoding_or_empty', (new GenericValidator())->validate($f->material, $source, $f->extraction())->errors);
    }

    public function testHierarchyAndDuplicateIds(): void
    {
        $f = new Fixture(); $a = $f->extraction()->artifacts;
        foreach ([[$a[0], $a[0], $a[1]], [$a[0], Fixture::artifact($a[1], ['parent' => 'missing'])],
            [Fixture::artifact($a[0], ['parent' => $a[0]->id]), $a[1]],
            [Fixture::artifact($a[0], ['start' => 2, 'text' => substr($a[0]->text, 2), 'contentHash' => hash('sha256', substr($a[0]->text, 2))]), $a[1]]] as $artifacts) {
            self::assertSame('failed', (new GenericValidator())->validate($f->material, $f->source->document, new Extraction('v1', $artifacts))->status);
        }
    }

    public function testCompletenessAndRequiredCards(): void
    {
        $f = new Fixture();
        foreach ([[], [$f->extraction()->artifacts[0]]] as $artifacts) {
            self::assertContains('incomplete', (new GenericValidator())->validate($f->material, $f->source->document, new Extraction('v1', $artifacts))->errors);
        }
        $a = $f->extraction()->artifacts;
        $validator = new GenericValidator(['node', 'fragment', 'navigation', 'card']);
        self::assertSame('failed', $validator->validate($f->material, $f->source->document, $f->extraction())->status);
        $a[] = Fixture::artifact($a[1], ['id' => 'nav:1', 'kind' => 'navigation', 'text' => 'Navigation', 'contentHash' => hash('sha256', 'Navigation')]);
        $a[] = Fixture::artifact($a[1], ['id' => 'card:1', 'kind' => 'card', 'text' => 'A knowledge card', 'contentHash' => hash('sha256', 'A knowledge card')]);
        $release = Release::validate('c', 'r', $f->material, $f->source->document, new Extraction('cards/v1', $a), $validator);
        self::assertCount(4, $release->projection()['artifacts']);
    }

    public function testUnknownValidationCannotPublish(): void
    {
        $f = new Fixture();
        foreach (['failed', 'unknown'] as $status) {
            $validator = new class($status) implements Validator {
                public function __construct(private string $status) {}
                public function validate(Material $material, SourceDocument $source, Extraction $extraction): ValidationResult { return new ValidationResult($this->status); }
            };
            $pipeline = new Pipeline($f->source, new PlainTextExtractor(), new GenericValidator(), $f->owner, $f->capture, $validator);
            try { $pipeline->ingest($f->scope, 'c', 'r', $f->material); self::fail('Unvalidated release published'); }
            catch (\DomainException) { self::assertSame([], $f->capture->published); }
        }
    }

    public function testRevokeDuringExtraction(): void
    {
        $f = new Fixture();
        $extractor = new class($f->owner) implements Extractor {
            public function __construct(private Owner $owner) {}
            public function extract(Material $material, SourceDocument $source): Extraction
            { $this->owner->decision = Decision::Denied; return (new PlainTextExtractor())->extract($material, $source); }
        };
        $pipeline = new Pipeline($f->source, $extractor, new GenericValidator(), $f->owner, $f->capture);
        try { $pipeline->ingest($f->scope, 'c', 'r', $f->material); self::fail('Revoked publish'); }
        catch (\DomainException) { self::assertSame([], $f->capture->published); }
    }

    public function testImmutableAndCorruptRelease(): void
    {
        $f = new Fixture(); $release = $f->release();
        $release->assertPublishable();
        $forged = $release->projection();
        $forged['artifacts'][1]['text'] = 'self-signed invented text';
        $decoded = Release::restore(json_encode($forged, JSON_THROW_ON_ERROR), Integrity::digest($forged));
        try { $decoded->assertPublishable(); self::fail('Storage decoder bypassed validation'); }
        catch (\DomainException) { self::assertTrue(true); }
        try { $release->id = 'changed'; self::fail('Mutable release'); } catch (\Error) { self::assertSame('release:1', $release->id); }
        $data = $release->projection(); $data['artifacts'][1]['text'] = 'invented';
        $this->expectException(\UnexpectedValueException::class);
        Release::restore(json_encode($data, JSON_THROW_ON_ERROR), $release->hash());
    }

    public function testEmbeddingSpaceIsolation(): void
    {
        $space = new EmbeddingSpace('provider:a', 'model:a', 'r1', 3, 'unit', 'index:1');
        foreach ([new EmbeddingSpace('provider:b', 'model:a', 'r1', 3, 'unit', 'index:1'),
            new EmbeddingSpace('provider:a', 'model:b', 'r1', 3, 'unit', 'index:1'),
            new EmbeddingSpace('provider:a', 'model:a', 'r2', 3, 'unit', 'index:1'),
            new EmbeddingSpace('provider:a', 'model:a', 'r1', 4, 'unit', 'index:1'),
            new EmbeddingSpace('provider:a', 'model:a', 'r1', 3, 'none', 'index:1'),
            new EmbeddingSpace('provider:a', 'model:a', 'r1', 3, 'unit', 'index:2')] as $other) {
            try { $space->assertCompatible($other); self::fail('Space mismatch accepted'); }
            catch (\DomainException) { self::assertNotSame($space->key(), $other->key()); }
        }
        $f = new Fixture(); self::assertNotSame($f->release()->hash(), $f->release(space: $space)->hash());
    }

    public function testMultiplePagesAndTenantIdentity(): void
    {
        $f = new Fixture(); $source = new SourceDocument($f->material->bytesRef, $f->source->document->bytes, ['First page', 'Second page']);
        $extraction = (new PlainTextExtractor())->extract($f->material, $source);
        self::assertSame('validated', (new GenericValidator())->validate($f->material, $source, $extraction)->status);
        self::assertSame([1, 1, 2, 2], array_map(fn($a) => $a->page, $extraction->artifacts));
        self::assertNotSame($f->release()->hash(), (new Fixture('tenant:b'))->release()->hash());
        self::assertNotSame($f->material->key(), (new Fixture('tenant:b'))->material->key());
    }

    public function testSharedFixtures(): void
    {
        $root = dirname(__DIR__, 3) . '/fixtures/platform/v1/knowledge';
        $data = json_decode(file_get_contents($root . '/release.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame((new Fixture())->release()->projection(), $data['release']);
        self::assertSame(Integrity::digest($data['release']), $data['hash']);
        $negative = json_decode(file_get_contents($root . '/negative.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['cross-tenant', 'revoke', 'space-mismatch'], array_column($negative['cases'], 'id'));
        self::assertSame('tenant:b', $negative['cases'][0]['scope']['tenant']);
        self::assertSame(Decision::Denied->value, $negative['cases'][1]['decision']);
        self::assertNotSame($negative['cases'][2]['left']['model'], $negative['cases'][2]['right']['model']);
    }
}
