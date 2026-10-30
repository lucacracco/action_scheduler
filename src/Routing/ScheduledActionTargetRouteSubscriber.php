<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Routing;

use Drupal\action_scheduler\Controller\ScheduledActionTargetController;
use Drupal\action_scheduler\ScheduledActionTargetResolver;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds the "Scheduled actions" page of the supported entity types.
 *
 * Each route is named entity.ENTITY_TYPE_ID.action_scheduler.
 *
 * @see \Drupal\action_scheduler\Plugin\Derivative\ScheduledActionLocalTasks
 */
class ScheduledActionTargetRouteSubscriber extends RouteSubscriberBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ScheduledActionTargetResolver $targetResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($this->targetResolver->getSupportedEntityTypeIds() as $entity_type_id) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $route = new Route(
        (string) $entity_type->getLinkTemplate(ScheduledActionTargetResolver::LINK_TEMPLATE),
        [
          '_controller' => ScheduledActionTargetController::class . '::listing',
          '_title_callback' => ScheduledActionTargetController::class . '::title',
          'entity_type_id' => $entity_type_id,
        ],
        [
          '_permission' => 'administer scheduled actions',
          '_entity_access' => "$entity_type_id.update",
        ],
        [
          'parameters' => [
            $entity_type_id => ['type' => 'entity:' . $entity_type_id],
          ],
        ],
      );
      // Use the admin theme when the edit form does. Options are only copied
      // when set: an unset _admin_route lets the system module flag /admin
      // paths, and _node_operation_route lets the node module apply its
      // "use admin theme" setting.
      $edit_route = $collection->get("entity.$entity_type_id.edit_form");
      foreach (['_admin_route', '_node_operation_route'] as $option) {
        if ($edit_route?->hasOption($option)) {
          $route->setOption($option, $edit_route->getOption($option));
        }
      }
      if ($entity_type->hasIntegerId()) {
        $route->setRequirement($entity_type_id, '\d+');
      }
      $collection->add("entity.$entity_type_id.action_scheduler", $route);
    }
  }

}
