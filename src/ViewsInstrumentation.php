<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\TraceAttributes;
use Drupal\views\ViewExecutable;

class ViewsInstrumentation extends InstrumentationBase {
  protected const CLASSNAME = ViewExecutable::class;

  public static function register(): void {
    static::create(
      name: 'io.opentelemetry.contrib.php.drupal_views',
      prefix: 'drupal.view',
      className: static::CLASSNAME
    );
  }

  protected function registerInstrumentation(): void {
    $operations = [
      'executeDisplay' => [
        'preHandler' => function($spanBuilder, ViewExecutable $executable, array $namedParams) {
          $display_id = $namedParams['display_id'] ?? null;
          $name = null;
          
          if ($executable->storage) {
            $name = $executable->storage->label();
          }

          $spanName = 'VIEW';
          if ($name) {
            $spanName .= ' ' . $name;
          }

          $spanBuilder->setName($spanName);
          $spanBuilder->setAttribute($this->getAttributeName('name'), $name);
          $spanBuilder->setAttribute($this->getAttributeName('display_id'), $display_id);
        }
      ]
    ];

    $this->registerOperations($operations);
  }
}
