<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\views\ViewExecutable;

class ViewsInstrumentation extends InstrumentationBase {

  public static function register(): void {

    $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.drupal_views');

    hook(
      ViewExecutable::class,
      'executeDisplay',
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
    return static function (ViewExecutable $executable, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {

      $display_id = $params[0];

      // Get the name of the view
      $span = "VIEW";
      $name = NULL;

      $storage = $executable->storage;

      if ($storage) {
        $name = $storage->label();
      }

      if ($name) {
        $span .= ' ' . $name;
      }

      $parent = Context::getCurrent();

      /** @var \OpenTelemetry\API\Trace\SpanBuilderInterface $span */
      $span = $instrumentation->tracer()->spanBuilder($span)
        ->setParent($parent)
        ->setSpanKind(SpanKind::KIND_CLIENT)
        ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
        ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
        ->setAttribute('drupal.view.name', $name)
        ->setAttribute('drupal.view.display_id', $display_id)
        ->startSpan();
      Context::storage()
        ->attach($span->storeInContext(Context::getCurrent()));

      return $params;
    };
  }
}
