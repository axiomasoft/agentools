<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
try {
    $options=getopt('',['offline','live','host-bootstrap:']);
    if (isset($options['live'])) {
        if (isset($options['offline']) || !isset($options['host-bootstrap']) || !is_string($options['host-bootstrap'])) { throw new LogicException('Live probe needs one explicit trusted host bootstrap'); }
        // Operator-owned code, never a class/endpoint selected by model data or a web request.
        $bound=require $options['host-bootstrap'];
        if (!is_array($bound) || !($bound['harness']??null) instanceof \AgenToolsPlatform\Testing\Providers\OptInHarness
            || !($bound['request']??null) instanceof \AgenToolsPlatform\Core\Models\ModelRequest
            || !($bound['deployment']??null) instanceof \AgenToolsPlatform\Core\Models\Deployment) { throw new LogicException('Required host probe bindings missing'); }
        if (($bound['mode']??'generate')==='stream') { $bound['harness']->stream($bound['request'],$bound['deployment']); }
        else { $bound['harness']->generate($bound['request'],$bound['deployment']); }
        echo json_encode(['mode'=>'explicit-opt-in-probe','result'=>$bound['harness']->report(),'qualification_claim'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
    } else {
        $profile=json_decode(file_get_contents(__DIR__.'/profiles.json'),true,flags:JSON_THROW_ON_ERROR);
        echo json_encode(['mode'=>'offline','provider_calls'=>0,'capability_profile'=>$profile,'live'=>$profile['live'],'qualification_claim'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable) { fwrite(STDERR,"PROVIDER_PROBE_REFUSED_OR_UNKNOWN; inspect host technical accounting/status, never blindly retry\n"); exit(1); }
