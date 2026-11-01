<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Summaries;

final class Proof
{
    /** @var array<string,mixed> */ public static array $observations=[];
    /** @param array<string,\Closure(): void> $tests */
    public static function run(array $tests): never
    {
        $database=new \AgenToolsPlatform\Postgres\Tests\IsolatedDatabase();$isolation=$database->verify();$cases=[];$failures=0;
        foreach ($tests as $id=>$test) {
            try { $test();$cases[]=['id'=>$id,'status'=>'passed']; }
            catch (\Throwable $error) { ++$failures;$cases[]=['id'=>$id,'status'=>'failed'];fwrite(STDERR,$id.': '.$error::class.': '.$error->getMessage().PHP_EOL); }
        }
        $isolation['case_ids']=array_column($cases,'id');
        echo json_encode(['mode'=>'isolated-postgres','tier'=>'isolated-postgres','cases'=>$cases,'count'=>count($cases),'failures'=>$failures,'skips'=>0,'isolation'=>$isolation,'observations'=>self::$observations],JSON_THROW_ON_ERROR).PHP_EOL;
        exit($tests!==[] && $failures===0?0:1);
    }
}
