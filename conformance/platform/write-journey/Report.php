<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Conformance\WriteJourney;

final class Report
{
    /** @param array<string,mixed> $facts */
    public static function record(string $name,array $facts): void
    {
        $directory=getenv('AGENTOOLS_WRITE_EVIDENCE');
        if (!is_string($directory) || !is_dir($directory) || preg_match('/^[a-z-]+$/D',$name)!==1) { throw new \RuntimeException('Required evidence directory unavailable'); }
        if (file_put_contents($directory.'/'.$name.'.json',json_encode($facts,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n")===false) { throw new \RuntimeException('Cannot save required case evidence'); }
    }
}
