<?php

declare(strict_types=1);

namespace App\Domain\Decision\Contracts;

use App\Domain\Decision\DTO\DecisionMetadata;
use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Market\FeatureSet;

interface DecisionContextContract
{
    public function pair(): string;

    public function timestamp(): \DateTimeImmutable;

    public function features(): FeatureSet;

    public function meta(): DecisionMetadata;

    public function toRequest(): DecisionRequest;

    public function toArray(): array;
}
