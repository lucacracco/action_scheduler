<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Routing;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Symfony\Component\Routing\Route;

/**
 * Provides HTML routes for scheduled actions.
 *
 * Scheduled actions have no page of their own: like custom blocks, their
 * canonical URL is the edit form.
 */
class ScheduledActionRouteProvider extends AdminHtmlRouteProvider {

  /**
   * {@inheritdoc}
   */
  protected function getCanonicalRoute(EntityTypeInterface $entity_type): ?Route {
    return $this->getEditFormRoute($entity_type);
  }

}
