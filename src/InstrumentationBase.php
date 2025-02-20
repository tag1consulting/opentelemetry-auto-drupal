<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait;

/**
 *
 */
abstract class InstrumentationBase {
  use InstrumentationTrait {
    create as protected createClass;
  }

  /**
   * Creates and initializes the instrumentation.
   */
  protected static function create(...$args): static {
    $instance = static::createClass(...$args);
    $instance->registerInstrumentation();
    return $instance;
  }

  /**
   * Register the specific instrumentation logic.
   */
  abstract protected function registerInstrumentation(): void;

  /**
   * Resolves parameter information for a given method using reflection.
   *
   * This helper function creates a mapping between parameter names and their positions
   * in the method signature. This is crucial for converting positional arguments
   * to named parameters later in the instrumentation process.
   *
   * @param string $className
   *   The fully qualified class name.
   * @param string $methodName
   *   The method name to analyze.
   *
   * @return array<string, int> Map of parameter names to their positions
   *   Example: ['cid' => 0, 'data' => 1, 'expire' => 2].
   *
   * @throws \ReflectionException If the method does not exist
   */
  protected static function resolveMethodParameters(string $className, string $methodName): array {
    $reflMethod = new \ReflectionMethod($className, $methodName);
    $parameterPositions = [];
    foreach ($reflMethod->getParameters() as $position => $parameter) {
      $parameterPositions[$parameter->getName()] = $position;
    }
    return $parameterPositions;
  }

  /**
   * Creates a converter function that transforms positional parameters to named parameters.
   *
   * This helper creates a closure that can convert an array of positional arguments
   * into an associative array that maintains both numeric indexes and named parameters.
   * This allows for flexible parameter access either by position or name.
   *
   * Example:
   * Input: [0 => 'value1', 1 => 'value2']
   * Parameter positions: ['name1' => 0, 'name2' => 1]
   * Output: [0 => 'value1', 1 => 'value2', 'name1' => 'value1', 'name2' => 'value2']
   *
   * @param array<string, int> $parameterPositions
   *   Parameter name to position mapping.
   *
   * @return callable Function that converts positional to named parameters while preserving numeric indexes
   *   Signature: function(array $params): array.
   */
  protected static function createNamedParamsConverter(array $parameterPositions): callable {
    return function (array $params) use ($parameterPositions): array {
      $namedParams = $params;
      foreach ($parameterPositions as $name => $position) {
        if (isset($params[$position])) {
          $namedParams[$name] = $params[$position];
        }
      }
      return $namedParams;
    };
  }

  /**
   * Creates a wrapped handler that combines common and specific handling logic.
   *
   * @param callable $converter
   *   Parameter converter function.
   * @param callable|null $handler
   *   Specific handling logic.
   * @param callable $allHandler
   *   Common handling logic.
   *
   * @return callable Combined handler.
   */
  protected static function wrapHandler(callable $converter, ?callable $handler, callable $allHandler): callable {
    return function (...$args) use ($converter, $handler, $allHandler) {
      $params = $converter($args[2]);

      $allHandler(...$args);
      if ($handler) {
        $args[2] = $params;
        $handler(...$args);
      }
    };
  }

  /**
   * Registers multiple operations with common handling logic.
   *
   * @param array $operations
   *   Array of operation configurations
   *   [
   *                           'methodName' => [
   *                             'params' => ['paramName' => 'attributeName'],
   *                             'preHandler' => callable,
   *                             'postHandler' => callable,
   *                             'returnValue' => string|null
   *                           ]
   *                         ].
   * @param callable|null $commonPreHandler
   *   Handler applied before all operations.
   * @param callable|null $commonPostHandler
   *   Handler applied after all operations.
   *
   * @return this
   */
  protected function registerOperations(
        array $operations,
        ?callable $commonPreHandler = NULL,
        ?callable $commonPostHandler = NULL
    ): self {
    foreach ($operations as $method => $config) {
      $parameterPositions = static::resolveMethodParameters($this->className, $method);
      $converter = static::createNamedParamsConverter($parameterPositions);

      $preHandler = isset($config['preHandler']) || $commonPreHandler ?
              static::wrapHandler(
                $converter,
                $config['preHandler'] ?? NULL,
                $commonPreHandler ?? function () {}
            ) : NULL;

      $postHandler = isset($config['postHandler']) || $commonPostHandler ?
              static::wrapHandler(
                $converter,
                $config['postHandler'] ?? NULL,
                $commonPostHandler ?? function () {}
            ) : NULL;

      $this->helperHook(
            methodName: $method,
            paramMap: $config['params'] ?? [],
            returnValueKey: $config['returnValue'] ?? NULL,
            preHandler: $preHandler,
            postHandler: $postHandler
        );
    }

    return $this;
  }

}
