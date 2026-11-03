<?php

declare(strict_types=1);
namespace AgenToolsPlatform\ChatomBridge\Tests;

final class Process
{
    private $process;
    private array $pipes;
    public function __construct(string $mode,string $schema)
    {
        $this->process=proc_open([PHP_BINARY,__DIR__.'/worker.php',$mode,$schema],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($this->process)){throw new \RuntimeException('Worker start failed');}
        $this->pipes=$pipes;stream_set_timeout($this->pipes[1],15);
    }
    public function line():array
    {
        $line=fgets($this->pipes[1]);
        if($line===false){throw new \RuntimeException('Worker failed: '.stream_get_contents($this->pipes[2]));}
        return json_decode($line,true,512,JSON_THROW_ON_ERROR);
    }
    public function go():void {fwrite($this->pipes[0],"go\n");fflush($this->pipes[0]);}
    public function finish():int {foreach($this->pipes as $pipe){fclose($pipe);}return proc_close($this->process);}
    public function kill():array
    {
        if(!proc_terminate($this->process,9)){throw new \RuntimeException('SIGKILL failed');}
        for($i=0;$i<500;++$i){$status=proc_get_status($this->process);if(!$status['running']){
            $exit=$this->finish();return ['signaled'=>$status['signaled'],'signal'=>$status['termsig'],'exit'=>$exit];
        }usleep(10000);}
        throw new \RuntimeException('Worker did not stop');
    }
    public function __destruct()
    {
        if(is_resource($this->process)){proc_terminate($this->process,9);foreach($this->pipes as $pipe){if(is_resource($pipe)){fclose($pipe);}}proc_close($this->process);}
    }
}
