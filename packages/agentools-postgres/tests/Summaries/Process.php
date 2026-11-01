<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Summaries;

use AgenToolsPlatform\Tests\Context\Fixture as Values;

final class Process
{
    /** @var resource */ private $process;
    /** @var array<int,resource> */ private array $pipes;
    public int $backend;
    public function __construct(string $schema,string $mode='append',string $text='baseline')
    {
        $pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/worker.php',$schema,$mode,$text],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Worker could not start'); }$this->process=$process;$this->pipes=$pipes;
        stream_set_timeout($this->pipes[1],10);$ready=$this->line();$this->backend=Values::number($ready['backend']);
    }
    public function go(): void { if (fwrite($this->pipes[0],"GO\n")===false) { throw new \RuntimeException('Barrier write failed'); }fflush($this->pipes[0]); }
    /** @return array<string,mixed> */
    public function line(): array
    {
        $line=fgets($this->pipes[1]);if ($line===false) { throw new \RuntimeException('Worker output missing: '.stream_get_contents($this->pipes[2])); }
        return Values::object(json_decode($line,true,32,JSON_THROW_ON_ERROR));
    }
    /** @return array<string,mixed> */
    public function kill(): array
    {
        if (!proc_terminate($this->process,9)) { throw new \RuntimeException('SIGKILL failed'); }
        $deadline=microtime(true)+5;
        do { $status=proc_get_status($this->process);if (!$status['running']) { break; }usleep(10000); } while (microtime(true)<$deadline);
        if ($status['running']) { throw new \RuntimeException('Killed worker still running'); }
        foreach ($this->pipes as $pipe) { fclose($pipe); }proc_close($this->process);
        return ['signaled'=>$status['signaled'],'signal'=>$status['termsig'],'exit'=>$status['exitcode']];
    }
    public function finish(): int
    {
        $errors=stream_get_contents($this->pipes[2]);foreach ($this->pipes as $pipe) { fclose($pipe); }$exit=proc_close($this->process);
        if ($errors!==false && $errors!=='') { throw new \RuntimeException('Worker stderr: '.$errors); }return $exit;
    }
}
