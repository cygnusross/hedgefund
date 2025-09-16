<?php

namespace App\Services\IG\Endpoints;

use App\Services\IG\BaseEndpoint;
use App\Services\IG\Enums\Resolution;

class HistoricalPricesEndpoint extends BaseEndpoint
{
    /**
     * Get historical prices for the given epic, resolution and number of data points.
     *
     * @param string $epic Instrument epic
     * @param Resolution|string $resolution Price resolution
     * @param int $numPoints Number of data points required
     * @return array Raw API response
     */
    public function get(string $epic, Resolution|string $resolution, int $numPoints): array
    {
        // URL encode the epic to handle special characters
        $encodedEpic = urlencode($epic);

        // Convert Resolution enum to string if needed
        $resolutionString = $resolution instanceof Resolution ? $resolution->value : $resolution;
        $encodedResolution = urlencode($resolutionString);

        // Validate numPoints is positive
        if ($numPoints <= 0) {
            throw new \InvalidArgumentException("Number of points must be positive, got: {$numPoints}");
        }

        $path = "/prices/{$encodedEpic}/{$encodedResolution}/{$numPoints}";

        // Use version 2 of the API as specified in the documentation
        $response = $this->client->get($path, [], ['Version' => '2']);

        return $response['body'] ?? [];
    }
}
