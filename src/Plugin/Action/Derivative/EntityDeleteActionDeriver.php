<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Plugin\Action\Derivative;

use Drupal\Core\Action\Plugin\Action\Derivative\EntityActionDeriverBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;

/**
 * Provides an immediate delete action for each content entity type.
 *
 * Users are left out: they are cancelled by the "Cancel user account" action,
 * which lets other modules act on their content.
 *
 * @see \Drupal\action_scheduler\Plugin\Action\EntityDeleteAction
 * @see \Drupal\action_scheduler\Plugin\Action\CancelUserAction
 */
class EntityDeleteActionDeriver extends EntityActionDeriverBase {

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    if ($this->derivatives === []) {
      // The core delete actions are already labelled "Delete <entity type>".
      foreach (parent::getDerivativeDefinitions($base_plugin_definition) as $entity_type_id => $definition) {
        $definition['label'] = $this->t('Delete @entity_type immediately', [
          '@entity_type' => $this->entityTypeManager->getDefinition($entity_type_id)->getSingularLabel(),
        ]);
        $this->derivatives[$entity_type_id] = $definition;
      }
    }
    return $this->derivatives;
  }

  /**
   * {@inheritdoc}
   */
  protected function isApplicable(EntityTypeInterface $entity_type): bool {
    return $entity_type->entityClassImplements(ContentEntityInterface::class)
      && !in_array($entity_type->id(), ['user', 'scheduled_action'], TRUE);
  }

}
