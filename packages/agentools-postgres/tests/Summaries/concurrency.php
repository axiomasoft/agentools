<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';require_once __DIR__.'/Process.php';

use AgenToolsPlatform\Postgres\Tests\Summaries\{Fixture,Process,Proof};
use AgenToolsPlatform\Tests\Harness as H;

Proof::run(['summary.competing-connections'=>static function(): void {
    $f=new Fixture();try {
        $one=new Process($f->schema,text:'candidate A');$two=new Process($f->schema,text:'candidate B');$one->go();$two->go();
        $a=$one->line();$b=$two->line();$exits=[$one->finish(),$two->finish()];$outcomes=[$a['outcome'],$b['outcome']];sort($outcomes);
        H::check($one->backend!==$two->backend && $exits===[0,0] && $outcomes===['appended','integrity_conflict']);
        H::check($f->count('summary_versions')===1 && $f->count('summary_heads')===1 && $f->count('derived_refs')===1);
        Proof::$observations['competing_connections']=['backends'=>[$one->backend,$two->backend],'exits'=>$exits,'outcomes'=>$outcomes,'versions'=>1];
    } finally { $f->close(); }
}]);
