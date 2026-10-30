<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Plugin\Action;

use Drupal\action_scheduler\Plugin\Action\Derivative\EntityDeleteActionDeriver;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Action\Plugin\Action\EntityActionBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Deletes an entity right away.
 *
 * Unlike the core delete actions, it needs no confirmation form, so it can be
 * scheduled.
 */
#[Action(
  id: 'action_scheduler_entity_delete',
  action_label: new TranslatableMarkup('Delete immediately'),
  deriver: EntityDeleteActionDeriver::class
)]
class EntityDeleteAction extends EntityActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if ($entity instanceof EntityInterface) {
      $entity->delete();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface {
    /** @var \Drupal\Core\Entity\EntityInterface $object */
    return $object->access('delete', $account, $return_as_object);
  }

}
