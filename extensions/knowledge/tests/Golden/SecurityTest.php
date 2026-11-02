<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Golden;

use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Knowledge\Decision;
use AgenToolsPlatform\Knowledge\Retrieval\{Query,ReleasePin};
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    use Fixture;
    public function testTenantAudienceAndUnknownWinOverCache(): void
    {
        $a=$this->journey();$own=$a->ingest();$b=$this->journey('education');$foreign=$b->ingest();
        $query=new Query($a->scope,$a->host->runId->value,'force',[
            new ReleasePin($own->corpus,$own->id,$own->hash(),$own->material,[]),
            new ReleasePin($foreign->corpus,$foreign->id,$foreign->hash(),$foreign->material,[])],'en');
        $selection=$a->retrieval->retrieve($query);$result=$a->retrieval->disclose($query,$selection);
        self::assertSame('empty',$result->status);self::assertSame([],$result->fragments);
        $a->prepare();self::assertSame('CACHE_HIT',$a->retrieval->retrieve($a->query)->code);
        $a->owner->sourceDecision=Decision::Unknown;
        self::assertSame('unknown',$a->retrieval->disclose($a->query,$a->selection)->status);
        try { $a->run();self::fail('Unknown source permitted invocation'); } catch (ContractViolation) { self::assertTrue(true); }
        self::assertSame(0,$this->countRows('knowledge_demo_calls'));
        $a->owner->sourceDecision=Decision::Allowed;
        $a->host->db->execute('UPDATE '.$a->host->db->table('demo_permissions').' SET emergency=true WHERE tenant_id=:tenant',['tenant'=>$a->scope->tenant]);
        try { $a->run();self::fail('Emergency bypassed pin'); } catch (ContractViolation) { self::assertTrue(true); }
    }
    public function testIndexIdentityAndForeignPartitionAreRejected(): void
    {
        $a=$this->journey();$old=$a->ingest();$a->prepare();
        $material=$a->document->material($a->scope->tenant,'v2');$source=$a->document->load($material);
        $forged=\AgenToolsPlatform\Knowledge\Release::validate($old->corpus,$old->id,$material,$source,
            (new \AgenToolsPlatform\Examples\Knowledge\ScriptedExtractor())->extract($material,$source),new \AgenToolsPlatform\Knowledge\GenericValidator());
        try { $a->index->add($forged);self::fail('Index identity substituted'); } catch (\DomainException) { self::assertTrue(true); }
        $b=$this->journey('education');$foreign=$b->ingest();
        try { $a->index->search($a->query,['foreign'=>$foreign]);self::fail('Foreign index partition searched'); } catch (\DomainException) { self::assertTrue(true); }
        self::assertSame(4,$this->countRows('knowledge_demo_index'));
    }
    public function testNoEvidenceDoesNotFabricateCitation(): void
    {
        $j=$this->journey();$j->ingest();$j->prepare('unobtainium');self::assertSame('empty',$j->evidence->status);
        self::assertSame('complete',$j->run());self::assertSame('delivered',$j->deliver());$body=$this->response($j);
        self::assertSame('no_evidence',$body['status']);self::assertSame([],$body['citations']);
        self::assertStringNotContainsString('30 minutes',$body['answer']);
    }
    public function testBudgetPrunesEvidencePreservingPolicyAndInput(): void
    {
        $j=$this->journey('education');$j->ingest();$j->prepare(window:700);
        $manifest=json_decode($j->compiled->manifestJson,true,64,JSON_THROW_ON_ERROR);
        $kinds=array_column($manifest['sections'],'kind');self::assertContains('policy',$kinds);self::assertContains('input',$kinds);
        self::assertNotContains('evidence',$kinds);self::assertNotEmpty($manifest['exclusions']);
        self::assertLessThanOrEqual($manifest['available_tokens'],$manifest['used_tokens']);
        self::assertSame('complete',$j->run());$body=$this->response($j);self::assertSame([],$body['citations']);
    }
}
