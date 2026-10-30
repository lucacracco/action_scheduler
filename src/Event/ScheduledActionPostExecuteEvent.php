<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Event;

use Drupal\action_scheduler\ScheduledActionInterface;
use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Event dispatched after a schedule is executed, failed or cancelled.
 *
 * @see \Drupal\action_scheduler\Event\ScheduledActionEvents::POST_EXECUTE
 */
class ScheduledActionPostExecuteEvent extends Event {

  public function __construct(
    protected ScheduledActionInterface $schedule,
    protected string|TranslatableMarkup|null $reason = NULL,
  ) {}

  /**
   * Gets the schedule, saved with its final status.
   *
   * @return \Drupal\action_scheduler\ScheduledActionInterface
   *   The schedule: getStatus() tells the outcome.
   */
  public function getSchedule(): ScheduledActionInterface {
    return $this->schedule;
  }

  /**
   * Gets why the schedule failed or was cancelled.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The reason, or NULL if the action was executed.
   */
  public function getReason(): string|TranslatableMarkup|null {
    return $this->reason;
  }

}
