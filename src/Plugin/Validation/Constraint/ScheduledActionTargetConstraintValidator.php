<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Plugin\Validation\Constraint;

use Drupal\action_scheduler\ScheduledActionInterface;
use Drupal\action_scheduler\ScheduledActionTargetResolver;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ScheduledActionTarget constraint.
 */
class ScheduledActionTargetConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ActionManager $actionManager,
    protected ScheduledActionTargetResolver $targetResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.action'),
      $container->get(ScheduledActionTargetResolver::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $entity, Constraint $constraint): void {
    assert($constraint instanceof ScheduledActionTargetConstraint);
    if (!$entity instanceof ScheduledActionInterface) {
      return;
    }
    // A missing action is reported by the required constraint of its field.
    $plugin_id = $entity->getActionPluginId();
    if ($plugin_id === NULL || $plugin_id === '') {
      return;
    }
    if (!$this->actionManager->hasDefinition($plugin_id)) {
      $this->context->buildViolation($constraint->unknownPluginMessage)
        ->setParameter('%plugin', $plugin_id)
        ->atPath('plugin')
        ->addViolation();
      return;
    }

    $action_label = $this->targetResolver->getPluginLabel($plugin_id);
    if (!$this->targetResolver->isSchedulable($plugin_id)) {
      $this->context->buildViolation($constraint->notSchedulableMessage)
        ->setParameter('%action', (string) $action_label)
        ->atPath('plugin')
        ->addViolation();
      return;
    }
    $required_type = $this->targetResolver->getTargetEntityTypeId($plugin_id);
    $entity_type_id = $entity->getTargetEntityTypeId();
    $entity_id = $entity->getTargetEntityId();

    if ($required_type === NULL) {
      if ($entity_type_id !== NULL || $entity_id !== NULL) {
        $this->context->buildViolation($constraint->notAllowedMessage)
          ->setParameter('%action', (string) $action_label)
          ->atPath('target_entity_id')
          ->addViolation();
      }
      return;
    }

    $required_label = $this->entityTypeManager
      ->getDefinition($required_type)
      ->getSingularLabel();

    if ($entity_id === NULL) {
      $this->context->buildViolation($constraint->missingMessage)
        ->setParameter('%action', (string) $action_label)
        ->setParameter('%entity_type', $required_label)
        ->atPath('target_entity_id')
        ->addViolation();
      return;
    }

    if ($entity_type_id !== $required_type) {
      $this->context->buildViolation($constraint->wrongTypeMessage)
        ->setParameter('%action', (string) $action_label)
        ->setParameter('%entity_type', $required_label)
        ->atPath('target_entity_id')
        ->addViolation();
      return;
    }

    // Only pending schedules need an existing target: processed ones
    // keep their history even after the target is deleted.
    if ($entity->getStatus() === ScheduledActionInterface::STATUS_PENDING && $entity->getTargetEntity() === NULL) {
      $this->context->buildViolation($constraint->notFoundMessage)
        ->setParameter('%entity_type', $required_label)
        ->setParameter('%id', (string) $entity_id)
        ->atPath('target_entity_id')
        ->addViolation();
    }
  }

}
