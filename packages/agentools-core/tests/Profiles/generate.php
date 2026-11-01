<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use AgenToolsPlatform\Core\Profiles\{Dependency, OwnerScope, ResourceKind, ResourceRef};
use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Tests\Profiles\Fixture;

$root = dirname(__DIR__, 4) . '/fixtures/platform/v1/profiles';
if (!is_dir($root) && !mkdir($root, 0777, true) && !is_dir($root)) { throw new RuntimeException('Fixture directory unavailable'); }
$f = new Fixture();
foreach ([7, 8] as $version) {
    $path = $root . '/bundle-' . $version . '.json';
    if (file_put_contents($path, json_encode($f->manifest($version)->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) { throw new RuntimeException('Fixture generation failed'); }
    echo $path . "\n";
}
$skillContent = ['format' => 'skill-manifest-example/v1', 'evidence_tier' => 'design-schema',
    'owner' => OwnerScope::platform()->toArray(), 'id' => 'skill.support-read', 'version' => '1',
    'provenance' => Fixture::provenance()->toArray(), 'purpose' => 'support.read',
    'tools' => [['id' => 'search', 'version' => '1', 'hash' => Fixture::hash('search-definition-example')->value]],
    'restriction_refs' => ['policy:read-only'], 'methodology_ref' => 'methodology:support-read:1'];
$skillHash = new PayloadHash('sha-256:' . hash('sha256', $f->json->canonicalize($skillContent)));
$skill = new Dependency(new ResourceRef(OwnerScope::platform(), ResourceKind::Skill, 'skill.support-read', '1', $skillHash), Fixture::provenance());
if (file_put_contents($root . '/skill-manifest.example.json', json_encode($skillContent + ['content_hash' => $skillHash->value], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) {
    throw new RuntimeException('Skill manifest example generation failed');
}
if (file_put_contents($root . '/skill-dependency.json', json_encode($skill->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) {
    throw new RuntimeException('Skill dependency generation failed');
}
