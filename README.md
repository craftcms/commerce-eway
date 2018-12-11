# eWay for Craft Commerce

This plugin provides an [eWay](https://www.eway.com.au/) integration for [Craft Commerce](https://craftcms.com/commerce).

## Requirements

This plugin requires Craft Commerce 2.0.0-alpha.5 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “eWay for Craft Commerce”. Then click on the “Install” button in its modal window.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require craftcms/commerce-eway

# tell Craft to install the plugin
./craft install/plugin commerce-eway
```

## Setup

To add an eWay payment gateway, go to Commerce → Settings → Gateways, create a new gateway, and set the gateway type to “eWay Direct”.
