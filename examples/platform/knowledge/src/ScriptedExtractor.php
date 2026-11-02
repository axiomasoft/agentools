<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Knowledge;

use AgenToolsPlatform\Knowledge\{Artifact,Extraction,Extractor,Material,SourceDocument};

/** Deterministic canonical UTF-8 byte coordinates and normalized boxes; no engine qualification. */
final readonly class ScriptedExtractor implements Extractor
{
    public function extract(Material $material, SourceDocument $source): Extraction
    {
        $artifacts=[];
        foreach ($source->pages as $i=>$text) {
            $page=$i+1;$hash=hash('sha256',$text);$node='node:'.$page;
            foreach (['node','fragment','navigation','card'] as $kind) {
                $body=in_array($kind,['node','fragment'],true) ? $text : ($kind==='navigation' ? 'Synthetic page '.$page : 'Synthetic source card '.$page);
                $artifacts[]=new Artifact($kind.':'.$page,$kind,$page,0,strlen($text),$body,$material->sourceHash,$hash,hash('sha256',$body),
                    $kind==='node' ? null : $node,[0.1,0.1,0.9,0.9]);
            }
        }
        return new Extraction('scripted-coordinate-extractor/v1',$artifacts);
    }
}
