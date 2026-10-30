<?php

declare(strict_types=1);

namespace Drupal\action_scheduler\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Cancels a user account right away, with the chosen method.
 *
 * Unlike the core "Cancel the selected user accounts" action, it needs no
 * confirmation form, so it can be scheduled. The account is cancelled like
 * from the user cancel form: other modules can act on the account content.
 */
#[Action(
  id: 'action_scheduler_user_cancel',
  label: new TranslatableMarkup('Cancel user account'),
  type: 'user'
)]
class CancelUserAction extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a CancelUserAction object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    $user_settings = $this->configFactory->get('user.settings');
    $method = (string) $user_settings->get('cancel_method');
    return [
      'user_cancel_method' => $method,
      'user_cancel_notify' => (bool) $user_settings->get('notify.' . static::getNotificationOperation($method)),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['user_cancel_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Cancellation method'),
      '#required' => TRUE,
    ];
    // The core methods, as altered by other modules.
    $form['user_cancel_method'] += user_cancel_methods();
    $form['user_cancel_method']['#default_value'] = $this->configuration['user_cancel_method'];
    $form['user_cancel_notify'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Notify user when account is canceled'),
      '#default_value' => $this->configuration['user_cancel_notify'],
      '#description' => $this->t('The email is only sent if the matching notification, "Account blocked" or "Account canceled", is enabled in the <a href=":url">account settings</a>.', [
        ':url' => Url::fromRoute('entity.user.admin_form')->toString(),
      ]),
    ];
    return $form;
  }

  /**
   * Gets the user email sent when an account is cancelled with a method.
   *
   * @param string $method
   *   The cancellation method.
   *
   * @return string
   *   The email operation, as used in the "notify" user settings.
   *
   * @see _user_cancel()
   */
  protected static function getNotificationOperation(string $method): string {
    return in_array($method, ['user_cancel_reassign', 'user_cancel_delete'], TRUE)
      ? 'status_canceled'
      : 'status_blocked';
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['user_cancel_method'] = $form_state->getValue('user_cancel_method');
    $this->configuration['user_cancel_notify'] = (bool) $form_state->getValue('user_cancel_notify');
  }

  /**
   * {@inheritdoc}
   */
  public function execute($account = NULL): void {
    if (!$account instanceof UserInterface) {
      return;
    }
    $method = $this->configuration['user_cancel_method'];
    user_cancel([
      'user_cancel_method' => $method,
      'user_cancel_notify' => $this->configuration['user_cancel_notify'],
    ], (int) $account->id(), $method);
    // user_cancel() only prepares a batch: run it now, in this request.
    $this->processBatch();
  }

  /**
   * Processes the current batch in one pass.
   *
   * The core batch_process() builds the URLs of the batch from the current
   * route, which cron and Drush runs from the command line lack. Its
   * non-progressive branch is reproduced here instead: no URL is needed to
   * run the operations in this request.
   */
  protected function processBatch(): void {
    $batch = &batch_get();
    if (!is_array($batch) || ($batch['sets'] ?? []) === []) {
      return;
    }
    require_once DRUPAL_ROOT . '/core/includes/batch.inc';
    $batch += [
      'current_set' => 0,
      'progressive' => FALSE,
      'id' => 'non-progressive',
    ];
    foreach (array_keys($batch['sets']) as $key) {
      _batch_populate_queue($batch, $key);
    }
    _batch_process();
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface {
    /** @var \Drupal\user\UserInterface $object */
    // Like the core bulk cancel form, user 1 cannot be cancelled.
    $result = AccessResult::forbiddenIf((int) $object->id() === 1)
      ->orIf($object->access('delete', $account, TRUE));
    return $return_as_object ? $result : $result->isAllowed();
  }

}
