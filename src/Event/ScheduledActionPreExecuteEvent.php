<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Event;

use Drupal\action_scheduler\ScheduledActionInterface;
use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Event dispatched before a due schedule is executed.
 *
 * It is dispatched before any check: the action plugin, the target or the
 * owner of the schedule may no longer exist. The schedule itself must not be
 * changed: use the methods of the event instead.
 *
 * @see \Drupal\action_scheduler\Event\ScheduledActionEvents::PRE_EXECUTE
 */
class ScheduledActionPreExecuteEvent extends Event {

  /**
   * The plugin configuration used for this run.
   *
   * @var array
   */
  protected array $configuration;

  /**
   * Why the run was cancelled, or NULL if it was not.
   *
   * @var string|\Drupal\Core\StringTranslation\TranslatableMarkup|null
   */
  protected string|TranslatableMarkup|null $cancelReason = NULL;

  public function __construct(
    protected ScheduledActionInterface $schedule,
  ) {
    $this->configuration = $schedule->getActionConfiguration();
  }

  /**
   * Gets the schedule about to be executed.
   *
   * @return \Drupal\action_scheduler\ScheduledActionInterface
   *   The schedule, as stored. Its target and owner are available through
   *   getTargetEntity() and getOwner().
   */
  public function getSchedule(): ScheduledActionInterface {
    return $this->schedule;
  }

  /**
   * Gets the plugin configuration used for this run.
   *
   * @return array
   *   The configuration stored in the schedule, as changed by subscribers.
   */
  public function getConfiguration(): array {
    return $this->configuration;
  }

  /**
   * Sets the plugin configuration used for this run.
   *
   * The configuration is not saved in the schedule, nor validated by the
   * plugin configuration form.
   *
   * @param array $configuration
   *   The plugin configuration.
   *
   * @return $this
   */
  public function setConfiguration(array $configuration): static {
    $this->configuration = $configuration;
    return $this;
  }

  /**
   * Cancels the run: the schedule is marked as cancelled.
   *
   * Later subscribers are still called, and can check isCancelled().
   *
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup $reason
   *   Why the run is cancelled, logged with the schedule.
   *
   * @return $this
   */
  public function cancel(string|TranslatableMarkup $reason): static {
    $this->cancelReason = $reason;
    return $this;
  }

  /**
   * Checks whether the run was cancelled.
   *
   * @return bool
   *   TRUE if a subscriber cancelled the run.
   */
  public function isCancelled(): bool {
    return $this->cancelReason !== NULL;
  }

  /**
   * Gets why the run was cancelled.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The reason given by the last subscriber that cancelled the run, or NULL
   *   if it was not cancelled.
   */
  public function getCancelReason(): string|TranslatableMarkup|null {
    return $this->cancelReason;
  }

}
