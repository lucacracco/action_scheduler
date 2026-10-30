<?php

declare(strict_types=1);

namespace Drupal\action_scheduler;

use Drupal\action_scheduler\Event\ScheduledActionEvents;
use Drupal\action_scheduler\Event\ScheduledActionPostExecuteEvent;
use Drupal\action_scheduler\Event\ScheduledActionPreExecuteEvent;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Action\ActionInterface;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Executes scheduled actions that are due.
 *
 * Each action runs as the owner of its schedule: if the owner may not perform
 * it, or its target no longer exists, the schedule is marked as failed. Other
 * modules can cancel a run, or change the plugin configuration it uses, and
 * react to its outcome.
 *
 * @see \Drupal\action_scheduler\Event\ScheduledActionEvents
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
    #[Autowire(service: 'plugin.manager.action')]
    protected ActionManager $actionManager,
    protected EventDispatcherInterface $eventDispatcher,
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
   * A schedule still in the queue from a previous run is queued again: the
   * extra item is skipped once the schedule has been executed.
   *
   * @return int
   *   The number of schedules queued.
   */
  public function queueDue(): int {
    $queue = $this->queueFactory->get(static::QUEUE_NAME);
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
   *   The number of schedules executed, failed and cancelled, keyed by
   *   status.
   */
  public function executeDue(): array {
    $counts = [
      ScheduledActionInterface::STATUS_EXECUTED => 0,
      ScheduledActionInterface::STATUS_FAILED => 0,
      ScheduledActionInterface::STATUS_CANCELLED => 0,
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
   *   The new status, ScheduledActionInterface::STATUS_EXECUTED,
   *   ScheduledActionInterface::STATUS_FAILED or
   *   ScheduledActionInterface::STATUS_CANCELLED, or NULL if the schedule is
   *   not pending.
   */
  public function execute(ScheduledActionInterface $schedule): ?string {
    if ($schedule->getStatus() !== ScheduledActionInterface::STATUS_PENDING) {
      return NULL;
    }

    $status = ScheduledActionInterface::STATUS_FAILED;
    $event = new ScheduledActionPreExecuteEvent($schedule);
    try {
      $this->eventDispatcher->dispatch($event, ScheduledActionEvents::PRE_EXECUTE);
      $reason = $event->getCancelReason();
      if ($reason !== NULL) {
        $status = ScheduledActionInterface::STATUS_CANCELLED;
      }
    }
    catch (\Throwable $e) {
      $reason = $e->getMessage();
    }

    if ($reason === NULL) {
      $this->runningId = (int) $schedule->id();
      try {
        $reason = $this->run($schedule, $event->getConfiguration());
      }
      finally {
        $this->runningId = NULL;
      }
      if ($reason === NULL) {
        $status = ScheduledActionInterface::STATUS_EXECUTED;
      }
    }

    $schedule->setStatus($status)
      ->setExecutedTime($this->time->getCurrentTime())
      ->save();

    $context = [
      '%label' => $schedule->label(),
      '@id' => $schedule->id(),
      '@reason' => $reason ?? '',
      'link' => $schedule->toLink($this->t('Edit'), 'edit-form')->toString(),
    ];
    match ($status) {
      ScheduledActionInterface::STATUS_EXECUTED => $this->logger->info('Executed scheduled action %label (ID @id).', $context),
      ScheduledActionInterface::STATUS_CANCELLED => $this->logger->warning('Scheduled action %label (ID @id) was cancelled: @reason', $context),
      default => $this->logger->error('Scheduled action %label (ID @id) failed: @reason', $context),
    };

    // The schedule is saved: a failing subscriber must not stop the run.
    try {
      $this->eventDispatcher->dispatch(new ScheduledActionPostExecuteEvent($schedule, $reason), ScheduledActionEvents::POST_EXECUTE);
    }
    catch (\Throwable $e) {
      $this->logger->error('A subscriber to the execution of scheduled action %label (ID @id) failed: @reason', ['@reason' => $e->getMessage()] + $context);
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
   * @param array $configuration
   *   The plugin configuration, as changed by the pre-execute event.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string|null
   *   Why the action failed, or NULL if it was executed.
   */
  protected function run(ScheduledActionInterface $schedule, array $configuration): TranslatableMarkup|string|null {
    $plugin_id = (string) $schedule->getActionPluginId();
    if (!$this->targetResolver->isSchedulable($plugin_id)) {
      return $this->t('the action plugin @plugin does not exist or cannot be scheduled.', ['@plugin' => $plugin_id]);
    }
    try {
      $plugin = $this->actionManager->createInstance($plugin_id, $configuration);
    }
    catch (\Throwable $e) {
      return $e->getMessage();
    }
    if (!$plugin instanceof ActionInterface) {
      return $this->t('the action plugin @plugin does not exist or cannot be scheduled.', ['@plugin' => $plugin_id]);
    }

    $target = NULL;
    $target_type = $this->targetResolver->getTargetEntityTypeId($plugin_id);
    if ($target_type !== NULL) {
      $target = $schedule->getTargetEntity();
      if ($target === NULL || $target->getEntityTypeId() !== $target_type) {
        return $this->t('the target @type @id no longer exists.', [
          '@type' => $schedule->getTargetEntityTypeId(),
          '@id' => $schedule->getTargetEntityId(),
        ]);
      }
    }

    // getOwner() returns NULL once the owner is deleted.
    $owner = $schedule->getOwner();
    if ($owner === NULL) {
      return $this->t('the owner of the schedule no longer exists.');
    }

    $this->accountSwitcher->switchTo($owner);
    try {
      $access = $plugin->access($target, $owner, TRUE);
      if (!$access->isAllowed()) {
        return $this->t('@user is not allowed to perform the action.', ['@user' => $owner->getDisplayName()]);
      }
      // Actions take their target as argument, which ExecutableInterface
      // does not declare.
      // @phpstan-ignore arguments.count
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
