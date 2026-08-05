#!/usr/bin/env php
<?php

declare(strict_types=1);

fwrite(STDERR, "fixture crash\n");
exit(17);
