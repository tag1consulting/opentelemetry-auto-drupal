<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\TraceAttributes;

/**
 *
 */
class HttpClientRequestInstrumentation extends InstrumentationBase {
  protected const CLASSNAME = Client::class;

  /**
   *
   */
  public static function register(): void {
    static::create(
      name: 'io.opentelemetry.contrib.php.drupal',
      prefix: 'http.client',
      className: static::CLASSNAME
    );
  }

  /**
   *
   */
  protected function registerInstrumentation(): void {
    $operations = [
      'requestAsync' => [
        'preHandler' => function ($spanBuilder, Client $client, array $namedParams) {
          $host = $namedParams['uri'];
          if (filter_var($namedParams['uri'], FILTER_VALIDATE_URL)) {
            $host = parse_url($namedParams['uri'], PHP_URL_HOST);
          }

          $spanBuilder->setName($host)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $namedParams['method'])
            ->setAttribute(TraceAttributes::URL_FULL, $namedParams['uri'])
            ->setAttribute(TraceAttributes::SERVER_ADDRESS, $host);
        },
        'postHandler' => function ($span, Client $client, array $namedParams, $response) {
          if ($response instanceof Promise) {
            $response = $response->wait();
          }

          if ($response instanceof GuzzleResponse) {
            $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
          }
        },
      ],
    ];

    $this->registerOperations($operations);
  }

}
