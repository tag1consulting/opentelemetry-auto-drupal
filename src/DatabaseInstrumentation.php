<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\Core\Database\Connection;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\TraceAttributes;

/**
 *
 */
class DatabaseInstrumentation extends InstrumentationBase {
  protected const CLASSNAME = Connection::class;
  public const DB_VARIABLES = 'db.variables';

  /**
   *
   */
  public static function register(): void {
    static::create(
      name: 'io.opentelemetry.contrib.php.drupal',
      prefix: 'drupal.database',
      className: static::CLASSNAME
    );
  }

  /**
   *
   */
  protected function registerInstrumentation(): void {
    $operations = [
      'query' => [
        'preHandler' => function ($spanBuilder, $object, array $namedParams) {
          $spanBuilder
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(TraceAttributes::DB_SYSTEM, 'mariadb')
            ->setAttribute(TraceAttributes::DB_STATEMENT, $namedParams['query']);

          if (isset($namedParams['args']) === TRUE) {
            $cleanVariables = array_map(
              static fn ($value) => is_array($value) ? json_encode($value) : (string) $value,
              $namedParams['args']
            );
            $spanBuilder->setAttribute(self::DB_VARIABLES, $cleanVariables);
          }
        },
      ],
    ];

    $this->registerOperations($operations);
  }

}
