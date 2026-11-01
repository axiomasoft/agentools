<?php

declare(strict_types=1);

require_once dirname(__DIR__,3).'/examples/platform/read-journey/bootstrap.php';
use AgenToolsPlatform\Examples\ReadJourney\SupportJourney;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
$database=new IsolatedDatabase();$journey=new SupportJourney($database,$argv[1]??throw new RuntimeException('Schema required'));
$target=$argv[2]??'';
$journey->barrier=static function(string $name)use($target):void{
    if($name===$target){fwrite(STDOUT,'boundary:'.$name.PHP_EOL);fflush(STDOUT);$deadline=hrtime(true)+30_000_000_000;
        while(hrtime(true)<$deadline){usleep(10000);}throw new RuntimeException('Parent did not kill worker');}
};
$journey->accept();$journey->drive();$journey->delivery->deliver();
