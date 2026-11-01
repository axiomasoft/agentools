<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload.php';
// Source-owned upstream bootstrap prevents stale mirrored path packages being the subject.
require_once dirname(__DIR__,3).'/packages/agentools-laravel/tests/Composition/bootstrap.php';
require_once __DIR__.'/Fixture.php';
require_once dirname(__DIR__,3).'/packages/agentools-core/tests/Common/JcsAdapter.php';
require_once dirname(__DIR__,3).'/packages/agentools-core/tests/Profiles/Fixture.php';
require_once dirname(__DIR__,3).'/packages/agentools-core/tests/Profiles/MergeFixture.php';
require_once dirname(__DIR__,3).'/packages/agentools-postgres/tests/Profiles/Fixture.php';
require_once __DIR__.'/ProfileFixture.php';
