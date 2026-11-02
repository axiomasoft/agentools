<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Golden;

use PHPUnit\Framework\TestCase;

final class ExampleTest extends TestCase
{
    use Fixture;
    public function testRunnableSyntheticExample(): void
    {
        $process=proc_open([PHP_BINARY,dirname(__DIR__,4).'/examples/platform/knowledge/run.php','support'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Example process missing'); }
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$error);$report=json_decode($out,true,64,JSON_THROW_ON_ERROR);
        self::assertSame('complete',$report['stage']);self::assertSame('delivered',$report['delivery']);
        self::assertNotEmpty($report['fragments']);self::assertSame('insufficient_evidence',$report['qualification']['educational_qualification']);
    }
}
