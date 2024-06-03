<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use InvalidArgumentException;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
final class RequestPropagationGetter implements PropagationGetterInterface {

  public static function instance(): self {
    static $instance;

    return $instance ??= new self();
  }

  /**
   * @psalm-suppress InvalidReturnType
   */
  public function keys($carrier): array {
    if ($this->isSupportedCarrier($carrier)) {
      return $carrier->headers->keys();
    }

    throw new InvalidArgumentException(
      sprintf(
        'Unsupported carrier type: %s.',
        is_object($carrier) ? get_class($carrier) : gettype($carrier),
      )
    );
  }

  public function get($carrier, string $key) : ?string {
    if ($this->isSupportedCarrier($carrier)) {
      return $carrier->headers->get($key);
    }

    throw new InvalidArgumentException(
      sprintf(
        'Unsupported carrier type: %s. Unable to get value associated with key:%s',
        is_object($carrier) ? get_class($carrier) : gettype($carrier),
        $key
      )
    );
  }

  private function isSupportedCarrier($carrier): bool {
    return $carrier instanceof Request;
  }

}
