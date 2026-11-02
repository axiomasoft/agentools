<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Golden;

use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Knowledge\Decision;
use AgenToolsPlatform\Knowledge\Dependencies\Node;
use PHPUnit\Framework\TestCase;

final class CorrectionTest extends TestCase
{
    use Fixture;
    private function proveCorrection(string $scenario): void
    {
        $j=$this->journey($scenario);$old=$j->ingest();$j->prepare();self::assertSame('complete',$j->run());
        $before=$this->response($j);$hash=$j->compiled->snapshot->contentHash->value;$next=$j->ingest('v2',$old);
        self::assertSame('denied',$j->retrieval->retrieve($j->query)->status);
        self::assertSame(Decision::Denied,$j->graph->usable($j->scope,$j->snapshotNode));
        try { $j->deliver();self::fail('Corrected source disclosed'); } catch (ContractViolation) { self::assertTrue(true); }
        self::assertSame(0,$this->countRows('demo_messages'));self::assertSame($before,$this->response($j));self::assertSame($hash,$j->compiled->snapshot->contentHash->value);
        $markers=$j->graph->markers($j->scope,$j->host->runId->value);self::assertSame('source_corrected',$markers[0]['reason']);
        $new=$this->journey($scenario,key:'corrected');$new->release=$next;
        $new->prepare();self::assertSame('complete',$new->run());self::assertSame('delivered',$new->deliver());
        $body=$this->response($new);self::assertStringContainsString($scenario==='support' ? '10 minutes' : '8 N',$body['answer']);
        self::assertSame('v2',$body['citations'][0]['version']);self::assertSame($next->hash(),$body['citations'][0]['release_hash']);
        self::assertSame(2,$this->countRows('knowledge_demo_calls'));self::assertSame(2,$this->countRows('usage_versions'));
        $this->observation($scenario==='support' ? 'correction' : 'education-correction',['old_run'=>$j->host->runId->value,'new_run'=>$new->host->runId->value,'historical_unchanged'=>true,
            'old_snapshot_denied'=>true,'marker'=>$markers[0]['reason'],'new_version'=>$body['citations'][0]['version']]);
    }
    public function testCorrectionInvalidatesCacheSnapshotAndBufferedDisclosure(): void { $this->proveCorrection('support'); }
    public function testEducationCorrectionRetainsOldFactAndCitesNewFormula(): void { $this->proveCorrection('education'); }
    public function testRevokeAtDeliveryBoundaryDoesNotSendOrConsumeAttempt(): void
    {
        $j=$this->journey();$old=$j->ingest();$j->prepare();self::assertSame('complete',$j->run());
        $j->host->barrier=function(string $boundary) use ($j,$old): void {
            if ($boundary==='delivery') { $j->repository->invalidate($j->scope,$old->material,'source_revoked'); }
        };
        try { $j->deliver();self::fail('Revoked boundary sent'); } catch (ContractViolation) { self::assertTrue(true); }
        self::assertSame(0,$this->countRows('demo_messages'));
        self::assertSame(0,(int)$this->database->pdo->query('SELECT coalesce(sum(attempts),0) FROM demo_delivery_attempts')->fetchColumn());
    }
}
