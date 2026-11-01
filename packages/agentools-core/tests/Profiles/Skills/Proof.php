<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Profiles\Skills;

final class Proof
{
    /**
     * @var array<string, array<string, mixed>>
     */ public static array $observations=[];
    /**
     * @param array<string, \Closure(): void> $tests
     */
    public static function run(array $tests): never
    {
        $cases=[]; $failures=0;
        foreach ($tests as $id=>$test) {
            try { $test(); $cases[]=['id'=>$id,'status'=>'passed']; }
            catch (\Throwable $error) { ++$failures; $cases[]=['id'=>$id,'status'=>'failed']; fwrite(STDERR,$id.': '.$error::class.': '.$error->getMessage().PHP_EOL); }
        }
        echo json_encode(['mode'=>'contract-scripted','tier'=>'contract-scripted','cases'=>$cases,'count'=>count($cases),
            'failures'=>$failures,'skips'=>0,'observations'=>self::$observations],JSON_THROW_ON_ERROR).PHP_EOL;
        exit($cases !== [] && $failures === 0 ? 0 : 1);
    }
}
