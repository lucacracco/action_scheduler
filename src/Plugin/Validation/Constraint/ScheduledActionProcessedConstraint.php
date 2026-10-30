<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks a processed (executed, failed or cancelled) schedule is not changed.
 */
#[Constraint(
  id: 'ScheduledActionProcessed',
  label: new TranslatableMarkup('Processed scheduled action', [], ['context' => 'Validation']),
  type: ['entity']
)]
class ScheduledActionProcessedConstraint extends SymfonyConstraint {

  #[HasNamedArguments]
  public function __construct(
    mixed $options = NULL,
    public $message = 'The scheduled action %label has been processed: it can no longer be changed.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct($options, $groups, $payload);
  }

}
