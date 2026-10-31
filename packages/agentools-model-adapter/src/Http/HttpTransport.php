<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter\Http;

use AgenToolsPlatform\ModelAdapter\IOControl;

/** One bounded request, no redirects/retries. Consumers must exhaust or close its iterator. */
interface HttpTransport
{
    /** @return iterable<HttpEvent> */
    public function exchange(HttpRequest $request, IOControl $control): iterable;
}
