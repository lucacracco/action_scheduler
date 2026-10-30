<?php

declare(strict_types=1);

namespace Drupal\action_scheduler;

use Drupal\Core\Action\ActionManager;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Works out which target entity, if any, an action plugin needs.
 *
 * Action plugins declare in their "type" either the ID of the entity type
 * they apply to (e.g. "node") or "system" for actions that run on their own
 * (e.g. sending an email).
 */
class ScheduledActionTargetResolver {

  use StringTranslationTrait;

  /**
   * The link template of the "Scheduled actions" tab of target entities.
   *
   * @see \Drupal\action_scheduler\Hook\ActionSchedulerEntityTypeHooks
   */
  const LINK_TEMPLATE = 'action-scheduler';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'plugin.manager.action')]
    protected ActionManager $actionManager,
  ) {}

  /**
   * Gets the entity type an action plugin must be executed on.
   *
   * @param string $plugin_id
   *   The action plugin ID.
   *
   * @return string|null
   *   The ID of the content entity type the action needs a target of, or NULL
   *   if the action runs without a target or the plugin does not exist.
   */
  public function getTargetEntityTypeId(string $plugin_id): ?string {
    $definition = $this->actionManager->getDefinition($plugin_id, FALSE);
    $type = $definition['type'] ?? NULL;
    if (!is_string($type) || $type === '') {
      return NULL;
    }
    $entity_type = $this->entityTypeManager->getDefinition($type, FALSE);
    // Targets are stored as numeric IDs, so only content entity types apply.
    if ($entity_type === NULL || !$entity_type->entityClassImplements(ContentEntityInterface::class)) {
      return NULL;
    }
    return $type;
  }

  /**
   * Checks whether an action plugin can be scheduled.
   *
   * Actions on scheduled actions themselves are excluded.
   *
   * @param string $plugin_id
   *   The action plugin ID.
   *
   * @return bool
   *   TRUE if the plugin exists and can be scheduled.
   */
  public function isSchedulable(string $plugin_id): bool {
    $definition = $this->actionManager->getDefinition($plugin_id, FALSE);
    return $definition !== NULL && ($definition['type'] ?? NULL) !== 'scheduled_action';
  }

  /**
   * Checks whether an action plugin relies on a confirmation form.
   *
   * Such actions (e.g. "Delete content" or "Cancel the selected user
   * accounts") only prepare a confirmation form for the user who triggers
   * them: run from a schedule, they do nothing.
   *
   * @param string $plugin_id
   *   The action plugin ID.
   *
   * @return bool
   *   TRUE if the plugin declares a confirmation form route.
   */
  public function needsConfirmation(string $plugin_id): bool {
    $definition = $this->actionManager->getDefinition($plugin_id, FALSE);
    $route_name = $definition['confirm_form_route_name'] ?? NULL;
    return is_string($route_name) && $route_name !== '';
  }

  /**
   * Gets the entity types that get a "Scheduled actions" tab.
   *
   * These are the entity types that have the tab link template and that at
   * least one schedulable action runs on.
   *
   * @return string[]
   *   The entity type IDs, sorted.
   */
  public function getSupportedEntityTypeIds(): array {
    $entity_type_ids = [];
    foreach (array_keys($this->actionManager->getDefinitions()) as $plugin_id) {
      $target_type = $this->isSchedulable($plugin_id)
        ? $this->getTargetEntityTypeId($plugin_id)
        : NULL;
      if ($target_type !== NULL && $this->entityTypeManager->getDefinition($target_type)->hasLinkTemplate(static::LINK_TEMPLATE)) {
        $entity_type_ids[$target_type] = $target_type;
      }
    }
    sort($entity_type_ids);
    return $entity_type_ids;
  }

  /**
   * Gets the action plugins as select options, grouped by target.
   *
   * @param string|null $target_entity_type_id
   *   (optional) Only list the actions that run on this entity type.
   *
   * @return array<string, array<string, string|\Drupal\Core\StringTranslation\TranslatableMarkup>>
   *   Plugin labels keyed by plugin ID, grouped by the label of the entity
   *   type they run on, or under "System" for actions without a target.
   */
  public function getPluginOptions(?string $target_entity_type_id = NULL): array {
    $options = [];
    foreach ($this->actionManager->getDefinitions() as $plugin_id => $definition) {
      if (!$this->isSchedulable($plugin_id)) {
        continue;
      }
      $target_type = $this->getTargetEntityTypeId($plugin_id);
      if ($target_entity_type_id !== NULL && $target_type !== $target_entity_type_id) {
        continue;
      }
      $group = $target_type !== NULL
        ? (string) $this->entityTypeManager->getDefinition($target_type)->getLabel()
        : (string) $this->t('System');
      $options[$group][$plugin_id] = $definition['label'] ?? $plugin_id;
    }
    ksort($options, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($options as &$group_options) {
      uasort($group_options, static fn ($a, $b): int => strnatcasecmp((string) $a, (string) $b));
    }
    return $options;
  }

  /**
   * Gets the label of an action plugin.
   *
   * @param string $plugin_id
   *   The action plugin ID.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   The plugin label, or the plugin ID if the plugin does not exist.
   */
  public function getPluginLabel(string $plugin_id): string|TranslatableMarkup {
    $definition = $this->actionManager->getDefinition($plugin_id, FALSE);
    return $definition['label'] ?? $plugin_id;
  }

}
