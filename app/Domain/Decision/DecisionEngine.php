<?php

namespace App\Domain\Decision;

use App\Domain\Rules\AlphaRules;

final class DecisionEngine
{
    /**
     * Decide an action based on the provided context and rules.
     *
     * For now this is a stub that always returns 'hold'.
     */
    public function decide(mixed $context, AlphaRules $rules): array
    {
        // Accept DecisionContext, array, or any object with toArray()
        if ($context instanceof DecisionContext) {
            $ctx = $context->toArray();
        } elseif (is_array($context)) {
            $ctx = $context;
        } elseif (is_object($context) && method_exists($context, 'toArray')) {
            $ctx = $context->toArray();
        } else {
            // Unknown shape — be safe and hold
            return ['action' => 'hold', 'confidence' => 0.0, 'reason' => 'invalid_context'];
        }

        // 1) Market status gate
        $requiredStatuses = $rules->getGate('market_required_status', ['TRADEABLE']);
        $marketStatus = $ctx['market']['status'] ?? null;
        if (! is_array($requiredStatuses)) {
            // allow single string
            $requiredStatuses = [$requiredStatuses];
        }
        if ($marketStatus === null || ! in_array($marketStatus, $requiredStatuses, true)) {
            return ['action' => 'hold', 'confidence' => 0.0, 'reason' => 'status_closed'];
        }

        // 2) Spread presence gate (optional)
        $spreadRequired = (bool) $rules->getGate('spread_required', false);
        if ($spreadRequired) {
            $spread = $ctx['market']['spread_estimate_pips'] ?? null;
            if ($spread === null) {
                return ['action' => 'hold', 'confidence' => 0.0, 'reason' => 'no_spread'];
            }
        }

        // 3) Data freshness
        $maxDataAge = (int) $rules->getGate('max_data_age_sec', 600);
        $dataAge = $ctx['meta']['data_age_sec'] ?? null;
        // Require data age to be present and within allowed threshold
        if ($dataAge === null || $dataAge > $maxDataAge) {
            return ['action' => 'hold', 'confidence' => 0.0, 'reason' => 'stale_data'];
        }

        // 4) Calendar blackout
        $withinBlackout = $ctx['calendar']['within_blackout'] ?? $ctx['blackout'] ?? false;
        if ($withinBlackout) {
            return ['action' => 'hold', 'confidence' => 0.0, 'reason' => 'blackout'];
        }

        // All safety gates passed for now — still default to hold until decision logic implemented
        return ['action' => 'hold', 'confidence' => 0.0, 'reason' => 'ok'];
    }
}
