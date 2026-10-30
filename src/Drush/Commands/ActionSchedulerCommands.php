<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Drush\Commands;

use Drupal\action_scheduler\ScheduledActionExecutor;
use Drupal\action_scheduler\ScheduledActionInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for action_scheduler.
 */
final class ActionSchedulerCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected ScheduledActionExecutor $executor,
  ) {
    parent::__construct();
  }

  /**
   * Executes the scheduled actions that are due, without waiting for cron.
   */
  #[CLI\Command(name: 'action-scheduler:run', aliases: ['asr'])]
  #[CLI\Usage(name: 'drush action-scheduler:run', description: 'Execute all pending scheduled actions whose date has passed.')]
  public function run(): void {
    $counts = $this->executor->executeDue();
    $executed = $counts[ScheduledActionInterface::STATUS_EXECUTED];
    $failed = $counts[ScheduledActionInterface::STATUS_FAILED];
    $cancelled = $counts[ScheduledActionInterface::STATUS_CANCELLED];
    if ($executed + $failed + $cancelled === 0) {
      $this->logger()->notice('No scheduled actions are due.');
      return;
    }
    $this->logger()->success(dt('Executed @executed scheduled action(s), @failed failed, @cancelled cancelled.', [
      '@executed' => $executed,
      '@failed' => $failed,
      '@cancelled' => $cancelled,
    ]));
    if ($failed + $cancelled > 0) {
      $this->logger()->warning(dt('See the action_scheduler entries of the site log for the reasons.'));
    }
  }

}
