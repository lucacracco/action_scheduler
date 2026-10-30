<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Controller;

use Drupal\action_scheduler\ScheduledActionListBuilder;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shows the scheduled actions of an entity, on its "Scheduled actions" tab.
 *
 * @see \Drupal\action_scheduler\Routing\ScheduledActionTargetRouteSubscriber
 */
class ScheduledActionTargetController extends ControllerBase {

  /**
   * Builds the "Scheduled actions" page of an entity.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param string $entity_type_id
   *   The entity type ID of the target.
   *
   * @return array
   *   A render array.
   */
  public function listing(RouteMatchInterface $route_match, string $entity_type_id): array {
    $target = $this->getTarget($route_match, $entity_type_id);

    // The add form opens with this entity as fixed target.
    $add_url = Url::fromRoute('entity.scheduled_action.add_form', [], [
      'query' => [
        'target_type' => $entity_type_id,
        'target_id' => $target->id(),
      ] + $this->getDestinationArray(),
    ]);
    $list_builder = $this->entityTypeManager()->getListBuilder('scheduled_action');
    assert($list_builder instanceof ScheduledActionListBuilder);
    return [
      'add' => [
        '#type' => 'link',
        '#title' => $this->t('Add scheduled action'),
        '#url' => $add_url,
        '#attributes' => ['class' => ['button', 'button--action', 'button--primary']],
        '#access' => $add_url->access(),
      ],
      'schedules' => $list_builder->renderForTarget($target),
    ];
  }

  /**
   * Title callback for the "Scheduled actions" page of an entity.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param string $entity_type_id
   *   The entity type ID of the target.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   */
  public function title(RouteMatchInterface $route_match, string $entity_type_id): TranslatableMarkup {
    return $this->t('Scheduled actions for %label', [
      '%label' => $this->getTarget($route_match, $entity_type_id)->label(),
    ]);
  }

  /**
   * Gets the entity of the page.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param string $entity_type_id
   *   The entity type ID of the target.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The entity.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   If the route has no such entity.
   */
  protected function getTarget(RouteMatchInterface $route_match, string $entity_type_id): ContentEntityInterface {
    $target = $route_match->getParameter($entity_type_id);
    if (!$target instanceof ContentEntityInterface) {
      throw new NotFoundHttpException();
    }
    return $target;
  }

}
