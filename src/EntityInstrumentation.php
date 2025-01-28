<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\TraceAttributes;

final class EntityInstrumentation extends InstrumentationBase
{
  protected const CLASSNAME = SqlContentEntityStorage::class;

  public static function register(): void
  {
    static::create(
      name: 'io.opentelemetry.contrib.php.drupal_entity',
      prefix: 'drupal.entity',
      className: static::CLASSNAME
    );
  }

  protected function registerInstrumentation(): void
  {
    $operations = [
      'save' => [
        'preHandler' => function($spanBuilder, $storage, array $namedParams) {
          /** @var \Drupal\Core\Entity\EntityInterface $entity */
          $entity = $namedParams[0];
          $span = sprintf('Entity save (%s:%s)', $entity->getEntityTypeId(), $entity->isNew() ? 'new' : $entity->id());

          $spanBuilder->setName($span)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('entity.type', $entity->getEntityTypeId())
            ->setAttribute('entity.is_new', $entity->isNew())
            ->setAttribute('entity.id', $entity->id())
            ->setAttribute('entity.label', $entity->label())
            ->setAttribute('entity.bundle', $entity->bundle());
        }
      ],
      'delete' => [
        'preHandler' => function($spanBuilder, $storage, array $namedParams) {
          /** @var \Drupal\Core\Entity\EntityInterface[] $entities */
          $entities = $namedParams[0];
          if (count($entities) === 0) {
            return;
          }

          $spanBuilder->setName('Entity delete')
            ->setSpanKind(SpanKind::KIND_INTERNAL);

          $entitiesGrouped = array_reduce($entities, function (array $carry, EntityInterface $entity) {
            $carry[$entity->getEntityTypeId()][] = $entity->id();
            return $carry;
          }, []);
          
          $entitiesTag = [];
          foreach ($entitiesGrouped as $entityTypeId => $entityIds) {
            $entitiesTag[] = sprintf('%s: %s', $entityTypeId, implode(', ', $entityIds));
          }
          $spanBuilder->setAttribute('entities.deleted', implode('; ', $entitiesTag));
        }
      ]
    ];

    // Common handler to add code attributes
    $commonPreHandler = function($spanBuilder, $object, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
      $spanBuilder->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
        ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);
    };

    $this->registerOperations(
      operations: $operations,
      commonPreHandler: $commonPreHandler
    );
  }
}
