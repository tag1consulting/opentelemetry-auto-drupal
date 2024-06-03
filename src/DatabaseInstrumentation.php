<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\Core\Database\Connection;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use function \OpenTelemetry\Instrumentation\hook;

class DatabaseInstrumentation extends InstrumentationBase {

  public const DB_VARIABLES = 'db.variables';

  public static function register(): void {

    $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.drupal');

    hook(
      Connection::class,
      'query',
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
    return static function (Connection $connection, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
      $parent = Context::getCurrent();

      /** @var \OpenTelemetry\API\Trace\SpanBuilderInterface $span */
      $span = $instrumentation->tracer()->spanBuilder('database::query')
        ->setParent($parent)
        ->setSpanKind(SpanKind::KIND_CLIENT)
        ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
        ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
        ->setAttribute(TraceAttributes::DB_SYSTEM, 'mariadb')
        ->setAttribute(TraceAttributes::DB_STATEMENT, $params[0]);
      if (isset($params[1]) === TRUE) {
        $cleanVariables = array_map(static fn ($value) => is_array($value) ? json_encode($value) : (string) $value, $params[1]);
        $span->setAttribute(self::DB_VARIABLES, $cleanVariables);
      }
      $span = $span->startSpan();

      Context::storage()
        ->attach($span->storeInContext($parent));
    };
  }

}
