<?php

declare(strict_types=1);

namespace App\Services\Ecommerce;

use App\Models\ChannelIntegration;
use App\Services\Ecommerce\Contracts\EcommerceConnector;
use InvalidArgumentException;

/** Resolves the concrete connector for an integration's platform. */
class ConnectorFactory
{
    public function make(ChannelIntegration $integration): EcommerceConnector
    {
        $meta = PlatformRegistry::get($integration->platform);
        if (! $meta) {
            throw new InvalidArgumentException("Unsupported e-commerce platform: {$integration->platform}");
        }

        /** @var class-string<EcommerceConnector> $class */
        $class = $meta['connector'];

        return new $class($integration);
    }
}
