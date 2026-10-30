<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Plugin\Derivative;

use Drupal\action_scheduler\ScheduledActionTargetResolver;
use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a "Scheduled actions" tab for each supported entity type.
 *
 * @see \Drupal\action_scheduler\Routing\ScheduledActionTargetRouteSubscriber
 */
class ScheduledActionLocalTasks extends DeriverBase implements ContainerDeriverInterface {

  public function __construct(
    protected ScheduledActionTargetResolver $targetResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static(
      $container->get(ScheduledActionTargetResolver::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    $this->derivatives = [];
    foreach ($this->targetResolver->getSupportedEntityTypeIds() as $entity_type_id) {
      $this->derivatives[$entity_type_id] = [
        'title' => new TranslatableMarkup('Scheduled actions'),
        'route_name' => "entity.$entity_type_id.action_scheduler",
        'base_route' => "entity.$entity_type_id.canonical",
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
