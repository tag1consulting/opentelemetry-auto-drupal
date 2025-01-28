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

  protected bool $inQuery = FALSE;

  /**
   *
   */
  protected function registerInstrumentation(): void {
    $operations = [
      'query' => [
        'preHandler' => function ($spanBuilder, Connection $connection, array $namedParams) {
          if ($this->inQuery) {
              return;
          }

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

          $this->inQuery = TRUE;
          try {
            $explain_results = $connection->query('EXPLAIN ' . $namedParams['query'], $namedParams['args'] ?? [])->fetchAll();
            $spanBuilder->setAttribute($this->getAttributeName('explain'), json_encode($explain_results));
          }
          catch (\Exception $e) {
          }

          $this->inQuery = FALSE;
        },
      ],
    ];

    $this->registerOperations($operations);
  }

}
