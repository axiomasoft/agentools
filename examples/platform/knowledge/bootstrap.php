<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/read-journey/bootstrap.php';
$knowledgeRoot=dirname(__DIR__,3);
spl_autoload_register(static function(string $class) use ($knowledgeRoot): void {
    foreach (['AgenToolsPlatform\\Knowledge\\'=>'/extensions/knowledge/src/',
        'AgenToolsPlatform\\Examples\\Knowledge\\'=>'/examples/platform/knowledge/src/'] as $prefix=>$directory) {
        if (str_starts_with($class,$prefix)) {
            $path=$knowledgeRoot.$directory.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
            if (is_file($path)) { require_once $path; }
        }
    }
},true,true);
