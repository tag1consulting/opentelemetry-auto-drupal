<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\Core\DrupalKernel;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class DrupalKernelInstrumentation extends InstrumentationBase {

  protected const CLASSNAME = DrupalKernel::class;
  const HTTP_ROUTE_PARAMETERS = 'http.route.parameters';

  public const NAME = 'drupal';

  /**
   *
   */
  public static function register(): void {
    static::create(
      name: 'io.opentelemetry.contrib.php.drupal',
      prefix: 'drupal',
      className: static::CLASSNAME
    );
  }

  /**
   *
   */
  protected function registerInstrumentation(): void {
    $this->helperHook(
      methodName: 'handle',
      preHandler: function ($spanBuilder, DrupalKernel $kernel, array $params) {
        $request = ($params[0] instanceof Request) ? $params[0] : NULL;
        $spanName = \sprintf('%s %s', $request?->getScheme() ?? 'HTTP', $request?->getMethod() ?? 'unknown');

        $spanBuilder->setSpanKind(SpanKind::KIND_SERVER);

        if ($request) {
          $parent = Globals::propagator()->extract($request, RequestPropagationGetter::instance());
          $spanBuilder->setParent($parent);
          $spanBuilder->setAttribute(TraceAttributes::HTTP_URL, $request->getUri())
            ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
            ->setAttribute(TraceAttributes::HTTP_REQUEST_CONTENT_LENGTH, $request->headers->get('Content-Length'))
            ->setAttribute(TraceAttributes::HTTP_SCHEME, $request->getScheme());
          $request->attributes->set(SpanInterface::class, $spanBuilder);
        }

        return [$request];
      },
      postHandler: function ($span, DrupalKernel $kernel, array $params, ?Response $response) {
        $request = ($params[0] instanceof Request) ? $params[0] : NULL;

        if ($request !== NULL) {
          self::setSpanName($kernel, $request, $span);

          $routeParameters = $request->attributes->get('_raw_variables');
          if ($routeParameters !== NULL && $routeParameters->count() > 0) {
            $span->setAttribute(self::HTTP_ROUTE_PARAMETERS, json_encode($routeParameters->all()));
          }
        }

        if ($response === NULL) {
          return;
        }

        if ($response->getStatusCode() >= Response::HTTP_INTERNAL_SERVER_ERROR) {
          $span->setStatus(StatusCode::STATUS_ERROR);
        }

        $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
        $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_NAME, $response->getProtocolVersion());

        if (is_string($response->getContent())) {
          $contentLength = \strlen($response->getContent());
          $span->setAttribute(TraceAttributes::HTTP_RESPONSE_CONTENT_LENGTH, $contentLength);
        }
      }
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

}
