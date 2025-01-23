<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use ReflectionMethod;
use Throwable;

trait InstrumentationTrait
{
  protected static ?CachedInstrumentation $instrumentation = null;

  protected static function helperHook(
    string $className,
    string $methodName,
    array $paramMap = [],
    ?string $returnValueKey = null
  ): void {
    $resolvedParamMap = static::resolveParamPositions($className, $methodName, $paramMap);
    
    hook(
      $className,
      $methodName,
      pre: static::preHook("$className::$methodName", $resolvedParamMap),
      post: static::postHook("$className::$methodName", $returnValueKey)
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
    array $resolvedParamMap = []
  ): callable {
    $instrumentation = static::getInstrumentation();

    return static function (
      object $object, 
      array $params, 
      string $class, 
      string $function, 
      ?string $filename, 
      ?int $lineno
    ) use ($operation, $resolvedParamMap, $instrumentation): void {
      $parent = Context::getCurrent();
      
      $spanBuilder = $instrumentation->tracer()->spanBuilder($operation)
        ->setParent($parent)
        ->setSpanKind(SpanKind::KIND_CLIENT)
        ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
        ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

      $spanBuilder->setAttribute('drupal.operation', $operation);

      foreach ($resolvedParamMap as $attributeName => $position) {
        if (isset($params[$position])) {
          $value = $params[$position];
          $spanBuilder->setAttribute(
            'drupal.' . $attributeName,
            is_scalar($value) ? $value : json_encode($value)
          );
        }
      }

      $span = $spanBuilder->startSpan();
      Context::storage()->attach($span->storeInContext($parent));
    };
  }

  protected static function postHook(string $operation, ?string $resultAttribute = null): callable
  {
    return static function (
      object $object,
      array $params,
      mixed $returnValue,
      ?Throwable $exception
    ) use ($resultAttribute): void {
      $scope = Context::storage()->scope();
      if (!$scope) {
        return;
      }

      $scope->detach();
      $span = SpanInterface::fromContext($scope->context());
      
      // Record return value if configured
      if ($resultAttribute !== null) {
        $span->setAttribute(
          $resultAttribute,
          is_scalar($returnValue) ? $returnValue : json_encode($returnValue)
        );
      }

      // Handle exception and status
      if ($exception) {
        $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
      }

      $span->end();
    };
  }

  protected static function resolveParamPositions(
    string $className,
    string $methodName,
    array $paramMap
  ): array {
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
