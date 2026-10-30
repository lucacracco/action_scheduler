<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Hook;

use Drupal\action_scheduler\ScheduledActionTargetResolver;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Entity type hook implementations for action_scheduler.
 *
 * Kept apart from ActionSchedulerHooks: entity type hooks run while entity
 * types are being discovered, so this class must not depend on services that
 * need them.
 */
class ActionSchedulerEntityTypeHooks {

  /**
   * Implements hook_entity_type_alter().
   *
   * Adds the link template of the "Scheduled actions" tab to the content
   * entity types with a page and an edit form. Action plugins cannot be
   * checked here, since their derivers read entity types: the route
   * subscriber and the local task deriver only use the entity types that an
   * action runs on.
   *
   * @see \Drupal\action_scheduler\ScheduledActionTargetResolver::getSupportedEntityTypeIds()
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    /** @var \Drupal\Core\Entity\EntityTypeInterface[] $entity_types */
    foreach ($entity_types as $entity_type_id => $entity_type) {
      if (!$entity_type instanceof ContentEntityTypeInterface
        || $entity_type_id === 'scheduled_action'
        || !$entity_type->hasLinkTemplate('canonical')
        || !$entity_type->hasLinkTemplate('edit-form')
        || $entity_type->hasLinkTemplate(ScheduledActionTargetResolver::LINK_TEMPLATE)) {
        continue;
      }
      $entity_type->setLinkTemplate(
        ScheduledActionTargetResolver::LINK_TEMPLATE,
        $entity_type->getLinkTemplate('canonical') . '/scheduled-actions'
      );
    }
  }

}
