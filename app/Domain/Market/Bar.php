<?php

namespace App\Domain\Market;

final class Bar
{
    public readonly \DateTimeImmutable $ts;

    public function __construct(
        \DateTimeImmutable $ts,
        public readonly float $open,
        public readonly float $high,
        public readonly float $low,
        public readonly float $close,
        public readonly ?float $volume = null,
    ) {
        // ensure UTC before assigning readonly property
        if ($ts->getTimezone()->getName() !== 'UTC') {
            $ts = $ts->setTimezone(new \DateTimeZone('UTC'));
        }

        $this->ts = $ts;
    }
}
