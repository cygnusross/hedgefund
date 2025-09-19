<?php

declare(strict_types=1);

namespace App\Support\Clock;

use DateTimeImmutable;

interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
