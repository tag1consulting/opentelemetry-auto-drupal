<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OpenTelemetry\SemConv\TraceAttributes;

class HttpClientRequestInstrumentation extends InstrumentationBase {
  protected const CLASSNAME = Client::class;

  public static function register(): void {
    static::create(
      name: 'io.opentelemetry.contrib.php.drupal',
      prefix: 'http.client',
      className: static::CLASSNAME
    );
  }

  protected function registerInstrumentation(): void {
    $operations = [
      'requestAsync' => [
        'preHandler' => function($spanBuilder, $object, array $params) {
          $host = $params['url'];
          if (filter_var($params['url'], FILTER_VALIDATE_URL)) {
            $host = parse_url($params['url'], PHP_URL_HOST);
          }

          $spanBuilder->setName($host)
            ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $params['method'])
            ->setAttribute(TraceAttributes::URL_FULL, $params['url'])
            ->setAttribute(TraceAttributes::SERVER_ADDRESS, $host);
        },
        'postHandler' => function($span, $object, array $params, $response) {
          if ($response instanceof Promise) {
            $response = $response->wait();
          }

          if ($response instanceof GuzzleResponse) {
            $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
          }
        }
      ]
    ];

    $this->registerOperations($operations);
  }
}
