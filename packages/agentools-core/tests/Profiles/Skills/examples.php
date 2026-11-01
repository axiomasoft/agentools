<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Core\Profiles\Skills\ManifestCodec;
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Profiles\Skills\{Fixture, Proof};

Proof::run(['skills.generated-examples'=>static function (): void {
    $root=dirname(__DIR__,5).'/fixtures/platform/v1/skills'; $f=new Fixture(); $entry=$f->publish();
    $codec=new ManifestCodec($f->merge->json); $wire=file_get_contents($root.'/manifest.json');
    if ($wire===false) { throw new RuntimeException('Required skill manifest example missing'); }
    H::check($codec->decode($wire)->dependency->toArray()===$entry->manifest->dependency->toArray());
    $wire=file_get_contents($root.'/selection.json');
    if ($wire===false) { throw new RuntimeException('Required selection example missing'); }
    $selection=Fixture::selected($f->router->select($f->request(routes:[$entry->manifest->dependency->ref])));
    H::check($f->merge->json->canonicalizeJson($wire)===$f->merge->json->canonicalize($selection->jsonSerialize()));
    $wire=file_get_contents($root.'/fragment.json');
    if ($wire===false) { throw new RuntimeException('Required fragment example missing'); }
    $stored=json_decode($wire,true,64,JSON_THROW_ON_ERROR);
    if (!is_array($stored)) { throw new RuntimeException('Fragment object required'); }
    H::check($stored['text']===$f->fragment()->text && $stored['ref']===$entry->manifest->fragments[0]->toArray());
}]);
