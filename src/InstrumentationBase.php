<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\redis\Cache\CacheBase;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

abstract class InstrumentationBase {

  public const NAME = 'php-fpm';

  abstract public static function register(): void;

  public static function preClosure(CachedInstrumentation $instrumentation): \Closure {
    return static function (CacheBase $cacheBase, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
      $span = $instrumentation->tracer()->spanBuilder('cache_backend::get')
        ->setSpanKind(SpanKind::KIND_CLIENT)
        ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
        ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
        ->setAttribute(TraceAttributes::DB_SYSTEM, 'redis')
        ->setAttribute('cache.key', $params[0])
        ->startSpan();
      Context::storage()
        ->attach($span->storeInContext(Context::getCurrent()));
    };
  }

  public static function postClosure(): \Closure {
    return static function (mixed $base, array $params, $returnValue, ?Throwable $exception) {
      $scope = Context::storage()->scope();
      if (!$scope) {
        return;
      }
      $scope->detach();
      $span = Span::fromContext($scope->context());
      if ($exception) {
        $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => TRUE]);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
      }

      $span->end();
    };
  }

}
