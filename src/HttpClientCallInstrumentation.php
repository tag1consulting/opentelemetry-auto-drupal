<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\API\Trace\SpanKind;

class HttpClientCallInstrumentation extends InstrumentationBase {
  protected const CLASSNAME = Client::class;

  public static function register(): void {
    static::create(
      name: 'io.opentelemetry.contrib.php.drupal',
      prefix: 'drupal.http_client',
      className: static::CLASSNAME
    );
  }

  protected function registerInstrumentation(): void {
    $this->helperHook(
      methodName: '__call',
      paramMap: [],
      preHandler: function($spanBuilder, $object, array $params) {
        $url = is_array($params[1]) ? $params[1][0] ?? null : $params[1];
        
        $host = filter_var($url, FILTER_VALIDATE_URL)
          ? parse_url($url, PHP_URL_HOST)
          : ($url ?? 'unknown-http-client');

        $spanBuilder
          ->setName($host)
          ->setSpanKind(SpanKind::KIND_CLIENT)
          ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $params[0])
          ->setAttribute(TraceAttributes::URL_FULL, $params[1])
          ->setAttribute(TraceAttributes::SERVER_ADDRESS, $host);
      },
      postHandler: function($span, $object, array $params, $response) {
        if ($response instanceof GuzzleResponse) {
          $span->setAttribute(TraceAttributes::HTTP_STATUS_CODE, $response->getStatusCode());
        }
      }
    );
  }
}
