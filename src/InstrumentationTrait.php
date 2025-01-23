<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use function OpenTelemetry\Instrumentation\hook;
use ReflectionMethod;
use Throwable;

trait InstrumentationTrait
{
  protected static ?CachedInstrumentation $instrumentation = null;

  protected static function helperHook(
    string $className,
    string $methodName,
    array $paramMap = [],
    ?string $returnValueKey = null,
    ?callable $preHandler = null,
    ?callable $postHandler = null
  ): void {
    $resolvedParamMap = static::resolveParamPositions($className, $methodName, $paramMap);
    hook(
      $className,
      $methodName,
      pre: static::preHook("$className::$methodName", $resolvedParamMap, $preHandler),
      post: static::postHook("$className::$methodName", $returnValueKey, $postHandler)
    );
  }

  protected static function initializeInstrumentation(string $name): void
  {
    if (static::$instrumentation === null) {
      static::$instrumentation = new CachedInstrumentation($name);
    }
  }

  protected static function getInstrumentation(): CachedInstrumentation
  {
    if (static::$instrumentation === null) {
      throw new \RuntimeException('Instrumentation not initialized. Call initializeInstrumentation() first.');
    }
    return static::$instrumentation;
  }

  protected static function preHook(
    string $operation,
    array $resolvedParamMap = [],
    ?callable $customHandler = null
  ): callable {
    $instrumentation = static::getInstrumentation();
    return static function (
      $object,
      array $params,
      string $class,
      string $function,
      ?string $filename,
      ?int $lineno
    ) use ($resolvedParamMap, $customHandler, $instrumentation): void {
      $parent = Context::getCurrent();
      $spanBuilder = $instrumentation->tracer()->spanBuilder("$class::$function")
        ->setParent($parent)
        ->setSpanKind(SpanKind::KIND_CLIENT)
        ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
        ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

      $spanBuilder->setAttribute('drupal.operation', $operation);

      // Handle standard parameter mapping
      foreach ($resolvedParamMap as $attributeName => $position) {
        if (isset($params[$position])) {
          $value = $params[$position];
          $spanBuilder->setAttribute(
            'drupal.' . $attributeName,
            is_scalar($value) ? $value : json_encode($value)
          );
        }
      }

      // Execute custom handler if provided, passing spanBuilder
      if ($customHandler !== null) {
        $customHandler($spanBuilder, $object, $params, $class, $function, $filename, $lineno);
      }

      $span = $spanBuilder->startSpan();
      Context::storage()->attach($span->storeInContext($parent));
    };
  }

  protected static function postHook(
    string $operation,
    ?string $resultAttribute = null,
    ?callable $customHandler = null
  ): callable {
    return static function (
      $object,
      array $params,
      $returnValue,
      ?Throwable $exception
    ) use ($operation, $resultAttribute, $customHandler): void {
      $scope = Context::storage()->scope();
      if (!$scope) {
        return;
      }

      $scope->detach();
      $span = Span::fromContext($scope->context());

      // Handle standard return value mapping
      if ($resultAttribute !== null) {
        $span->setAttribute(
          'drupal.' . $resultAttribute,
          is_scalar($returnValue) ? $returnValue : json_encode($returnValue)
        );
      }

      // Handle exception and status first
      if ($exception) {
        $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => TRUE]);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
      }

      // Execute custom handler if provided, passing span
      if ($customHandler !== null) {
        $customHandler($span, $object, $params, $returnValue, $exception);
      }

      $span->end();
    };
  }

  protected static function resolveParamPositions(
    string $className,
    string $methodName,
    array $paramMap
  ): array {
    if (empty($paramMap)) {
      return [];
    }

    $reflection = new ReflectionMethod($className, $methodName);
    $parameters = $reflection->getParameters();
    $resolvedMap = [];

    foreach ($paramMap as $key => $value) {
      $paramName = is_int($key) ? $value : $key;
      $attributeName = $value;

      foreach ($parameters as $index => $parameter) {
        if ($parameter->getName() === $paramName) {
          $resolvedMap[$attributeName] = $index;
          break;
        }
      }
    }
    return $resolvedMap;
  }
}
