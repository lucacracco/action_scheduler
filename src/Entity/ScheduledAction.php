<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Entity;

use Drupal\action_scheduler\Form\ScheduledActionForm;
use Drupal\action_scheduler\Routing\ScheduledActionRouteProvider;
use Drupal\action_scheduler\ScheduledActionAccessControlHandler;
use Drupal\action_scheduler\ScheduledActionInterface;
use Drupal\action_scheduler\ScheduledActionListBuilder;
use Drupal\Core\Action\ActionInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the scheduled action entity class.
 */
#[ContentEntityType(
  id: 'scheduled_action',
  label: new TranslatableMarkup('Scheduled action'),
  label_collection: new TranslatableMarkup('Scheduled actions'),
  label_singular: new TranslatableMarkup('scheduled action'),
  label_plural: new TranslatableMarkup('scheduled actions'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => ScheduledActionAccessControlHandler::class,
    'list_builder' => ScheduledActionListBuilder::class,
    'form' => [
      'add' => ScheduledActionForm::class,
      'edit' => ScheduledActionForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => ScheduledActionRouteProvider::class,
    ],
  ],
  // The canonical URL is the edit form: see ScheduledActionRouteProvider.
  links: [
    'canonical' => '/admin/content/scheduled-action/{scheduled_action}',
    'add-form' => '/admin/content/scheduled-action/add',
    'edit-form' => '/admin/content/scheduled-action/{scheduled_action}',
    'delete-form' => '/admin/content/scheduled-action/{scheduled_action}/delete',
    'collection' => '/admin/content/scheduled-action',
  ],
  admin_permission: 'administer scheduled actions',
  constraints: [
    'ScheduledActionTarget' => [],
    'ScheduledActionProcessed' => [],
  ],
  base_table: 'scheduled_action',
  label_count: [
    'singular' => '@count scheduled action',
    'plural' => '@count scheduled actions',
  ],
)]
class ScheduledAction extends ContentEntityBase implements ScheduledActionInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    // The action is an Action plugin, not a configured action entity: each
    // schedule stores its own plugin configuration. The plugin, its
    // configuration and the target are edited by custom elements in
    // ScheduledActionForm, not through widgets.
    $fields['plugin'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Action plugin'))
      ->setDescription(new TranslatableMarkup('The ID of the action plugin to execute.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['configuration'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Action configuration'))
      ->setDescription(new TranslatableMarkup('The configuration of the action plugin.'));

    // The target is stored as entity type + ID because the entity type depends
    // on the selected action.
    $fields['target_entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Target entity type'))
      ->setDescription(new TranslatableMarkup('The entity type of the entity the action is executed on.'))
      ->setSetting('max_length', EntityTypeInterface::ID_MAX_LENGTH)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['target_entity_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Target entity ID'))
      ->setDescription(new TranslatableMarkup('The ID of the entity the action is executed on.'))
      ->setSetting('unsigned', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'settings' => ['thousand_separator' => ''],
        'weight' => 6,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['scheduled'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Scheduled for'))
      ->setDescription(new TranslatableMarkup('The date and time the action runs.'))
      ->setRequired(TRUE)
      ->setSetting('datetime_type', DateTimeItem::DATETIME_TYPE_DATETIME)
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'datetime_default',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // The status is set when the action runs, not edited in the form.
    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDescription(new TranslatableMarkup('The execution status of the schedule.'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values_function', static::class . '::statusAllowedValues')
      ->setDefaultValue(static::STATUS_PENDING)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // The author defaults to the current user and is not editable.
    $uid = $fields['uid'];
    assert($uid instanceof BaseFieldDefinition);
    $uid->setLabel(new TranslatableMarkup('Created by'))
      ->setDescription(new TranslatableMarkup('The user who created the schedule.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'author',
        'weight' => 30,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time the schedule was created.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 40,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['executed'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Executed'))
      ->setDescription(new TranslatableMarkup('The time the schedule was processed: executed, failed or cancelled.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 25,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time the schedule was last edited.'));

    return $fields;
  }

  /**
   * Allowed values callback for the status field.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The status labels, keyed by status value.
   */
  public static function statusAllowedValues(): array {
    return [
      static::STATUS_PENDING => new TranslatableMarkup('Pending'),
      static::STATUS_EXECUTED => new TranslatableMarkup('Executed'),
      static::STATUS_FAILED => new TranslatableMarkup('Failed'),
      static::STATUS_CANCELLED => new TranslatableMarkup('Cancelled'),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The entity has no label field: the label is built from the action name
   * and the scheduled date.
   */
  public function label(): string|TranslatableMarkup|null {
    $plugin_id = $this->getActionPluginId();
    $date = $this->getScheduledDate();
    if ($plugin_id === NULL || $plugin_id === '' || $date === NULL) {
      return NULL;
    }
    // Fall back to the plugin ID if the plugin no longer exists.
    $action_label = $this->getActionPlugin()?->getPluginDefinition()['label'] ?? $plugin_id;
    // Entity classes cannot use dependency injection.
    $formatted = \Drupal::service('date.formatter')
      ->format($date->getTimestamp(), 'short');
    return new TranslatableMarkup('@action on @date', [
      '@action' => $action_label,
      '@date' => $formatted,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getActionPluginId(): ?string {
    return $this->get('plugin')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getActionConfiguration(): array {
    return $this->get('configuration')->first()?->getValue() ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function setActionPlugin(string $plugin_id, array $configuration = []): static {
    $this->set('plugin', $plugin_id);
    // Wrap the configuration as a single map item, whatever its keys.
    $this->set('configuration', [$configuration]);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getActionPlugin(): ?ActionInterface {
    $plugin_id = $this->getActionPluginId();
    if ($plugin_id === NULL || $plugin_id === '') {
      return NULL;
    }
    // Entity classes cannot use dependency injection.
    $action_manager = \Drupal::service('plugin.manager.action');
    if (!$action_manager->hasDefinition($plugin_id)) {
      return NULL;
    }
    return $action_manager->createInstance($plugin_id, $this->getActionConfiguration());
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityTypeId(): ?string {
    return $this->get('target_entity_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityId(): ?int {
    $id = $this->get('target_entity_id')->value;
    return $id === NULL ? NULL : (int) $id;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntity(): ?ContentEntityInterface {
    $entity_type_id = $this->getTargetEntityTypeId();
    $entity_id = $this->getTargetEntityId();
    if ($entity_type_id === NULL || $entity_type_id === '' || $entity_id === NULL) {
      return NULL;
    }
    // Entity classes cannot use dependency injection.
    $entity_type_manager = \Drupal::entityTypeManager();
    if (!$entity_type_manager->hasDefinition($entity_type_id)) {
      return NULL;
    }
    $entity = $entity_type_manager->getStorage($entity_type_id)->load($entity_id);
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setTargetEntity(?ContentEntityInterface $entity): static {
    $this->set('target_entity_type', $entity?->getEntityTypeId());
    $this->set('target_entity_id', $entity?->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getScheduledDate(): ?DrupalDateTime {
    // The computed "date" property of the datetime item.
    $date = $this->get('scheduled')->first()?->get('date')->getValue();
    return $date instanceof DrupalDateTime ? $date : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus(string $status): static {
    $this->set('status', $status);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isProcessed(): bool {
    return $this->getStatus() !== static::STATUS_PENDING;
  }

  /**
   * {@inheritdoc}
   */
  public function getExecutedTime(): ?int {
    $time = $this->get('executed')->value;
    return $time === NULL ? NULL : (int) $time;
  }

  /**
   * {@inheritdoc}
   */
  public function setExecutedTime(int $timestamp): static {
    $this->set('executed', $timestamp);
    return $this;
  }

}
