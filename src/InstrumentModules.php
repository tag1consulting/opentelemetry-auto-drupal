<?php
namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\Core\DrupalKernel;

class InstrumentModules extends InstrumentationBase {
  protected const CLASSNAME = DrupalKernel::class;
  private static array $moduleInstrumentations = [];
  private static bool $isRegistered = false;

  /**
   * Register a module-based instrumentation class.
   *
   * @param string $instrumentationClass The fully qualified class name of the instrumentation
   */
  public static function registerModule(string $instrumentationClass): void {
    if (!is_subclass_of($instrumentationClass, InstrumentationBase::class)) {
      throw new \InvalidArgumentException(
        "Instrumentation class must extend InstrumentationBase"
      );
    }

    static::$moduleInstrumentations[] = $instrumentationClass;
    static::register();
  }

  public static function register(): void {
    // Register the kernel hook if not already done
    if (static::$isRegistered) {
      return;
    }

    static::create(
      name: 'io.opentelemetry.contrib.php.drupal.modules',
      prefix: 'drupal.modules',
      className: static::CLASSNAME
    );
    static::$isRegistered = true;
  }

  protected function registerInstrumentation(): void {
    // Hook into container initialization
    $this->helperHook(
      methodName: 'initializeContainer',
      postHandler: function($span, $object) {
        // Register all collected module instrumentations
        foreach (static::$moduleInstrumentations as $instrumentationClass) {
          try {
            $instrumentationClass::register();
          } catch (\Throwable $e) {
            // Optionally log the error or handle it as needed
            error_log(sprintf(
              'Failed to register module instrumentation %s: %s',
              $instrumentationClass,
              $e->getMessage()
            ));
          }
        }
      }
    );
  }
}
