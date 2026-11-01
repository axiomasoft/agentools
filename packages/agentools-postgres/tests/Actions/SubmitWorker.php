<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Actions;

/** Test process with a readiness barrier and explicit SIGKILL for recovery schedules. */
final class SubmitWorker
{
    /** @var resource */
    private $process;
    /** @var array<int,resource> */
    private array $pipes;
    private bool $closed = false;

    public function __construct(string $schema, string $mode)
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/submit-worker.php', $schema, $mode],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Submit worker unavailable'); }
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_timeout($pipes[1], 10);
        if ($this->line() !== 'ready') { throw new \RuntimeException('Submit worker readiness failed'); }
    }

    public function line(): string { return trim((string) fgets($this->pipes[1])); }
    public function go(): void { fwrite($this->pipes[0], "go\n"); fflush($this->pipes[0]); }
    public function kill(): void { proc_terminate($this->process, 9); }

    /** @return array{exit:int,stdout:string,stderr:string} */
    public function finish(): array
    {
        fclose($this->pipes[0]);
        $stdout = stream_get_contents($this->pipes[1]); $stderr = stream_get_contents($this->pipes[2]);
        fclose($this->pipes[1]); fclose($this->pipes[2]);
        $exit = proc_close($this->process); $this->closed = true;
        return ['exit' => $exit, 'stdout' => $stdout === false ? '' : $stdout, 'stderr' => $stderr === false ? '' : $stderr];
    }

    public function __destruct()
    {
        if (!$this->closed) {
            $this->kill();
            foreach ($this->pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            proc_close($this->process);
        }
    }
}
