<?php

declare(strict_types=1);

namespace CraftCms\CommerceEway;

use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Commerce\Payment\Gateway\GatewayTypes;

/**
 * eWAY for Craft Commerce.
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '5.0.0';

    public string $minVersionRequired = '4.1.1';

    public function boot(GatewayTypes $gatewayTypes): void
    {
        $gatewayTypes->register(Gateway::class);
    }
}
