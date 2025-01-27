<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\Core\Cache\CacheBackendInterface;

require_once __DIR__ . '/../opentelemery-php-instrumentation-trait/src/InstrumentationTrait.php';
use PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait;

class CacheBackendInstrumentation {
  use InstrumentationTrait;

  protected const CLASSNAME = CacheBackendInterface::class;

  public static function register(): void {
    static::initialize(name: 'io.opentelemetry.contrib.php.drupal', prefix: 'drupal.cache');

    // Get single item - track hits/misses
    static::helperHook(
      self::CLASSNAME,
      'get',
      ['cid', 'allowInvalid'],
      null,
      postHandler: function($span, $object, array $params, $returnValue) {
        $span->setAttribute(static::getAttributeName('hit'), $returnValue !== FALSE);
        $span->setAttribute(static::getAttributeName('valid'), !empty($returnValue->valid));
      }
    );

    // Get multiple items - track hit ratio
    static::helperHook(
      self::CLASSNAME,
      'getMultiple',
      ['cids'],
      null,
      postHandler: function($span, $object, array $params, $returnValue) {
        $missCount = count($params[0]);
        $hitCount = count($returnValue);
        $total = $missCount + $hitCount;

        $span->setAttribute(static::getAttributeName('hit_count'), $hitCount);
        $span->setAttribute(static::getAttributeName('miss_count'), $missCount);
        $span->setAttribute(static::getAttributeName('hit_ratio'), $total > 0 ? $hitCount / $total : 0);
      }
    );

    // Set - track TTL and tags
    static::helperHook(
      self::CLASSNAME,
      'set',
      ['cid', 'ttl', 'tags'],
      null,
      preHandler: function($spanBuilder, $object, array $params) {
        $spanBuilder->setAttribute(static::getAttributeName('tag_count'), count($params[3] ?? []));
      }
    );

    // Delete operations - track counts
    static::helperHook(
      self::CLASSNAME,
      'deleteMultiple',
      ['cids'],
      null,
      preHandler: function($spanBuilder, $object, array $params) {
        $spanBuilder->setAttribute(static::getAttributeName('delete_count'), count($params[0]));
      }
    );

    // Invalidate operations - track counts
    static::helperHook(
      self::CLASSNAME,
      'invalidateMultiple',
      ['cids'],
      null,
      preHandler: function($spanBuilder, $object, array $params) {
        $spanBuilder->setAttribute(static::getAttributeName('invalidate_count'), count($params[0]));
      }
    );

    // Simple operations without additional attributes
    static::helperHook(self::CLASSNAME, 'delete', ['cid']);
    static::helperHook(self::CLASSNAME, 'invalidate', ['cid']);
    static::helperHook(self::CLASSNAME, 'deleteAll');
    static::helperHook(self::CLASSNAME, 'invalidateAll');
    static::helperHook(self::CLASSNAME, 'removeBin');
  }
}
