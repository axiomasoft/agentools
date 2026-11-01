<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Budget\{Admission,AdmissionRequest};

final readonly class BudgetFact { public function __construct(public AdmissionRequest $request,public Admission $outcome) {} }
