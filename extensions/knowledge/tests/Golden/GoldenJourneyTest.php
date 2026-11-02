<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Golden;

use AgenToolsPlatform\Knowledge\Dependencies\Node;
use PHPUnit\Framework\TestCase;

final class GoldenJourneyTest extends TestCase
{
    use Fixture;
    private function prove(string $scenario): void
    {
        $j=$this->journey($scenario);$r=$j->ingest();$j->prepare();self::assertSame('success',$j->evidence->status);
        self::assertSame('CACHE_HIT',$j->retrieval->retrieve($j->query)->code);
        self::assertSame('complete',$j->run());self::assertSame('delivered',$j->deliver());
        $body=$this->response($j);self::assertSame('evidence',$body['status']);self::assertNotEmpty($body['citations']);
        self::assertStringContainsString($scenario==='support' ? '30 minutes' : '6 N',$body['answer']);
        self::assertSame(array_column($j->selectedEvidence(),'citation'),$body['citations']);
        $pages=$j->document->load($r->material)->pages;
        foreach ($j->selectedEvidence() as $fragment) {
            $c=$fragment['citation'];self::assertSame($r->hash(),$c['release_hash']);self::assertSame('v1',$c['version']);
            self::assertSame(substr($pages[$c['page']-1],$c['start'],$c['end']-$c['start']),$fragment['text']);
            self::assertSame(hash('sha256',$fragment['text']),$c['content_hash']);self::assertSame([0.1,0.1,0.9,0.9],$fragment['box']);
        }
        foreach (['runs','responses','usage_versions','invocation_intents','knowledge_demo_calls','demo_messages'] as $table) { self::assertSame(1,$this->countRows($table),$table); }
        self::assertSame(2,$this->countRows('knowledge_demo_index'));
        self::assertSame('completed',$j->run());self::assertSame('delivered',$j->deliver());self::assertSame(1,$this->countRows('knowledge_demo_calls'));
        self::assertSame('insufficient_evidence',$j->validator->qualification()['educational_qualification']);
        $this->observation($scenario,['runtime'=>'AdvanceRun','provider_calls'=>1,'responses'=>1,'usage_versions'=>1,'index'=>'postgresql-tsvector-gin',
            'citations'=>$body['citations'],'source_hash'=>$r->material->sourceHash,'release_hash'=>$r->hash(),'snapshot_hash'=>$j->compiled->snapshot->contentHash->value,
            'educational_qualification'=>'insufficient_evidence']);
    }
    public function testSupportIngestIndexRuntimeCitationAndReplay(): void { $this->prove('support'); }
    public function testEducationFormulaUnitsCoordinatesAndCitation(): void { $this->prove('education'); }
    public function testMissingGoldCannotPublishDomainGatedRelease(): void
    {
        $j=$this->journey('education',gold:false);
        try { $j->ingest();self::fail('Missing gold published a release'); } catch (\DomainException) { self::assertTrue(true); }
        self::assertSame(0,$this->countRows('knowledge_releases'));self::assertSame(0,$this->countRows('knowledge_demo_index'));
        self::assertSame(0,$this->countRows('knowledge_demo_calls'));
    }
}
