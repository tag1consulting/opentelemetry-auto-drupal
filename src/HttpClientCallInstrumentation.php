<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;
use function OpenTelemetry\Instrumentation\hook;

class HttpClientCallInstrumentation extends InstrumentationBase{

  public static function register(): void {

    $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.drupal');

    hook(
      Client::class,
      '__call',
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
      $url = is_array($params[1]) ? $params[1][0] ?? NULL : $params[1];

      $host = filter_var($url, FILTER_VALIDATE_URL)
        ? parse_url($url, PHP_URL_HOST)
        : ($url ?? 'unknown-http-client');

      $span = $instrumentation->tracer()->spanBuilder($host)
        ->setSpanKind(SpanKind::KIND_CLIENT)
        ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
        ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
        ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $params[0])
        ->setAttribute(TraceAttributes::URL_FULL, $params[1])
        ->setAttribute(TraceAttributes::SERVER_ADDRESS, $host)
        ->startSpan();
      Context::storage()
        ->attach($span->storeInContext(Context::getCurrent()));
    };
  }

  /**
   * @return \Closure
   */
  public static function postClosure(): \Closure {
    return static function (Client $client, array $params, $response, ?Throwable $exception) {
      $scope = Context::storage()->scope();
      if (!$scope) {
        return;
      }
      $scope->detach();
      $span = Span::fromContext($scope->context());

      if ($response instanceof GuzzleResponse) {
        $span->setAttribute(TraceAttributes::HTTP_STATUS_CODE, $response->getStatusCode());
      }

      if ($exception) {
        $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => TRUE]);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
      }

      $span->end();
    };
  }

}
