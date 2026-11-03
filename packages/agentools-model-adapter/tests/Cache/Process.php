<?php
declare(strict_types=1);
namespace AgenToolsPlatform\ModelAdapter\Tests\Cache;
final class Process
{
    /** @var resource */ private $process;
    /** @var array<int,resource> */ private array $pipes;
    public int $backend;
    public function __construct(string $schema,string $mode)
    {
        $pipes=[]; $p=proc_open([PHP_BINARY,__DIR__.'/worker.php',$schema,$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($p)) { throw new \RuntimeException('Cache worker failed to start'); }
        $this->process=$p; $this->pipes=$pipes; stream_set_timeout($pipes[1],15); $this->backend=$this->line()['backend'];
    }
    public function go(): void { fwrite($this->pipes[0],"GO\n"); fflush($this->pipes[0]); }
    /** @return array<string,mixed> */
    public function line(): array
    {
        $line=fgets($this->pipes[1]); if ($line===false) { throw new \RuntimeException('Worker output missing: '.stream_get_contents($this->pipes[2])); }
        return json_decode($line,true,flags:JSON_THROW_ON_ERROR);
    }
    /** @return array<string,mixed> */
    public function kill(): array
    {
        if (!proc_terminate($this->process,9)) { throw new \RuntimeException('SIGKILL refused'); }
        $end=microtime(true)+5;
        do { $s=proc_get_status($this->process); if (!$s['running']) { break; } usleep(1000); } while (microtime(true)<$end);
        if ($s['running']) { throw new \RuntimeException('Cache worker survived SIGKILL'); }
        foreach ($this->pipes as $p) { fclose($p); } proc_close($this->process);
        return ['signal'=>$s['termsig'],'signaled'=>$s['signaled'],'exit'=>$s['exitcode']];
    }
    public function finish(): int
    { $err=stream_get_contents($this->pipes[2]); foreach ($this->pipes as $p) { fclose($p); } $exit=proc_close($this->process); if ($err!=='') { throw new \RuntimeException($err); } return $exit; }
}
