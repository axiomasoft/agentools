<?php

declare(strict_types=1);
namespace AgenToolsPlatform\ChatomBridge\Tests;
require_once __DIR__.'/Fixture.php';

final class PgHarness
{
    public static array $observations=[];
    public static function run(array $tests):never
    {
        $options=getopt('', ['case:']);
        if(isset($options['case'])) {
            $selected=is_array($options['case'])?$options['case']:[$options['case']];
            if($selected===[] || array_diff($selected,array_keys($tests))!==[]) { throw new \InvalidArgumentException('Unknown focused case'); }
            $tests=array_intersect_key($tests,array_fill_keys($selected,true));
        }
        $isolation=(new \AgenToolsPlatform\Postgres\Tests\IsolatedDatabase())->verify();
        $cases=[];$failures=0;
        foreach($tests as $id=>$test) {
            try { $test();$cases[]=['id'=>$id,'status'=>'passed']; }
            catch(\Throwable $error) { ++$failures;$cases[]=['id'=>$id,'status'=>'failed'];fwrite(STDERR,$id.': '.$error::class.': '.$error->getMessage().PHP_EOL); }
        }
        echo json_encode(['mode'=>'isolated-postgres','tier'=>'isolated-postgres','evidence_kind'=>'host_contract_harness',
            'cases'=>$cases,'count'=>count($cases),'failures'=>$failures,'skips'=>0,'isolation'=>$isolation+['case_ids'=>array_keys($tests)],
            'observations'=>self::$observations],JSON_THROW_ON_ERROR).PHP_EOL;
        exit($tests!==[] && $failures===0?0:1);
    }
}
