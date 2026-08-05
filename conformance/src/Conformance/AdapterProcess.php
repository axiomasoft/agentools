<?php

declare(strict_types=1);

namespace AgenTools\Conformance;

use RuntimeException;

/** Единственная точка запуска процесса: stdout остаётся только протоколом. */
final class AdapterProcess
{
    private $process;
    /** @var array<int, resource> */
    private array $pipes = [];
    private string $stderr = '';
    private string $stdout = '';

    /** @param list<string> $command */
    public function __construct(array $command)
    {
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        if (!is_resource($this->process)) {
            throw new RuntimeException('не удалось запустить адаптер');
        }
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    public function send(array $message): void
    {
        $encoded = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($this->pipes[0], $encoded) !== strlen($encoded)) {
            throw new SutFailure('адаптер закрыл stdin до получения сообщения');
        }
        fflush($this->pipes[0]);
    }

    /** @return array<string,mixed> */
    public function receive(float $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (($newline = strpos($this->stdout, "\n")) !== false) {
                $line = substr($this->stdout, 0, $newline);
                $this->stdout = substr($this->stdout, $newline + 1);
                try {
                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new ProtocolViolation('stdout адаптера содержит невалидный JSON: ' . $e->getMessage());
                }
                if (!is_array($decoded)) {
                    throw new ProtocolViolation('stdout адаптера содержит не объект JSON');
                }
                return $decoded;
            }
            $remaining = max(0, $deadline - microtime(true));
            $seconds = (int) $remaining;
            $micros = (int) (($remaining - $seconds) * 1_000_000);
            $read = [$this->pipes[1], $this->pipes[2]];
            $write = null;
            $except = null;
            $ready = stream_select($read, $write, $except, $seconds, $micros);
            if ($ready === false) {
                throw new RuntimeException('не удалось читать stdout/stderr адаптера');
            }
            foreach ($read as $stream) {
                $chunk = stream_get_contents($stream);
                if ($stream === $this->pipes[2]) {
                    $this->stderr .= $chunk;
                } else {
                    $this->stdout .= $chunk;
                }
            }
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                throw new SutFailure('адаптер завершился до ответа' . $this->stderrSuffix());
            }
        }
        throw new SutFailure('тайм-аут ответа адаптера' . $this->stderrSuffix());
    }

    public function stop(): void
    {
        if (is_resource($this->pipes[0])) {
            fclose($this->pipes[0]);
        }
        foreach ([1, 2] as $fd) {
            if (isset($this->pipes[$fd]) && is_resource($this->pipes[$fd])) {
                $this->stderr .= stream_get_contents($this->pipes[$fd]);
                fclose($this->pipes[$fd]);
            }
        }
        if (is_resource($this->process)) {
            proc_close($this->process);
        }
    }

    public function stderr(): string
    {
        return $this->stderr;
    }

    private function stderrSuffix(): string
    {
        return $this->stderr === '' ? '' : '; stderr: ' . trim($this->stderr);
    }
}
