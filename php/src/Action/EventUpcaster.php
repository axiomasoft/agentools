<?php

declare(strict_types=1);

namespace AgenTools\Action;

interface EventUpcaster
{
    public function supports(ActionEvent $event): bool;

    public function upcast(ActionEvent $event): ActionEvent;
}
