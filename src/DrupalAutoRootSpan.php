<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\Core\DrupalKernel;
use OpenTelemetry\SDK\Trace\AutoRootSpan;
use function OpenTelemetry\Instrumentation\hook;

/**
 *
 */
class DrupalAutoRootSpan {

  /**
   *
   */
  public static function register(): void {

    hook(
      DrupalKernel::class,
      '__construct',
      static::registerAutoRootSpan(),
      NULL,
    );
  }

  /**
   * @param \OpenTelemetry\API\Instrumentation\CachedInstrumentation $instrumentation
   *
   * @return \Closure
   */
  public static function registerAutoRootSpan(): \Closure {
    return static function (DrupalKernel $kernel, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
      $request = AutoRootSpan::createRequest();

      if ($request) {
        AutoRootSpan::create($request);
        AutoRootSpan::registerShutdownHandler();
      }
    };
  }

}
