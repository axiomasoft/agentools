<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use AgenToolsPlatform\Core\Profiles\ManifestCodec;
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Profiles\Fixture;

H::run(['profiles.generated-manifest-examples' => static function (): void {
    $f = new Fixture(); $codec = new ManifestCodec($f->json);
    foreach ([7, 8] as $version) {
        $wire = file_get_contents(dirname(__DIR__, 4) . '/fixtures/platform/v1/profiles/bundle-' . $version . '.json');
        if ($wire === false) { throw new RuntimeException('Required manifest example missing'); }
        H::check($codec->decode($wire, $f->tenant)->bundle->toArray() === $f->manifest($version)->bundle->toArray());
    }
    $wire = file_get_contents(dirname(__DIR__, 4) . '/fixtures/platform/v1/profiles/skill-manifest.example.json');
    if ($wire === false) { throw new RuntimeException('Required skill example missing'); }
    $skill = json_decode($wire, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($skill) || !is_string($skill['content_hash'] ?? null)) { throw new RuntimeException('Hashed skill example required'); }
    $hash = $skill['content_hash']; unset($skill['content_hash']);
    H::check($hash === 'sha-256:' . hash('sha256', $f->json->canonicalize($skill)));
}], 'unit', 'unit');
