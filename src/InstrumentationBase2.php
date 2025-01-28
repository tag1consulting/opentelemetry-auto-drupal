<?php
namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

require_once __DIR__ . '/../opentelemery-php-instrumentation-trait/src/InstrumentationTrait.php';
use PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait;

abstract class InstrumentationBase2 {
    use InstrumentationTrait;

    /**
     * Resolves parameter information for a given method using reflection.
     *
     * This helper function creates a mapping between parameter names and their positions
     * in the method signature. This is crucial for converting positional arguments
     * to named parameters later in the instrumentation process.
     *
     * @param string $className The fully qualified class name
     * @param string $methodName The method name to analyze
     * @return array<string, int> Map of parameter names to their positions
     *                           Example: ['cid' => 0, 'data' => 1, 'expire' => 2]
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
     * into an associative array of named parameters based on the parameter mapping
     * created by resolveMethodParameters().
     *
     * @param array<string, int> $parameterPositions Parameter name to position mapping
     * @return callable Function that converts positional to named parameters
     *                 Signature: function(array $params): array
     */
    protected static function createNamedParamsConverter(array $parameterPositions): callable {
        return function(array $params) use ($parameterPositions): array {
            $namedParams = [];
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
     * @param callable $converter Parameter converter function
     * @param callable|null $handler Specific handling logic
     * @param callable $allHandler Common handling logic
     * @return callable Combined handler
     */
    protected static function wrapHandler(callable $converter, ?callable $handler, callable $allHandler): callable {
        return function(...$args) use ($converter, $handler, $allHandler) {
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
     * @param array $operations Array of operation configurations
     *                         [
     *                           'methodName' => [
     *                             'params' => ['paramName' => 'attributeName'],
     *                             'preHandler' => callable,
     *                             'postHandler' => callable,
     *                             'returnValue' => string|null
     *                           ]
     *                         ]
     * @param callable|null $commonPreHandler Handler applied before all operations
     * @param callable|null $commonPostHandler Handler applied after all operations
     * @return $this
     */
    protected function registerOperations(
        array $operations,
        ?callable $commonPreHandler = null,
        ?callable $commonPostHandler = null
    ): self {
        foreach ($operations as $method => $config) {
            $parameterPositions = static::resolveMethodParameters($this->className, $method);
            $converter = static::createNamedParamsConverter($parameterPositions);

            $preHandler = isset($config['preHandler']) || $commonPreHandler ?
                static::wrapHandler(
                    $converter,
                    $config['preHandler'] ?? null,
                    $commonPreHandler ?? function() {}
                ) : null;

            $postHandler = isset($config['postHandler']) || $commonPostHandler ?
                static::wrapHandler(
                    $converter,
                    $config['postHandler'] ?? null,
                    $commonPostHandler ?? function() {}
                ) : null;

            $this->helperHook(
                methodName: $method,
                paramMap: $config['params'] ?? [],
                returnValueKey: $config['returnValue'] ?? null,
                preHandler: $preHandler,
                postHandler: $postHandler
            );
        }

        return $this;
    }
}