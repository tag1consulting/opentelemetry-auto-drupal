<?php
namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\Core\Cache\CacheBackendInterface;

class CacheBackendInstrumentation extends InstrumentationBase2 {
  protected const CLASSNAME = CacheBackendInterface::class;
  protected static array $cacheBins = [];

  public static function register(): void {
    static::create(
      name: 'io.opentelemetry.contrib.php.drupal',
      prefix: 'drupal.cache',
      className: static::CLASSNAME
    );
  }

  protected function registerInstrumentation(): void {
    // Capture bin name in constructor
    $this->helperHook(
      methodName: '__construct',
      preHandler: function($spanBuilder, $object, array $params, string $class) {
        // Get actual constructor parameters at runtime
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if (!$constructor) {
          return;
        }

        foreach ($constructor->getParameters() as $position => $parameter) {
          if ($parameter->getName() === 'bin' && isset($params[$position])) {
            $bin = $params[$position];
            $spanBuilder->setAttribute($this->getAttributeName('bin'), $bin);
            static::$cacheBins[spl_object_id($object)] = $bin;
            break;
          }
        }
      }
    );

    // Define operations
    $operations = [
      'get' => [
        'params' => ['cid' => 'cache_key'],
        'postHandler' => function($span, $object, array $namedParams, $returnValue) {
          $span->setAttribute($this->getAttributeName('hit'), $returnValue !== FALSE);
          $span->setAttribute($this->getAttributeName('valid'), !empty($returnValue->valid));
        }
      ],
      'getMultiple' => [
        'params' => ['cids' => 'cache_keys'],
        'postHandler' => function($span, $object, array $namedParams, $returnValue) {
          $missCount = count($namedParams['cids']);
          $hitCount = count($returnValue);
          $total = $missCount + $hitCount;

          $span->setAttribute($this->getAttributeName('hit_count'), $hitCount);
          $span->setAttribute($this->getAttributeName('miss_count'), $missCount);
          $span->setAttribute($this->getAttributeName('hit_ratio'), $total > 0 ? $hitCount / $total : 0);
        }
      ],
      'set' => [
        'params' => ['cid' => 'cache_key', 'tags'],
        'preHandler' => function($spanBuilder, $object, array $namedParams) {
          $spanBuilder->setAttribute($this->getAttributeName('ttl'), isset($namedParams['expire']) ? $namedParams['expire'] : -1);
          $spanBuilder->setAttribute($this->getAttributeName('tag_count'), count($namedParams['tags'] ?? []));
        }
      ],
      'deleteMultiple' => [
        'params' => ['cids' => 'cache_keys'],
        'preHandler' => function($spanBuilder, $object, array $namedParams) {
          $spanBuilder->setAttribute($this->getAttributeName('delete_count'), count($namedParams['cids']));
        }
      ],
      'invalidateMultiple' => [
        'params' => ['cids' => 'cache_keys'],
        'preHandler' => function($spanBuilder, $object, array $namedParams) {
          $spanBuilder->setAttribute($this->getAttributeName('invalidate_count'), count($namedParams['cids']));
        }
      ],
      'delete' => [
        'params' => ['cid' => 'cache_key']
      ],
      'invalidate' => [
        'params' => ['cid' => 'cache_key']
      ],
      'deleteAll' => [],
      'invalidateAll' => [],
      'removeBin' => []
    ];

    // Common handler for adding bin information
    $binHandler = function($spanBuilder, $object) {
      $objectId = spl_object_id($object);
      $bin = static::$cacheBins[$objectId] ?? 'unknown';
      $spanBuilder->setAttribute($this->getAttributeName('bin'), $bin);
    };

    // Register all operations with common bin handling
    $this->registerOperations(
      operations: $operations,
      commonPreHandler: $binHandler
    );
  }
}
