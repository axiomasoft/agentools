<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Concurrency;

/** Real processes pause between committed claims and attempted checkpoint/receipt writes. */
final class BarrierWorker
{
    /** @var resource */
    private $process;
    /** @var array<int, resource> */
    private array $pipes;
    private bool $closed = false;

    public function __construct(string $schema, string $mode)
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/worker.php', $schema, $mode],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Worker unavailable');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_timeout($pipes[1], 10);
    }

    public function line(): string
    {
        $line = fgets($this->pipes[1]);
        if ($line === false) {
            throw new \RuntimeException('Worker barrier failed: ' . stream_get_contents($this->pipes[2]));
        }
        return trim($line);
    }

    public function go(): void
    {
        fwrite($this->pipes[0], "go\n");
        fflush($this->pipes[0]);
    }

    /** @return array{exit: int, stdout: string, stderr: string} */
    public function finish(): array
    {
        fclose($this->pipes[0]);
        $stdout = stream_get_contents($this->pipes[1]);
        $stderr = stream_get_contents($this->pipes[2]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        $exit = proc_close($this->process);
        $this->closed = true;
        return ['exit' => $exit, 'stdout' => $stdout === false ? '' : $stdout, 'stderr' => $stderr === false ? '' : $stderr];
    }

    public function __destruct()
    {
        if (!$this->closed) {
            proc_terminate($this->process);
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
        }
    }
}
