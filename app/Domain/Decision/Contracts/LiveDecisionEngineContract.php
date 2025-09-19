<?php

declare(strict_types=1);

namespace App\Domain\Decision\Contracts;

use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Decision\DTO\DecisionResult;

interface LiveDecisionEngineContract
{
    public function decide(DecisionRequest $request): DecisionResult;
}

