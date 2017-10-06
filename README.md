# Mindsize NewRelic

WordPress plugin to make New Relic data more approachable and readable.

This plugin is a mix of [10up's New Relic plugin](https://github.com/10up/wp-newrelic) and [Gigaom's New Relic plugin](https://github.com/gigaOM/go-newrelic), and incorporates the best of both while leaving out things that Mindsize doesn't need.

Things left out:

* no `ini_set` calls, as the PHP Agent does not support it
* no Browser agent
* no utility functions for plugins to call

## Installation

1. clone this repository into your plugins folder (by default it's `wp-content/plugins`)
2. issue `composer install`
3. activate the plugin

## Prerequisites

1. Composer on the server
2. New Relic agent installed and token configured
