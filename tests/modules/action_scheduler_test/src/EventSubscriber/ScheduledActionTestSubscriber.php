<?php

declare(strict_types=1);

namespace Drupal\action_scheduler_test\EventSubscriber;

use Drupal\action_scheduler\Event\ScheduledActionEvents;
use Drupal\action_scheduler\Event\ScheduledActionPostExecuteEvent;
use Drupal\action_scheduler\Event\ScheduledActionPreExecuteEvent;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records the execution events, and cancels or changes runs on demand.
 *
 * Tests drive it through state:
 * - action_scheduler_test.cancel: a reason to cancel every run with;
 * - action_scheduler_test.configuration: values merged into the plugin
 *   configuration of every run;
 * - action_scheduler_test.events: the recorded events, as lists of
 *   [event name, schedule ID, status, reason].
 */
class ScheduledActionTestSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ScheduledActionEvents::PRE_EXECUTE => 'onPreExecute',
      ScheduledActionEvents::POST_EXECUTE => 'onPostExecute',
    ];
  }

  /**
   * Cancels the run or changes its configuration, as set in state.
   */
  public function onPreExecute(ScheduledActionPreExecuteEvent $event): void {
    $schedule = $event->getSchedule();
    $this->record([ScheduledActionEvents::PRE_EXECUTE, (int) $schedule->id(), $schedule->getStatus(), NULL]);
    $reason = $this->state->get('action_scheduler_test.cancel');
    if (is_string($reason)) {
      $event->cancel($reason);
    }
    $configuration = $this->state->get('action_scheduler_test.configuration');
    if (is_array($configuration)) {
      $event->setConfiguration($configuration + $event->getConfiguration());
    }
  }

  /**
   * Records the outcome of the run.
   */
  public function onPostExecute(ScheduledActionPostExecuteEvent $event): void {
    $schedule = $event->getSchedule();
    $reason = $event->getReason();
    $this->record([
      ScheduledActionEvents::POST_EXECUTE,
      (int) $schedule->id(),
      $schedule->getStatus(),
      $reason === NULL ? NULL : (string) $reason,
    ]);
  }

  /**
   * Records an event in state.
   *
   * @param array $event
   *   The event name, schedule ID, status and reason.
   */
  protected function record(array $event): void {
    $events = $this->state->get('action_scheduler_test.events', []);
    $events[] = $event;
    $this->state->set('action_scheduler_test.events', $events);
  }

}
