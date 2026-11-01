<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Profiles;

final class Worker
{
    /** @var resource */
    private $process;
    /** @var array<int, resource> */
    private array $pipes;
    private bool $closed = false;
    public int $backend;

    public function __construct(string $schema, string $mode)
    {
        $p = proc_open([PHP_BINARY, __DIR__ . '/worker.php', $schema, $mode], [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes);
        if (!is_resource($p)) { throw new \RuntimeException('Profile worker unavailable'); }
        $this->process = $p; $this->pipes = $pipes; stream_set_timeout($pipes[1], 10);
        $ready = $this->read();
        if (($ready['ready'] ?? null) !== true || !is_int($ready['pid'] ?? null)) { throw new \RuntimeException('Profile worker not ready'); }
        $this->backend = $ready['pid'];
    }
    public function go(): void { fwrite($this->pipes[0], "go\n"); fflush($this->pipes[0]); }
    /** @return array<string, mixed> */
    public function read(): array
    {
        $line = fgets($this->pipes[1]);
        if ($line === false) { throw new \RuntimeException('Profile worker output missing: ' . stream_get_contents($this->pipes[2])); }
        $data = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new \RuntimeException('Invalid profile worker output'); }
        foreach ($data as $key => $_) { if (!is_string($key)) { throw new \RuntimeException('Object required'); } }
        return $data;
    }
    public function finish(): int
    {
        foreach ($this->pipes as $pipe) { fclose($pipe); }
        $exit = proc_close($this->process); $this->closed = true; return $exit;
    }
    /** @return array{signal: int, signaled: bool, worker_exit: int} */
    public function kill(): array
    {
        if (!proc_terminate($this->process, 9)) { throw new \RuntimeException('SIGKILL failed'); }
        $status = proc_get_status($this->process);
        for ($i = 0; $i < 500 && $status['running']; ++$i) { usleep(10000); $status = proc_get_status($this->process); }
        if ($status['running']) { throw new \RuntimeException('Killed worker still running'); }
        $exit = $this->finish();
        return ['signal' => $status['termsig'], 'signaled' => $status['signaled'], 'worker_exit' => $exit];
    }
    public function __destruct()
    {
        if (!$this->closed) {
            proc_terminate($this->process, 9);
            foreach ($this->pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            proc_close($this->process);
        }
    }
}
