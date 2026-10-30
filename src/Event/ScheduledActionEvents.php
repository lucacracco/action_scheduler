<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Event;

/**
 * Defines the events dispatched when a scheduled action is executed.
 *
 * @see \Drupal\action_scheduler\ScheduledActionExecutor::execute()
 */
final class ScheduledActionEvents {

  /**
   * Dispatched before a due schedule is executed, before any check.
   *
   * Subscribers can change the plugin configuration used for this run, or
   * cancel the run.
   *
   * @Event
   *
   * @see \Drupal\action_scheduler\Event\ScheduledActionPreExecuteEvent
   */
  const PRE_EXECUTE = 'action_scheduler.pre_execute';

  /**
   * Dispatched after a schedule is executed, failed or cancelled.
   *
   * The schedule has already been saved with its final status.
   *
   * @Event
   *
   * @see \Drupal\action_scheduler\Event\ScheduledActionPostExecuteEvent
   */
  const POST_EXECUTE = 'action_scheduler.post_execute';

}
