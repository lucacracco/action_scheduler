<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Plugin\QueueWorker;

use Drupal\action_scheduler\ScheduledActionExecutor;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Executes the scheduled actions queued on cron.
 *
 * Each item is the ID of a due schedule.
 */
#[QueueWorker(
  id: ScheduledActionExecutor::QUEUE_NAME,
  title: new TranslatableMarkup('Execute scheduled actions'),
  cron: ['time' => 60]
)]
class ScheduledActionExecute extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected ScheduledActionExecutor $executor,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(ScheduledActionExecutor::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    // The schedule is skipped if it was deleted or already run meanwhile.
    $this->executor->executeById((int) $data);
  }

}
