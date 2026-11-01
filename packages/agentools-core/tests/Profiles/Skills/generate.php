<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Tests\Profiles\Skills\Fixture;

$f=new Fixture(); $entry=$f->publish(); $request=$f->request(routes:[$entry->manifest->dependency->ref]);
$selection=Fixture::selected($f->router->select($request)); $fragment=$f->store->entries[$entry->manifest->fragments[0]->key()];
$root=dirname(__DIR__,5).'/fixtures/platform/v1/skills';
if (!is_dir($root) && !mkdir($root,0777,true) && !is_dir($root)) { throw new RuntimeException('Fixture directory unavailable'); }
foreach (['manifest.json'=>$entry->manifest->toArray(), 'fragment.json'=>['ref'=>$fragment->ref->toArray(),
    'content_kind'=>$fragment->kind->value,'classification'=>$fragment->classification->value,'text'=>$fragment->text],
    'selection.json'=>$selection->jsonSerialize()] as $name=>$value) {
    if (file_put_contents($root.'/'.$name,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n")===false) {
        throw new RuntimeException('Skill fixture generation failed');
    }
    echo $root.'/'.$name."\n";
}
