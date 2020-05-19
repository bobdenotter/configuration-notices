# 📰 Bolt News Widget

A simple Widget Extension to display the latest Bolt news on the dashboard. 
Installation:

```bash
composer require bobdenotter/configuration-notices 
```

-------

The part below is only for _developing_ the extension. Not required for general
usage of the extension in your Bolt Project

## Running PHPStan and Easy Codings Standard

First, make sure dependencies are installed:

```
COMPOSER_MEMORY_LIMIT=-1 composer update
```

And then run ECS:

```
vendor/bin/ecs check src
```
