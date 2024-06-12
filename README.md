# OpenTelemetry Drupal auto-instrumentation

This is an OpenTelemetry auto-instrumentation package for Drupal framework applications.

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Requirements

* [OpenTelemetry extension](https://opentelemetry.io/docs/instrumentation/php/automatic/#installation)
* OpenTelemetry SDK and exporters (required to actually export traces)

## Overview
The following features are supported:
* root span creation (Drupal core hooks)
* context propagation
* HttpClient client span creation
* HttpClient context propagation
* Message Bus span creation
* Message Transport span creation

## Installation via composer

```bash
$ composer require mladenrtl/opentelemetry-auto-drupal
```

## Installing dependencies and executing tests

From Drupal subdirectory:

```bash
$ composer install
$ ./vendor/bin/phpunit tests
```

## Configuration
The extension can be disabled via runtime configuration:

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=drupal
```



