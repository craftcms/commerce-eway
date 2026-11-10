<?php

namespace craft\commerce\eway\gateways;

// Gateways saved with eWAY for Craft Commerce 4 store this class name as their type.
/** @deprecated in 5.0.0. Use {@see \CraftCms\CommerceEway\Gateway} instead. */
class_alias(\CraftCms\CommerceEway\Gateway::class, 'craft\commerce\eway\gateways\Gateway');

/** @phpstan-ignore-next-line */
if (false) {
    class Gateway extends \CraftCms\CommerceEway\Gateway
    {
    }
}
