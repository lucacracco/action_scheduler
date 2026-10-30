<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Form;

use Drupal\action_scheduler\ScheduledActionTargetResolver;
use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Core\Action\ActionInterface;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityConstraintViolationListInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Plugin\PluginFormInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for the scheduled action add and edit forms.
 *
 * The action plugin, its configuration and the target entity are edited by
 * elements added outside the form display. Changing the action rebuilds,
 * through Ajax, the settings below it:
 * - the target entity, shown only when the action runs on an entity, with an
 *   autocomplete on the entity type the action applies to;
 * - the plugin configuration form, for configurable actions.
 */
class ScheduledActionForm extends ContentEntityForm {

  /**
   * The ID of the wrapper replaced when the action changes.
   */
  const SETTINGS_WRAPPER_ID = 'scheduled-action-settings-wrapper';

  /**
   * The action plugin manager.
   *
   * @var \Drupal\Core\Action\ActionManager
   */
  protected ActionManager $actionManager;

  /**
   * The target resolver.
   *
   * @var \Drupal\action_scheduler\ScheduledActionTargetResolver
   */
  protected ScheduledActionTargetResolver $targetResolver;

  /**
   * The target set by the URL of the add form, which cannot be changed.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface|null
   */
  protected ?ContentEntityInterface $fixedTarget = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->actionManager = $container->get('plugin.manager.action');
    $instance->targetResolver = $container->get(ScheduledActionTargetResolver::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * The "Scheduled actions" tab of an entity links to the add form with
   * ?target_type=…&target_id=…: that entity becomes the fixed target, if the
   * user may edit it.
   */
  protected function prepareEntity(): void {
    parent::prepareEntity();
    /** @var \Drupal\action_scheduler\ScheduledActionInterface $entity */
    $entity = $this->entity;
    $query = $this->getRequest()->query;
    $target_type = $query->get('target_type');
    $target_id = $query->get('target_id');
    if (!$entity->isNew() || !is_string($target_type) || !is_numeric($target_id)
      || !in_array($target_type, $this->targetResolver->getSupportedEntityTypeIds(), TRUE)) {
      return;
    }
    $target = $this->entityTypeManager->getStorage($target_type)->load($target_id);
    if ($target instanceof ContentEntityInterface && $target->access('update')) {
      $this->fixedTarget = $target;
      $entity->setTargetEntity($target);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\action_scheduler\ScheduledActionInterface $entity */
    $entity = $this->entity;

    // Processed schedules are a record: show them, but disable every element,
    // the buttons included.
    if ($entity->isProcessed()) {
      $form['#disabled'] = TRUE;
      $form['processed_notice'] = [
        '#theme' => 'status_messages',
        '#message_list' => [
          'warning' => [
            $this->t('This scheduled action has been processed: it can no longer be changed or deleted.'),
          ],
        ],
        '#status_headings' => [
          'warning' => $this->t('Warning message'),
        ],
        '#weight' => -100,
      ];
    }

    // A fixed target is shown, not edited, and only its actions are offered.
    if ($this->fixedTarget !== NULL) {
      $form['fixed_target'] = [
        '#type' => 'item',
        '#title' => $this->fixedTarget->getEntityType()->getLabel(),
        '#markup' => $this->fixedTarget->toLink()->toString(),
        '#weight' => -1,
      ];
    }

    $form['plugin'] = [
      '#type' => 'select',
      '#title' => $this->t('Action'),
      '#options' => $this->targetResolver->getPluginOptions($this->fixedTarget?->getEntityTypeId()),
      '#empty_option' => $this->t('- Select an action -'),
      '#default_value' => $entity->getActionPluginId(),
      '#required' => TRUE,
      '#weight' => 0,
      '#ajax' => [
        'callback' => '::settingsAjaxCallback',
        'wrapper' => static::SETTINGS_WRAPPER_ID,
      ],
    ];

    // Without JavaScript, this button rebuilds the settings for the selected
    // action; with JavaScript, Ajax does it and the button is hidden.
    $form['plugin_update'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update action settings'),
      '#limit_validation_errors' => [['plugin']],
      '#validate' => [],
      '#submit' => ['::rebuildSettings'],
      '#attributes' => ['class' => ['js-hide']],
      '#weight' => 1,
    ];

    // #tree keeps #parents equal to #array_parents, which SubformState uses
    // to name the errors set by plugin configuration forms.
    $form['settings'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['id' => static::SETTINGS_WRAPPER_ID],
      '#weight' => 5,
    ];

    $plugin_id = $this->getSelectedPluginId($form_state);
    if ($plugin_id === NULL || !$this->actionManager->hasDefinition($plugin_id)) {
      return $form;
    }

    // Values typed for a previous action may not fit the new one.
    $triggering_element = $form_state->getTriggeringElement();
    if ($triggering_element !== NULL && in_array($triggering_element['#parents'][0] ?? NULL, ['plugin', 'plugin_update'], TRUE)) {
      $input = &$form_state->getUserInput();
      unset($input['settings']);
    }

    // The warning is not shown on processed schedules, which cannot change.
    if (!$entity->isProcessed() && $this->targetResolver->needsConfirmation($plugin_id)) {
      $form['settings']['confirmation_warning'] = [
        '#theme' => 'status_messages',
        '#message_list' => [
          'warning' => [
            $this->t('This action does not do the work itself: it only prepares a confirmation form for the user who triggers it. Run from a schedule, it does nothing. Choose another action.'),
          ],
        ],
        '#status_headings' => [
          'warning' => $this->t('Warning message'),
        ],
        '#weight' => -10,
      ];
    }

    $target_type = $this->targetResolver->getTargetEntityTypeId($plugin_id);
    if ($target_type !== NULL && $this->fixedTarget === NULL) {
      $default = $entity->getTargetEntityTypeId() === $target_type
        ? $entity->getTargetEntity()
        : NULL;
      $form['settings']['target_entity_id'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->entityTypeManager
          ->getDefinition($target_type)
          ->getLabel(),
        '#description' => $this->t('The entity the action is executed on.'),
        '#target_type' => $target_type,
        '#default_value' => $default,
        '#required' => TRUE,
      ];
    }

    $plugin = $this->createPlugin($plugin_id);
    if ($plugin instanceof PluginFormInterface) {
      $form['settings']['configuration'] = [
        '#type' => 'details',
        '#title' => $this->t('Action settings'),
        '#open' => TRUE,
        // Lets later steps check the subform belongs to the selected plugin.
        '#plugin_id' => $plugin_id,
      ];
      $subform_state = SubformState::createForSubform($form['settings']['configuration'], $form, $form_state);
      $form['settings']['configuration'] = $plugin->buildConfigurationForm($form['settings']['configuration'], $subform_state);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    /** @var \Drupal\action_scheduler\ScheduledActionInterface $entity */
    $entity = $this->entity;
    if ($entity->isProcessed()) {
      // The delete link is hidden by the access handler: show a disabled
      // button in its place, like the save button.
      $actions['delete'] = [
        '#type' => 'button',
        '#value' => $this->t('Delete'),
        '#disabled' => TRUE,
        '#attributes' => ['class' => ['button--danger']],
        '#weight' => 10,
      ];
    }
    return $actions;
  }

  /**
   * Ajax callback: returns the settings of the selected action.
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The settings wrapper.
   */
  public function settingsAjaxCallback(array $form, FormStateInterface $form_state): array {
    return $form['settings'];
  }

  /**
   * Submit handler: rebuilds the form for the selected action.
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function rebuildSettings(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): ContentEntityInterface {
    /** @var \Drupal\action_scheduler\ScheduledActionInterface $entity */
    $entity = $this->entity;
    // The form is disabled, but a forged submission must not save either.
    if ($entity->isProcessed()) {
      $form_state->setErrorByName('', $this->t('This scheduled action has been processed: it can no longer be changed or deleted.'));
      return $entity;
    }
    $plugin_id = $this->getSelectedPluginId($form_state);
    $plugin = $plugin_id !== NULL ? $this->createPlugin($plugin_id) : NULL;
    if ($plugin instanceof PluginFormInterface && $this->hasPluginSubform($form, $plugin_id)) {
      $subform_state = SubformState::createForSubform($form['settings']['configuration'], $form, $form_state);
      $plugin->validateConfigurationForm($form['settings']['configuration'], $subform_state);
    }
    return parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    parent::copyFormValuesToEntity($entity, $form, $form_state);
    /** @var \Drupal\action_scheduler\ScheduledActionInterface $entity */
    $plugin_id = $this->getSelectedPluginId($form_state);
    $plugin = $plugin_id !== NULL ? $this->createPlugin($plugin_id) : NULL;
    if ($plugin_id === NULL || $plugin === NULL) {
      // Keep the submitted plugin ID so the validator reports what is wrong.
      $entity->set('plugin', $plugin_id);
      $entity->set('configuration', []);
      $entity->setTargetEntity(NULL);
      return;
    }

    if ($plugin instanceof PluginFormInterface && $this->hasPluginSubform($form, $plugin_id)) {
      $subform_state = SubformState::createForSubform($form['settings']['configuration'], $form, $form_state);
      $plugin->submitConfigurationForm($form['settings']['configuration'], $subform_state);
    }
    $configuration = $plugin instanceof ConfigurableInterface
      ? $plugin->getConfiguration()
      : [];
    $entity->setActionPlugin($plugin_id, $configuration);

    // The validator rejects a fixed target the selected action does not use.
    if ($this->fixedTarget !== NULL) {
      $entity->setTargetEntity($this->fixedTarget);
      return;
    }

    $target_type = $this->targetResolver->getTargetEntityTypeId($plugin_id);
    $target_id = $form_state->getValue(['settings', 'target_entity_id']);
    $target_id = is_numeric($target_id) ? (int) $target_id : NULL;

    $target = NULL;
    if ($target_type !== NULL && $target_id !== NULL) {
      $target = $this->entityTypeManager
        ->getStorage($target_type)
        ->load($target_id);
    }
    if ($target instanceof ContentEntityInterface) {
      $entity->setTargetEntity($target);
      return;
    }
    // Keep the submitted values so the validator reports what is wrong.
    $entity->set('target_entity_type', $target_type);
    $entity->set('target_entity_id', $target_id);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditedFieldNames(FormStateInterface $form_state): array {
    return array_merge(parent::getEditedFieldNames($form_state), [
      'plugin',
      'configuration',
      'target_entity_type',
      'target_entity_id',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function flagViolations(EntityConstraintViolationListInterface $violations, array $form, FormStateInterface $form_state): void {
    $element_names = [
      'plugin' => 'plugin',
      'configuration' => 'plugin',
      'target_entity_type' => 'settings][target_entity_id',
      'target_entity_id' => 'settings][target_entity_id',
    ];
    foreach ($element_names as $field_name => $element_name) {
      foreach ($violations->getByField($field_name) as $violation) {
        $form_state->setErrorByName($element_name, $violation->getMessage());
      }
    }
    parent::flagViolations($violations, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $arguments = ['%label' => $this->entity->label()];
    if ($result === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Created the scheduled action %label.', $arguments));
    }
    else {
      $this->messenger()->addStatus($this->t('Updated the scheduled action %label.', $arguments));
    }

    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

  /**
   * Gets the action plugin currently selected in the form.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return string|null
   *   The submitted plugin ID on a rebuild, otherwise the saved one.
   */
  protected function getSelectedPluginId(FormStateInterface $form_state): ?string {
    if (!$form_state->hasValue('plugin')) {
      /** @var \Drupal\action_scheduler\ScheduledActionInterface $entity */
      $entity = $this->entity;
      return $entity->getActionPluginId();
    }
    $plugin_id = $form_state->getValue('plugin');
    return is_string($plugin_id) && $plugin_id !== '' ? $plugin_id : NULL;
  }

  /**
   * Creates the action plugin to configure in the form.
   *
   * @param string $plugin_id
   *   The action plugin ID.
   *
   * @return \Drupal\Core\Action\ActionInterface|null
   *   The plugin, with the stored configuration if the schedule already uses
   *   it, or NULL if the plugin does not exist.
   */
  protected function createPlugin(string $plugin_id): ?ActionInterface {
    if (!$this->actionManager->hasDefinition($plugin_id)) {
      return NULL;
    }
    /** @var \Drupal\action_scheduler\ScheduledActionInterface $entity */
    $entity = $this->entity;
    $configuration = $entity->getActionPluginId() === $plugin_id
      ? $entity->getActionConfiguration()
      : [];
    $plugin = $this->actionManager->createInstance($plugin_id, $configuration);
    return $plugin instanceof ActionInterface ? $plugin : NULL;
  }

  /**
   * Checks whether the form holds the configuration subform of a plugin.
   *
   * After the action changes, the submitted form still holds the subform of
   * the previous action until it is rebuilt.
   *
   * @param array $form
   *   The form structure.
   * @param string $plugin_id
   *   The action plugin ID.
   *
   * @return bool
   *   TRUE if the configuration subform was built for this plugin.
   */
  protected function hasPluginSubform(array $form, string $plugin_id): bool {
    return ($form['settings']['configuration']['#plugin_id'] ?? NULL) === $plugin_id;
  }

}
