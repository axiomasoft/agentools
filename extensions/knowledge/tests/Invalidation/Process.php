<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Invalidation;

final class Process
{
    public $process;
    public array $pipes;
    public function __construct(string $mode, string $schema, string $id = 'event:1', string $version = 'v1')
    {
        $this->process = proc_open([PHP_BINARY, __DIR__ . '/worker.php', $mode, $schema, $id, $version],
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($this->process)) { throw new \RuntimeException('Worker start failed'); }
        $this->pipes = $pipes;
        stream_set_timeout($this->pipes[1], 15);
    }
    public function line(): string
    {
        $line = fgets($this->pipes[1]);
        if ($line === false) { throw new \RuntimeException('Worker pipe failed: ' . stream_get_contents($this->pipes[2])); }
        return trim($line);
    }
    public function go(): void { fwrite($this->pipes[0], "go\n"); fflush($this->pipes[0]); }
    public function finish(): int
    {
        foreach ($this->pipes as $pipe) { fclose($pipe); }
        return proc_close($this->process);
    }
    public function kill(): array
    {
        if (!proc_terminate($this->process, 9)) { throw new \RuntimeException('SIGKILL failed'); }
        for ($attempt=0; $attempt<500; ++$attempt) {
            $status = proc_get_status($this->process);
            if (!$status['running']) { $this->finish(); return $status; }
            usleep(10000);
        }
        throw new \RuntimeException('Worker did not stop');
    }
    public function __destruct()
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            foreach ($this->pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            proc_close($this->process);
        }
    }
}
