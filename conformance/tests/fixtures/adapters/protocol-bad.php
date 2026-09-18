#!/usr/bin/env php
<?php

declare(strict_types=1);

while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if (($message['type'] ?? '') === 'start') {
        echo "это не json\n";
        exit(0);
    }
}
