<?php

namespace App\Services\IG\DTO;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WorkingOrderRequest
{
    /**
     * Validate and normalize a working order request payload according to IG constraints.
     * Returns the normalized payload array (wrapped under 'request' if appropriate).
     *
     * @throws ValidationException
     */
    public static function validate(array $payload): array
    {
        // Accept either ['request' => [...]] or [...]
        $data = array_key_exists('request', $payload) ? $payload['request'] : $payload;

        $rules = [
            'currencyCode' => ['required', 'regex:/^[A-Z]{3}$/'],
            'dealReference' => ['nullable', 'regex:/^[A-Za-z0-9_\-]{1,30}$/'],
            'direction' => ['nullable', 'in:BUY,SELL'],
            'epic' => ['required', 'string'],
            'expiry' => ['required', 'regex:/^(\d{2}-)?[A-Z]{3}-\d{2}$|^-$|^DFB$/'],
            'forceOpen' => ['nullable', 'boolean'],
            'goodTillDate' => ['nullable', 'regex:/^(\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}|\d*)$/'],
            'guaranteedStop' => ['required', 'boolean'],
            'level' => ['required', 'numeric'],
            'limitDistance' => ['nullable', 'numeric'],
            'limitLevel' => ['nullable', 'numeric'],
            'size' => ['required', 'numeric'],
            'stopDistance' => ['nullable', 'numeric'],
            'stopLevel' => ['nullable', 'numeric'],
            'timeInForce' => ['nullable', 'in:GOOD_TILL_CANCELLED,GOOD_TILL_DATE'],
            'type' => ['nullable', 'in:LIMIT,STOP'],
        ];

        $v = Validator::make($data, $rules);
        $v->after(function ($validator) use ($data) {
            // Mutual exclusivity checks
            if (! empty($data['limitLevel']) && ! empty($data['limitDistance'])) {
                $validator->errors()->add('limit', 'Set only one of {limitLevel, limitDistance}');
            }

            if (! empty($data['stopLevel']) && ! empty($data['stopDistance'])) {
                $validator->errors()->add('stop', 'Set only one of {stopLevel, stopDistance}');
            }

            if (! empty($data['guaranteedStop']) && $data['guaranteedStop'] === true) {
                // If guaranteedStop true then only one of stopDistance is allowed — interpret as stopDistance must be set and stopLevel not set
                if (empty($data['stopDistance']) || ! empty($data['stopLevel'])) {
                    $validator->errors()->add('guaranteedStop', 'If guaranteedStop equals true, then set only one of stopDistance and do not set stopLevel');
                }
            }

            if (isset($data['timeInForce']) && $data['timeInForce'] === 'GOOD_TILL_DATE') {
                if (empty($data['goodTillDate'])) {
                    $validator->errors()->add('goodTillDate', 'If timeInForce equals GOOD_TILL_DATE, then set goodTillDate');
                }
            }

            // Precision check: size should not have more than 12 decimal places
            if (isset($data['size']) && is_numeric($data['size'])) {
                $parts = explode('.', (string) $data['size']);
                if (isset($parts[1]) && strlen(rtrim($parts[1], '0')) > 12) {
                    $validator->errors()->add('size', 'Size precision cannot be more than 12 decimal places');
                }
            }
        });

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        // Return wrapped payload
        return ['request' => $data];
    }
}
