<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Conformance\Providers;
final class Proof
{
    /** @param array<string,\Closure():void> $tests */
    public static function run(array $tests): never
    {
        $rows=[]; $failures=0;
        foreach ($tests as $id=>$test) {
            try { $test(); $rows[]=['id'=>$id,'status'=>'passed']; }
            catch (\Throwable $e) { ++$failures; $rows[]=['id'=>$id,'status'=>'failed']; fwrite(STDERR,$id.': '.$e::class.': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL); }
        }
        echo json_encode(['mode'=>'contract-scripted','tier'=>'contract-scripted','cases'=>$rows,'count'=>count($rows),'failures'=>$failures,'skips'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
        exit($rows!==[] && $failures===0?0:1);
    }
}
