<?php

declare(strict_types=1);

namespace Drupal\action_scheduler;

use Drupal\Core\Action\ActionInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining a scheduled action entity type.
 */
interface ScheduledActionInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

  /**
   * Status of a schedule waiting to be executed.
   */
  const STATUS_PENDING = 'pending';

  /**
   * Status of a schedule whose action has been executed.
   */
  const STATUS_EXECUTED = 'executed';

  /**
   * Status of a schedule whose action failed to execute.
   */
  const STATUS_FAILED = 'failed';

  /**
   * Gets the creation timestamp of the schedule.
   *
   * @return int
   *   The creation timestamp.
   */
  public function getCreatedTime(): int;

  /**
   * Gets the ID of the action plugin to execute.
   *
   * @return string|null
   *   The plugin ID, or NULL if no action is set.
   */
  public function getActionPluginId(): ?string;

  /**
   * Gets the configuration of the action plugin.
   *
   * @return array
   *   The plugin configuration stored in the schedule.
   */
  public function getActionConfiguration(): array;

  /**
   * Sets the action plugin to execute and its configuration.
   *
   * @param string $plugin_id
   *   The action plugin ID.
   * @param array $configuration
   *   The plugin configuration.
   *
   * @return $this
   */
  public function setActionPlugin(string $plugin_id, array $configuration = []): static;

  /**
   * Gets the action plugin, configured as stored in the schedule.
   *
   * @return \Drupal\Core\Action\ActionInterface|null
   *   A new plugin instance, or NULL if no action is set or the plugin no
   *   longer exists.
   */
  public function getActionPlugin(): ?ActionInterface;

  /**
   * Gets the entity type ID of the target entity.
   *
   * @return string|null
   *   The entity type ID, or NULL if the schedule has no target.
   */
  public function getTargetEntityTypeId(): ?string;

  /**
   * Gets the ID of the target entity.
   *
   * @return int|null
   *   The entity ID, or NULL if the schedule has no target.
   */
  public function getTargetEntityId(): ?int;

  /**
   * Gets the entity the action is executed on.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The target entity, or NULL if the schedule has no target or the target
   *   no longer exists.
   */
  public function getTargetEntity(): ?ContentEntityInterface;

  /**
   * Sets the entity the action is executed on.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $entity
   *   The target entity, or NULL to remove the target.
   *
   * @return $this
   */
  public function setTargetEntity(?ContentEntityInterface $entity): static;

  /**
   * Gets the date the action is scheduled to run.
   *
   * @return \Drupal\Core\Datetime\DrupalDateTime|null
   *   The scheduled date, or NULL if it is not set.
   */
  public function getScheduledDate(): ?DrupalDateTime;

  /**
   * Gets the status of the schedule.
   *
   * @return string
   *   One of the STATUS_* constants.
   */
  public function getStatus(): string;

  /**
   * Sets the status of the schedule.
   *
   * @param string $status
   *   One of the STATUS_* constants.
   *
   * @return $this
   */
  public function setStatus(string $status): static;

  /**
   * Checks whether the schedule has been processed.
   *
   * Processed schedules, executed or failed, are kept as a record: they can
   * no longer be changed or deleted.
   *
   * @return bool
   *   TRUE if the schedule is no longer pending.
   */
  public function isProcessed(): bool;

  /**
   * Gets the time the action was executed or failed.
   *
   * @return int|null
   *   The timestamp, or NULL if the schedule has not run yet.
   */
  public function getExecutedTime(): ?int;

  /**
   * Sets the time the action was executed or failed.
   *
   * @param int $timestamp
   *   The timestamp.
   *
   * @return $this
   */
  public function setExecutedTime(int $timestamp): static;

}
