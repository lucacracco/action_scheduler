<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Hook;

use Drupal\action_scheduler\ScheduledActionExecutor;
use Drupal\action_scheduler\ScheduledActionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Hook implementations for action_scheduler.
 */
class ActionSchedulerHooks {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MessengerInterface $messenger,
    protected ScheduledActionExecutor $executor,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Queues the due schedules: the queue worker executes them on cron, within
   * its time limit.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->executor->queueDue();
  }

  /**
   * Implements hook_entity_delete().
   *
   * Pending schedules targeting a deleted entity are kept and will be marked
   * as failed when executed: warn the user so they can delete them.
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    if (!$entity instanceof ContentEntityInterface || $entity->getEntityTypeId() === 'scheduled_action') {
      return;
    }
    // Targets are stored as numeric IDs.
    if (!is_numeric($entity->id())) {
      return;
    }
    // The count must include schedules the current user cannot access.
    $query = $this->entityTypeManager->getStorage('scheduled_action')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_entity_type', $entity->getEntityTypeId())
      ->condition('target_entity_id', $entity->id())
      ->condition('status', ScheduledActionInterface::STATUS_PENDING);
    // A schedule deleting its own target is still pending while it runs.
    $running_id = $this->executor->getRunningId();
    if ($running_id !== NULL) {
      $query->condition('id', $running_id, '<>');
    }
    $count = $query->count()->execute();
    if ($count === 0) {
      return;
    }
    $this->messenger->addWarning($this->formatPlural(
      $count,
      '%label has 1 pending <a href=":url">scheduled action</a>: it will fail when executed, delete it manually.',
      '%label has @count pending <a href=":url">scheduled actions</a>: they will fail when executed, delete them manually.',
      [
        '%label' => $entity->label() ?? $entity->id(),
        ':url' => Url::fromRoute('entity.scheduled_action.collection')->toString(),
      ]
    ));
  }

}
