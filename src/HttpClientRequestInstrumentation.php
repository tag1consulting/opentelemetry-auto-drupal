<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use GuzzleHttp\Client;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use function \OpenTelemetry\Instrumentation\hook;

class HttpClientRequestInstrumentation extends InstrumentationBase{

  public static function register(): void {

    $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.drupal');

    hook(
      Client::class,
      'requestAsync',
      static::preClosure($instrumentation),
      static::postClosure()
    );

  }

  /**
   * @param \OpenTelemetry\API\Instrumentation\CachedInstrumentation $instrumentation
   *
   * @return \Closure
   */
  public static function preClosure(CachedInstrumentation $instrumentation): \Closure {
    return static function (Client $client, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
      $host = $params[1];
      if (filter_var($params[1], FILTER_VALIDATE_URL)) {
        $host = parse_url($params[1], PHP_URL_HOST);
      }

      $span = $instrumentation->tracer()->spanBuilder($host)
        ->setSpanKind(SpanKind::KIND_CLIENT)
        ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
        ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
        ->setAttribute(TraceAttributes::HTTP_METHOD, $params[0])
        ->setAttribute(TraceAttributes::HTTP_URL, $params[1])
        ->setAttribute(TraceAttributes::NET_HOST_NAME, $host)
        ->startSpan();
      Context::storage()
        ->attach($span->storeInContext(Context::getCurrent()));
    };
  }

}
