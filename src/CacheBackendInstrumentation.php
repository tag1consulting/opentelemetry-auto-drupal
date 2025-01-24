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

    static::helperHook(
      self::CLASSNAME,
      'get',
      ['cid'],
      'returnValue'
    );

    static::helperHook(
      self::CLASSNAME,
      'getMultiple',
      ['cids' => 'cacheIds'],
      'returnValue'
    );

    static::helperHook(
      self::CLASSNAME,
      'set',
      ['cid', 'data', 'expire', 'tags'],
      'returnValue'
    );

    static::helperHook(
      self::CLASSNAME,
      'delete',
      ['cid'],
      'returnValue'
    );

    static::helperHook(
      self::CLASSNAME,
      'deleteMultiple',
      ['cids'],
      'returnValue'
    );

    static::helperHook(
      self::CLASSNAME,
      'deleteAll',
      [],
      'returnValue'
    );

    static::helperHook(
      self::CLASSNAME,
      'invalidate',
      ['cid'],
      'returnValue'
    );

    static::helperHook(
      self::CLASSNAME,
      'invalidateMultiple',
      ['cids'],
      'returnValue'
    );

    static::helperHook(
      self::CLASSNAME,
      'invalidateAll',
      [],
      'returnValue'
    );

    static::helperHook(
      self::CLASSNAME,
      'removeBin',
      [],
      'returnValue'
    );
  }
}
