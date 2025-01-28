<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\Core\Database\Connection;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\TraceAttributes;

class DatabaseInstrumentation extends InstrumentationBase {
  protected const CLASSNAME = Connection::class;
  public const DB_VARIABLES = 'db.variables';

  public static function register(): void {
    static::create(
      name: 'io.opentelemetry.contrib.php.drupal',
      prefix: 'drupal.database',
      className: static::CLASSNAME
    );
  }

  protected function registerInstrumentation(): void {
    $operations = [
      'query' => [
        'params' => [],
        'preHandler' => function($spanBuilder, $object, array $params) {
          $spanBuilder
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(TraceAttributes::DB_SYSTEM, 'mariadb')
            ->setAttribute(TraceAttributes::DB_STATEMENT, $params[0]);

          if (isset($params[1]) === TRUE) {
            $cleanVariables = array_map(
              static fn ($value) => is_array($value) ? json_encode($value) : (string) $value,
              $params[1]
            );
            $spanBuilder->setAttribute(self::DB_VARIABLES, $cleanVariables);
          }
        }
      ]
    ];

    $this->registerOperations($operations);
  }
}
