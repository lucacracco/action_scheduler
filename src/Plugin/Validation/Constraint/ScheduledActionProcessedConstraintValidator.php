<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Plugin\Validation\Constraint;

use Drupal\action_scheduler\ScheduledActionInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ScheduledActionProcessed constraint.
 *
 * The check is made on the stored schedule, so the executor can still mark a
 * pending schedule as executed, failed or cancelled.
 */
class ScheduledActionProcessedConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $entity, Constraint $constraint): void {
    assert($constraint instanceof ScheduledActionProcessedConstraint);
    if (!$entity instanceof ScheduledActionInterface || $entity->isNew()) {
      return;
    }
    $original = $this->entityTypeManager
      ->getStorage($entity->getEntityTypeId())
      ->loadUnchanged($entity->id());
    if (!$original instanceof ScheduledActionInterface || !$original->isProcessed()) {
      return;
    }
    // Compared like ContentEntityBase::hasTranslationChanges(), which would
    // load the stored schedule again. The changed time alone is not a change.
    $langcode = $entity->language()->getId();
    foreach (array_keys($entity->getFieldDefinitions()) as $field_name) {
      $items = $entity->get($field_name)->filterEmptyItems();
      $original_items = $original->get($field_name)->filterEmptyItems();
      if ($items->hasAffectingChanges($original_items, $langcode)) {
        $this->context->buildViolation($constraint->message)
          ->setParameter('%label', (string) $original->label())
          ->addViolation();
        return;
      }
    }
  }

}
