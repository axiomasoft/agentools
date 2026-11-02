<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Golden;

use AgenToolsPlatform\Conformance\Knowledge\ScriptedGold;
use AgenToolsPlatform\Examples\Knowledge\{FixtureDocument,HostValidator,ScriptedExtractor};
use AgenToolsPlatform\Knowledge\{Artifact,Extraction,GenericValidator,Material,SourceDocument};
use PHPUnit\Framework\TestCase;

final class HostValidationTest extends TestCase
{
    private function fixture(): FixtureDocument { return new FixtureDocument(dirname(__DIR__,4).'/conformance/platform/knowledge/fixtures/education.json'); }
    private function gold(): ScriptedGold { return new ScriptedGold(dirname(__DIR__,4).'/conformance/platform/knowledge/fixtures/gold.json','education'); }
    public function testFormulaAndUnitsAreHostOwned(): void
    {
        $f=$this->fixture();$original=$f->material('golden','v1');$source=$f->load($original);
        foreach (['6 N'=>'5 N','kg·m/s²'=>'kg/s'] as $from=>$to) {
            $pages=array_map(fn($p)=>str_replace($from,$to,$p),$source->pages);$bytes=implode("\n\f\n",$pages);
            $m=new Material(...array_replace(get_object_vars($original),['sourceHash'=>hash('sha256',$bytes)]));
            $bad=new SourceDocument($m->bytesRef,$bytes,$pages);$extraction=(new ScriptedExtractor())->extract($m,$bad);
            self::assertSame('validated',(new GenericValidator())->validate($m,$bad,$extraction)->status);
            self::assertSame('failed',(new HostValidator($this->gold()))->validate($m,$bad,$extraction)->status);
        }
        self::assertSame('insufficient_evidence',(new HostValidator($this->gold()))->qualification()['educational_qualification']);
    }
    public function testNonSyntheticOrPersonalFixtureIsRejected(): void
    {
        foreach ([['synthetic'=>false,'personal_data'=>false],['synthetic'=>true,'personal_data'=>true]] as $flags) {
            $path=tempnam(sys_get_temp_dir(),'knowledge_flag_');
            if ($path===false) { throw new \RuntimeException('Temporary fixture unavailable'); }
            try {
                file_put_contents($path,json_encode(['format'=>'synthetic-knowledge-fixture/v1']+$flags,JSON_THROW_ON_ERROR));
                try { new FixtureDocument($path);self::fail('Unqualified fixture accepted'); }
                catch (\DomainException) { self::assertTrue(true); }
            } finally { unlink($path); }
        }
    }
    public function testMissingAndUnavailableGoldStayUnknown(): void
    {
        $f=$this->fixture();$m=$f->material('golden','v1');$s=$f->load($m);$e=(new ScriptedExtractor())->extract($m,$s);
        self::assertSame('validated',(new GenericValidator())->validate($m,$s,$e)->status);
        self::assertSame('unknown',(new HostValidator(null))->validate($m,$s,$e)->status);
        $gold=$this->gold();$gold->unavailable=true;self::assertSame('unknown',(new HostValidator($gold))->validate($m,$s,$e)->status);
    }
    public function testTechnicalCoordinatesHashesAndUtf8CannotBeGoldOverridden(): void
    {
        $f=$this->fixture();$m=$f->material('golden','v1');$s=$f->load($m);$e=(new ScriptedExtractor())->extract($m,$s);$a=$e->artifacts;
        $fragment=$a[1];$start=strpos($s->pages[0],'×')+1;
        $a[1]=new Artifact(...array_replace(get_object_vars($fragment),['start'=>$start]));
        $result=(new GenericValidator())->validate($m,$s,new Extraction($e->extractorVersion,$a));
        self::assertSame('failed',$result->status);self::assertContains('encoding_boundary',$result->errors);
        $wrong=new SourceDocument($s->bytesRef,'different bytes',$s->pages);
        self::assertContains('source_mapping',(new GenericValidator())->validate($m,$wrong,$e)->errors);
    }
}
