<?php

declare(strict_types=1);

namespace Drupal\action_scheduler;

use Drupal\action_scheduler\Entity\ScheduledAction;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a list controller for the scheduled action entity type.
 *
 * @phpstan-consistent-constructor
 */
class ScheduledActionListBuilder extends EntityListBuilder {

  /**
   * The cacheability of the target cells of the rendered rows.
   *
   * @var \Drupal\Core\Cache\CacheableMetadata
   */
  protected CacheableMetadata $targetCacheability;

  /**
   * Constructs a new ScheduledActionListBuilder object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The entity storage class.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\action_scheduler\ScheduledActionTargetResolver $targetResolver
   *   The target resolver.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected DateFormatterInterface $dateFormatter,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ScheduledActionTargetResolver $targetResolver,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
      $container->get(ScheduledActionTargetResolver::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $this->targetCacheability = new CacheableMetadata();
    $build = parent::render();
    CacheableMetadata::createFromRenderArray($build)
      ->merge($this->targetCacheability)
      ->applyTo($build);
    return $build;
  }

  /**
   * {@inheritdoc}
   *
   * Latest scheduled date first.
   */
  protected function getEntityListQuery(): QueryInterface {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('scheduled', 'DESC')
      ->sort($this->entityType->getKey('id'), 'DESC');
    if ($this->limit) {
      $query->pager($this->limit);
    }
    return $query;
  }

  /**
   * Builds the schedules of a target entity, pending ones first.
   *
   * Used by the "Scheduled actions" tab of the target: the target column is
   * left out, and so are the status and executed columns of the pending
   * section and the scheduled column of the processed one.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $target
   *   The target entity.
   *
   * @return array
   *   A render array with a "pending" and a "processed" section.
   */
  public function renderForTarget(ContentEntityInterface $target): array {
    $this->targetCacheability = new CacheableMetadata();
    $sections = [
      'pending' => [
        'title' => $this->t('Pending'),
        'empty' => $this->t('There are no pending scheduled actions.'),
        'operator' => '=',
        // Next to run first.
        'sort' => ['scheduled' => 'ASC'],
        // Always "Pending", and never executed.
        'hidden' => ['target', 'status', 'executed'],
      ],
      'processed' => [
        'title' => $this->t('Processed'),
        'empty' => $this->t('There are no processed scheduled actions.'),
        'operator' => '<>',
        // Last run first.
        'sort' => ['executed' => 'DESC', 'scheduled' => 'DESC'],
        // The execution time matters more than the scheduled one.
        'hidden' => ['target', 'scheduled'],
      ],
    ];

    $build = [];
    foreach ($sections as $key => $section) {
      $hidden = array_flip($section['hidden']);
      $header = array_diff_key($this->buildHeader(), $hidden);
      $query = $this->getStorage()->getQuery()
        ->accessCheck(TRUE)
        ->condition('target_entity_type', $target->getEntityTypeId())
        ->condition('target_entity_id', $target->id())
        ->condition('status', ScheduledActionInterface::STATUS_PENDING, $section['operator']);
      foreach ($section['sort'] as $field => $direction) {
        $query->sort($field, $direction);
      }
      $query->sort('id', $section['operator'] === '=' ? 'ASC' : 'DESC');

      $rows = [];
      foreach ($this->getStorage()->loadMultiple($query->execute()) as $entity) {
        $rows[$entity->id()] = array_diff_key($this->buildRow($entity), $hidden);
      }
      $build[$key] = [
        '#type' => 'details',
        '#title' => $section['title'],
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => $header,
          '#rows' => $rows,
          '#empty' => $section['empty'],
        ],
      ];
    }

    $this->targetCacheability
      ->addCacheableDependency($target)
      ->addCacheTags($this->entityType->getListCacheTags())
      ->addCacheContexts($this->entityType->getListCacheContexts())
      ->addCacheContexts(['user.permissions'])
      ->applyTo($build);
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header = [
      'action' => $this->t('Action'),
      'target' => $this->t('Target'),
      'scheduled' => $this->t('Scheduled for'),
      'status' => $this->t('Status'),
      'executed' => $this->t('Executed'),
      'owner' => $this->t('Created by'),
      'created' => $this->t('Created'),
    ];
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\action_scheduler\ScheduledActionInterface $entity */
    $scheduled = $entity->getScheduledDate();
    $statuses = ScheduledAction::statusAllowedValues();
    // getOwner() returns NULL once the owner is deleted.
    $owner = $entity->getOwner();

    $row = [
      'action' => $this->targetResolver
        ->getPluginLabel($entity->getActionPluginId() ?? ''),
      'target' => $this->buildTargetCell($entity),
      'scheduled' => $scheduled !== NULL
        ? $this->dateFormatter->format($scheduled->getTimestamp(), 'short')
        : '',
      'status' => $statuses[$entity->getStatus()] ?? $entity->getStatus(),
      'executed' => $entity->getExecutedTime() !== NULL
        ? $this->dateFormatter->format($entity->getExecutedTime(), 'short')
        : '',
      'owner' => $owner !== NULL ? $owner->getDisplayName() : '',
      'created' => $this->dateFormatter
        ->format($entity->getCreatedTime(), 'short'),
    ];
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity/* , ?CacheableMetadata $cacheability = NULL */): array {
    $operations = parent::getDefaultOperations(...func_get_args());
    // The canonical URL is the edit form, already linked by "Edit".
    unset($operations['view']);
    return $operations;
  }

  /**
   * Builds the target cell of a row.
   *
   * @param \Drupal\action_scheduler\ScheduledActionInterface $entity
   *   The scheduled action.
   *
   * @return array|string|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   A link to the target, a notice if it no longer exists, or an empty
   *   string if the schedule has no target.
   */
  protected function buildTargetCell(ScheduledActionInterface $entity): array|string|TranslatableMarkup {
    $entity_id = $entity->getTargetEntityId();
    if ($entity_id === NULL) {
      return '';
    }
    $target = $entity->getTargetEntity();
    if ($target === NULL) {
      // The list must show the target again if it is recreated.
      $entity_type_id = $entity->getTargetEntityTypeId();
      if ($entity_type_id !== NULL &&$this->entityTypeManager->hasDefinition($entity_type_id)) {
        $this->targetCacheability->addCacheTags(
          $this->entityTypeManager->getDefinition($entity_type_id)->getListCacheTags()
        );
      }
      return $this->t('Deleted (@type @id)', [
        '@type' => $entity_type_id,
        '@id' => $entity_id,
      ]);
    }
    $access = $target->access('view', NULL, TRUE);
    $this->targetCacheability
      ->addCacheableDependency($target)
      ->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      return $this->t('@type @id', [
        '@type' => $target->getEntityType()->getSingularLabel(),
        '@id' => $entity_id,
      ]);
    }
    if ($target->hasLinkTemplate('canonical')) {
      return ['data' => $target->toLink()->toRenderable()];
    }
    return $target->label() ?? '';
  }

}
