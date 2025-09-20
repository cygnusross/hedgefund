<?php

declare(strict_types=1);

namespace App\Domain\Rules\Calibration;

use App\Domain\Rules\ResolvedRules;
use App\Models\RuleSet;

final class CalibrationResult
{
    public function __construct(
        public readonly ?RuleSet $ruleSet,
        public readonly ResolvedRules $resolvedRules,
        public readonly CalibrationConfig $config,
        public readonly array $summary = [],
    ) {}
}
