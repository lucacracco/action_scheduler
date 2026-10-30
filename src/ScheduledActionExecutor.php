<?php

declare(strict_types=1);

namespace Drupal\action_scheduler;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Executes scheduled actions that are due.
 *
 * Each action runs as the owner of its schedule: if the owner may not perform
 * it, or its target no longer exists, the schedule is marked as failed.
 */
class ScheduledActionExecutor {

  use StringTranslationTrait;

  /**
   * The queue holding the IDs of the schedules to execute on cron.
   */
  const QUEUE_NAME = 'action_scheduler_execute';

  /**
   * The ID of the schedule whose action is running, if any.
   *
   * @var int|null
   */
  protected ?int $runningId = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ScheduledActionTargetResolver $targetResolver,
    protected AccountSwitcherInterface $accountSwitcher,
    protected TimeInterface $time,
    protected QueueFactory $queueFactory,
    #[Autowire(service: 'logger.channel.action_scheduler')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Gets the pending schedules whose date has passed, oldest first.
   *
   * @return int[]
   *   The schedule IDs.
   */
  public function getDueIds(): array {
    $now = DrupalDateTime::createFromTimestamp($this->time->getCurrentTime(), new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE))
      ->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
    // System operation: due schedules run whoever triggers cron.
    $ids = $this->entityTypeManager->getStorage('scheduled_action')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', ScheduledActionInterface::STATUS_PENDING)
      ->condition('scheduled', $now, '<=')
      ->sort('scheduled')
      ->sort('id')
      ->execute();
    return array_map('intval', array_values($ids));
  }

  /**
   * Queues the due schedules for execution.
   *
   * Nothing is queued while the queue still holds items, so a schedule is not
   * queued again before its previous queue item is processed.
   *
   * @return int
   *   The number of schedules queued.
   */
  public function queueDue(): int {
    $queue = $this->queueFactory->get(static::QUEUE_NAME);
    if ($queue->numberOfItems() > 0) {
      return 0;
    }
    $ids = $this->getDueIds();
    foreach ($ids as $id) {
      $queue->createItem($id);
    }
    return count($ids);
  }

  /**
   * Executes the due schedules right away.
   *
   * @return array<string, int>
   *   The number of schedules executed and failed, keyed by status.
   */
  public function executeDue(): array {
    $counts = [
      ScheduledActionInterface::STATUS_EXECUTED => 0,
      ScheduledActionInterface::STATUS_FAILED => 0,
    ];
    foreach ($this->getDueIds() as $id) {
      $status = $this->executeById($id);
      if ($status !== NULL) {
        $counts[$status]++;
      }
    }
    return $counts;
  }

  /**
   * Executes a schedule, loading it again to get its current status.
   *
   * @param int $id
   *   The schedule ID.
   *
   * @return string|null
   *   The new status, or NULL if the schedule no longer exists or is not
   *   pending.
   */
  public function executeById(int $id): ?string {
    $schedule = $this->entityTypeManager->getStorage('scheduled_action')
      ->loadUnchanged($id);
    return $schedule instanceof ScheduledActionInterface
      ? $this->execute($schedule)
      : NULL;
  }

  /**
   * Executes a pending schedule and records the outcome.
   *
   * @param \Drupal\action_scheduler\ScheduledActionInterface $schedule
   *   The schedule.
   *
   * @return string|null
   *   The new status, ScheduledActionInterface::STATUS_EXECUTED or
   *   ScheduledActionInterface::STATUS_FAILED, or NULL if the schedule is not
   *   pending.
   */
  public function execute(ScheduledActionInterface $schedule): ?string {
    if ($schedule->getStatus() !== ScheduledActionInterface::STATUS_PENDING) {
      return NULL;
    }

    $this->runningId = (int) $schedule->id();
    try {
      $error = $this->run($schedule);
    }
    finally {
      $this->runningId = NULL;
    }
    $status = $error === NULL
      ? ScheduledActionInterface::STATUS_EXECUTED
      : ScheduledActionInterface::STATUS_FAILED;
    $schedule->setStatus($status)
      ->setExecutedTime($this->time->getCurrentTime())
      ->save();

    $context = [
      '%label' => $schedule->label(),
      '@id' => $schedule->id(),
      'link' => $schedule->toLink($this->t('Edit'), 'edit-form')->toString(),
    ];
    if ($error === NULL) {
      $this->logger->info('Executed scheduled action %label (ID @id).', $context);
    }
    else {
      $this->logger->error('Scheduled action %label (ID @id) failed: @reason', $context + ['@reason' => $error]);
    }
    return $status;
  }

  /**
   * Gets the schedule whose action is running.
   *
   * @return int|null
   *   The schedule ID, or NULL if no action is running.
   */
  public function getRunningId(): ?int {
    return $this->runningId;
  }

  /**
   * Runs the action of a schedule as the schedule owner.
   *
   * @param \Drupal\action_scheduler\ScheduledActionInterface $schedule
   *   The schedule.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string|null
   *   Why the action failed, or NULL if it was executed.
   */
  protected function run(ScheduledActionInterface $schedule): TranslatableMarkup|string|null {
    $plugin_id = (string) $schedule->getActionPluginId();
    $plugin = $schedule->getActionPlugin();
    if (!$plugin || !$this->targetResolver->isSchedulable($plugin_id)) {
      return $this->t('the action plugin @plugin does not exist or cannot be scheduled.', ['@plugin' => $plugin_id]);
    }

    $target = NULL;
    $target_type = $this->targetResolver->getTargetEntityTypeId($plugin_id);
    if ($target_type) {
      $target = $schedule->getTargetEntity();
      if (!$target || $target->getEntityTypeId() !== $target_type) {
        return $this->t('the target @type @id no longer exists.', [
          '@type' => $schedule->getTargetEntityTypeId(),
          '@id' => $schedule->getTargetEntityId(),
        ]);
      }
    }

    $owner = $schedule->getOwner();
    if (!$owner) {
      return $this->t('the owner of the schedule no longer exists.');
    }

    $this->accountSwitcher->switchTo($owner);
    try {
      $access = $plugin->access($target, $owner, TRUE);
      if (!$access->isAllowed()) {
        return $this->t('@user is not allowed to perform the action.', ['@user' => $owner->getDisplayName()]);
      }
      $plugin->execute($target);
    }
    catch (\Throwable $e) {
      return $e->getMessage();
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
    return NULL;
  }

}
