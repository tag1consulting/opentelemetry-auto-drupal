<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\Core\Database\Connection;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\TraceAttributes;

/**
 * Provides OpenTelemetry instrumentation for Drupal database operations.
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

  protected bool $explainQueries = FALSE;
  /**
   * Minimum duration threshold (in seconds) for capturing EXPLAIN results.
   *
   * When explainQueries is enabled, only queries that take longer than this
   * threshold will have their EXPLAIN results captured in the span. This helps
   * prevent unnecessary overhead for fast queries.
   *
   * Configure via OTEL_PHP_DRUPAL_EXPLAIN_THRESHOLD environment variable.
   * Value should be in milliseconds, e.g.:
   * OTEL_PHP_DRUPAL_EXPLAIN_THRESHOLD=100 // For 100ms threshold
   *
   * @var float Time in seconds (e.g., 0.1 for 100ms)
   */
  protected float $explainQueriesThreshold = 0.0;

  /**
   *
   */
  protected function registerInstrumentation(): void {

    $this->explainQueries = getenv('OTEL_PHP_DRUPAL_EXPLAIN_QUERIES') ? TRUE : FALSE;
    $this->explainQueriesThreshold = (float) (getenv('OTEL_PHP_DRUPAL_EXPLAIN_THRESHOLD') ?? 0) / 1000;

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

          if ($this->explainQueries) {
            $this->captureQueryExplain(
              $spanBuilder,
              $connection,
              $namedParams['query'],
              $namedParams['args'] ?? []
            );
          }
        },
      ],
    ];

    $this->registerOperations($operations);
  }

  /**
   * Captures and adds EXPLAIN query results as attributes to the span builder.
   *
   * This function executes an EXPLAIN query and adds the results as attributes
   * to the span builder if the query execution time exceeds the configured threshold.
   *
   * @param mixed $spanBuilder The span builder instance
   * @param \Drupal\Core\Database\Connection $connection Database connection
   * @param string $query The SQL query to explain
   * @param array $args Query arguments
   */
  protected function captureQueryExplain($spanBuilder, Connection $connection, string $query, array $args): void {
    $this->inQuery = TRUE;
    try {
      $start = microtime(true);
      $explain_results = $connection->query('EXPLAIN ' . $query, $args)->fetchAll();
      $duration = microtime(true) - $start;

      if ($duration >= $this->explainQueriesThreshold) {
        $spanBuilder->setAttribute($this->getAttributeName('explain'), json_encode($explain_results));
      }
    }
    catch (\Exception $e) {
    }
    $this->inQuery = FALSE;
  }

}
