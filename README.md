<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="eWay for Craft Commerce icon"></p>

<h1 align="center">eWay for Craft Commerce</h1>

This plugin provides an [eWay](https://www.eway.com.au/) integration for [Craft Commerce](https://craftcms.com/commerce).

## Requirements

This plugin requires Craft CMS 4.0 and Craft Commerce 4.0 or later.

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

> **Tip:** The API Key, Password, and Client side encryption key settings can be set to environment variables. See [Environmental Configuration](https://docs.craftcms.com/v3/config/environments.html) in the Craft docs for more information.
