<?php

declare(strict_types=1);

namespace Drupal\action_scheduler;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for scheduled actions.
 *
 * Processed schedules cannot be deleted. Their edit form stays accessible but
 * read-only: see ScheduledActionForm.
 */
class ScheduledActionAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($operation === 'delete' && $entity instanceof ScheduledActionInterface && $entity->isProcessed()) {
      return AccessResult::forbidden('Processed scheduled actions cannot be deleted.')
        ->addCacheableDependency($entity);
    }
    return parent::checkAccess($entity, $operation, $account);
  }

}
