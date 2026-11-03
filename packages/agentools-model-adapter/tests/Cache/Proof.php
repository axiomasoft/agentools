<?php
declare(strict_types=1);
namespace AgenToolsPlatform\ModelAdapter\Tests\Cache;
final class Proof
{
    /** @var array<string,mixed> */ public static array $observations=[];
    /** @param array<string,\Closure():void> $tests */
    public static function run(array $tests,bool $postgres=false): never
    {
        $isolation=$postgres?(new \AgenToolsPlatform\Postgres\Tests\IsolatedDatabase())->verify():null; $rows=[]; $failures=0;
        foreach ($tests as $id=>$test) {
            try { $test(); $rows[]=['id'=>$id,'status'=>'passed']; }
            catch (\Throwable $e) { ++$failures; $rows[]=['id'=>$id,'status'=>'failed']; fwrite(STDERR,$id.': '.$e::class.': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL); }
        }
        if ($isolation!==null) { $isolation['case_ids']=array_keys($tests); } $tier=$postgres?'isolated-postgres':'contract-scripted';
        echo json_encode(['mode'=>$tier,'tier'=>$tier,'cases'=>$rows,'count'=>count($rows),'failures'=>$failures,'skips'=>0,
            'isolation'=>$isolation,'observations'=>(object)self::$observations],JSON_THROW_ON_ERROR).PHP_EOL;
        exit($rows!==[] && $failures===0?0:1);
    }
}
