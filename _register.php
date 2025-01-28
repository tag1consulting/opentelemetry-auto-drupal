<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\Drupal\CacheBackendInstrumentation;
use OpenTelemetry\Contrib\Instrumentation\Drupal\DatabaseInstrumentation;
use OpenTelemetry\Contrib\Instrumentation\Drupal\DrupalAutoRootSpan;
use OpenTelemetry\Contrib\Instrumentation\Drupal\DrupalKernelInstrumentation;
use OpenTelemetry\Contrib\Instrumentation\Drupal\EntityInstrumentation;
use OpenTelemetry\Contrib\Instrumentation\Drupal\InstrumentModules;
use OpenTelemetry\Contrib\Instrumentation\Drupal\HttpClientCallInstrumentation;
use OpenTelemetry\Contrib\Instrumentation\Drupal\HttpClientRequestInstrumentation;
use OpenTelemetry\Contrib\Instrumentation\Drupal\ViewsInstrumentation;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(DrupalKernelInstrumentation::NAME) === TRUE) {
  return;
}

if (extension_loaded('opentelemetry') === FALSE) {
  trigger_error('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry Drupal auto-instrumentation', E_USER_WARNING);

  return;
}

// Force disable the FiberBoundContextStorage because of the conflict
// with Drupal Renderer service.
// @see https://www.drupal.org/project/opentelemetry/issues/3488173
// @todo Make a proper fix to work well with the FiberBoundContextStorage.
$contextStorage = new ContextStorage();
Context::setStorage($contextStorage);

try {
  CacheBackendInstrumentation::register();
  DrupalAutoRootSpan::register();
  DrupalKernelInstrumentation::register();
  DatabaseInstrumentation::register();
  EntityInstrumentation::register();
  HttpClientRequestInstrumentation::register();
  HttpClientCallInstrumentation::register();
  InstrumentModules::registerModule(ViewsInstrumentation::class);
}
catch (Throwable $exception) {
  throw $exception;
  //\Drupal::logger("drupalInstrumentation")->error($exception->getMessage());
  //return;
}
