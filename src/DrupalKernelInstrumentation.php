<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\Core\DrupalKernel;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use function \OpenTelemetry\Instrumentation\hook;

/**
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class DrupalKernelInstrumentation extends InstrumentationBase{

  const HTTP_ROUTE_PARAMETERS = 'http.route.parameters';

  public static function register(): void {

    $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.drupal');

    hook(
      DrupalKernel::class,
      'handle',
      static::preClosure($instrumentation),
      static::postClosure()
    );

  }

  /**
   * @param \Drupal\Core\DrupalKernel $kernel
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @param \OpenTelemetry\API\Trace\SpanInterface $span
   *
   * @return void
   */
  private static function setSpanName(DrupalKernel $kernel, Request $request, SpanInterface $span): void {
    $routeName = $request->attributes->get('_route', '');
    $span->updateName($routeName);
    if ('' !== $routeName) {
      $span->setAttribute(TraceAttributes::HTTP_ROUTE, $routeName);
      return;
    }

    $routes = self::getRoutes($kernel, $request);

    if ($routes->valid() === TRUE && $routes->count() > 0) {
      $routeName = $routes->key();
      $span->updateName(\sprintf('%s %s %s', strtoupper($request->getScheme()), $request?->getMethod() ?? 'unknown', $routeName));
      $span->setAttribute(TraceAttributes::HTTP_ROUTE, $routeName);
    }
  }

  /**
   * @param \Drupal\Core\DrupalKernel $kernel
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \ArrayIterator|\Symfony\Component\Routing\Route[]
   */
  private static function getRoutes(DrupalKernel $kernel, Request $request): \ArrayIterator|array {
    $container = $kernel->getContainer();
    if ($container === NULL) {
      return new \ArrayIterator();
    }

    $container->get('request_stack')->push($request);

    /** @var \Drupal\Core\Routing\RouteProviderInterface $routeProvider */
    $routeProvider = $container->get('router.route_provider');

    /** @var \Symfony\Component\Routing\RouteCollection $route */
    $routeCollection = $routeProvider->getRouteCollectionForRequest($request);

    return $routeCollection->getIterator();
  }

  /**
   * @param \OpenTelemetry\API\Instrumentation\CachedInstrumentation $instrumentation
   *
   * @return \Closure
   */
  public static function preClosure(CachedInstrumentation $instrumentation): \Closure {
    return static function (DrupalKernel $kernel, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
      $request = ($params[0] instanceof Request) ? $params[0] : NULL;
      /** @psalm-suppress ArgumentTypeCoercion */
      $spanName = \sprintf('%s %s', $request?->getScheme() ?? 'HTTP', $request?->getMethod() ?? 'unknown');
      $builder = $instrumentation
        ->tracer()
        ->spanBuilder($spanName)
        ->setSpanKind(SpanKind::KIND_SERVER)
        ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
        ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

      $parent = Context::getCurrent();
      if ($request) {
        $parent = Globals::propagator()
          ->extract($request, RequestPropagationGetter::instance());
        $span = $builder
          ->setParent($parent)
          ->setAttribute(TraceAttributes::HTTP_URL, $request->getUri())
          ->setAttribute(TraceAttributes::HTTP_METHOD, $request->getMethod())
          ->setAttribute(TraceAttributes::HTTP_REQUEST_CONTENT_LENGTH, $request->headers->get('Content-Length'))
          ->setAttribute(TraceAttributes::HTTP_SCHEME, $request->getScheme())
          ->startSpan();
        $request->attributes->set(SpanInterface::class, $span);
      }
      else {
        $span = $builder->startSpan();
      }
      Context::storage()->attach($span->storeInContext($parent));

      return [$request];
    };
  }

  public static function postClosure(): \Closure {
    return static function (DrupalKernel $kernel, array $params, ?Response $response, ?Throwable $exception) {
      $scope = Context::storage()->scope();
      if (!$scope) {
        return;
      }
      $scope->detach();
      $span = Span::fromContext($scope->context());

      $request = ($params[0] instanceof Request) ? $params[0] : NULL;
      if (NULL !== $request) {
        self::setSpanName($kernel, $request, $span);

        $routeParameters = $request->attributes->get('_raw_variables');
        if ($routeParameters !== NULL && $routeParameters->count() > 0) {
          $span->setAttribute(self::HTTP_ROUTE_PARAMETERS, json_encode($routeParameters->all()));
        }
      }

      if (NULL !== $exception) {
        $span->recordException($exception, [
          TraceAttributes::EXCEPTION_ESCAPED => FALSE,
        ]);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
      }

      if (NULL === $response) {
        $span->end();

        return;
      }

      if ($response->getStatusCode() >= Response::HTTP_INTERNAL_SERVER_ERROR) {
        $span->setStatus(StatusCode::STATUS_ERROR);
      }

      $span->setAttribute(TraceAttributes::HTTP_STATUS_CODE, $response->getStatusCode());
      $span->setAttribute(TraceAttributes::HTTP_FLAVOR, $response->getProtocolVersion());
      /** @psalm-suppress PossiblyFalseArgument */
      if (is_string($response->getContent())) {
        $contentLength = \strlen($response->getContent());
        $span->setAttribute(TraceAttributes::HTTP_RESPONSE_CONTENT_LENGTH, $contentLength);
      }

      $span->end();
    };
  }

}
