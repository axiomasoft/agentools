<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Observability;

interface MetricSink
{
    public function observe(Metric $metric): void;
    public function reset(): void;
}
