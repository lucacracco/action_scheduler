<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks a scheduled action's plugin exists and its target matches it.
 */
#[Constraint(
  id: 'ScheduledActionTarget',
  label: new TranslatableMarkup('Scheduled action target', [], ['context' => 'Validation']),
  type: ['entity']
)]
class ScheduledActionTargetConstraint extends SymfonyConstraint {

  #[HasNamedArguments]
  public function __construct(
    mixed $options = NULL,
    public $missingMessage = 'The action %action requires a target %entity_type.',
    public $wrongTypeMessage = 'The action %action can only be executed on a %entity_type.',
    public $notAllowedMessage = 'The action %action does not use a target entity.',
    public $notFoundMessage = 'The target %entity_type with ID %id does not exist.',
    public $unknownPluginMessage = 'The action plugin %plugin does not exist.',
    public $notSchedulableMessage = 'The action %action cannot be scheduled.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct($options, $groups, $payload);
  }

}
