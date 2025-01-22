<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;

final class EntityInstrumentation
{
    public const NAME = 'entity';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.drupal_entity');

        hook(
            SqlContentEntityStorage::class,
            'save',
            pre: static function (
                EntityStorageInterface $storage,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                /** @var \Drupal\Core\Entity\EntityInterface $entity */
                $entity = $params[0];
                $span = \sprintf('Entity save (%s:%s)', $entity->getEntityTypeId(), $entity->isNew() ? 'new' : $entity->id());

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder($span)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute('entity.type', $entity->getEntityTypeId())
                    ->setAttribute('entity.is_new', $entity->isNew())
                    ->setAttribute('entity.id', $entity->id())
                    ->setAttribute('entity.label', $entity->label())
                    ->setAttribute('entity.bundle', $entity->bundle())
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

                $parent = Context::getCurrent();
                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $context = $span->storeInContext($parent);
                Context::storage()->attach($context);

                return $params;
            },
            post: static function (
                SqlContentEntityStorage $storage,
                array $params,
                ?int $return,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }

                $scope->detach();

                $span = Span::fromContext($scope->context());

                $span->end();
            }
        );

        hook(
            SqlContentEntityStorage::class,
            'delete',
            pre: static function (
              EntityStorageInterface $storage,
              array $params,
              string $class,
              string $function,
              ?string $filename,
              ?int $lineno,
            ) use ($instrumentation): array {
              /** @var \Drupal\Core\Entity\EntityInterface[] $entities */
              $entities = $params[0];
              if (count($entities) === 0) {
                return $params;
              }

              /** @psalm-suppress ArgumentTypeCoercion */
              $builder = $instrumentation
                ->tracer()
                ->spanBuilder('Entity delete')
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

              $entitiesGrouped = \array_reduce($entities, function (array $carry, EntityInterface $entity) {
                $carry[$entity->getEntityTypeId()][] = $entity->id();
                return $carry;
              }, []);
              $entitiesTag = [];
              foreach ($entitiesGrouped as $entityTypeId => $entityIds) {
                $entitiesTag[] = \sprintf('%s: %s', $entityTypeId, \implode(', ', $entityIds));
              }
              $builder->setAttribute('entities.deleted', \implode('; ', $entitiesTag));

              $parent = Context::getCurrent();
              $span = $builder
                ->setParent($parent)
                ->startSpan();

              $context = $span->storeInContext($parent);
              Context::storage()->attach($context);

              return $params;
            },
            post: static function (
              SqlContentEntityStorage $storage,
              array $params,
              ?\Throwable $exception
            ): void {
              $scope = Context::storage()->scope();
              if (null === $scope) {
                return;
              }

              $scope->detach();

              $span = Span::fromContext($scope->context());

              $span->end();
            }
        );
    }

}
