<?php

namespace Zain\WebhookLedger\Identifiers;

use Illuminate\Support\Arr;
use Zain\WebhookLedger\Contracts\EventIdentifier;

/**
 * Pulls ids out of a payload using configured dot-paths, so most providers need
 * no custom class at all.
 *
 *     'paths' => [
 *         'id'          => 'id',
 *         'type'        => 'event_type',
 *         'external_id' => 'resource.id',
 *     ],
 *
 * Each entry may also be a list of candidate paths, tried in order - useful
 * where a provider moved a field between API versions and you receive both.
 */
class GenericIdentifier implements EventIdentifier
{
    public function __construct(protected array $config = []) {}

    public function identify(array $payload): ?string
    {
        return $this->first($payload, $this->path('id', 'id'));
    }

    public function type(array $payload): ?string
    {
        return $this->first($payload, $this->path('type', 'type'));
    }

    public function externalId(array $payload): ?string
    {
        return $this->first($payload, $this->path('external_id', null));
    }

    /**
     * @return array<int, string>
     */
    protected function path(string $key, ?string $default): array
    {
        $configured = $this->config['paths'][$key] ?? $default;

        if ($configured === null) {
            return [];
        }

        return is_array($configured) ? $configured : [$configured];
    }

    /**
     * @param  array<int, string>  $paths
     */
    protected function first(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);

            // Scalars only. A nested array here means the path is wrong, and
            // stringifying it would produce a useless deduplication key.
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
