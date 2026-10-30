<?php

declare(strict_types=1);

namespace Drupal\Tests\action_scheduler\Kernel;

use Drupal\action_scheduler\Entity\ScheduledAction;
use Drupal\action_scheduler\ScheduledActionInterface;
use Drupal\action_scheduler\ScheduledActionTargetResolver;
use Drupal\Core\Action\Plugin\Action\MessageAction;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the scheduled action entity, its validation and its hooks.
 */
#[Group('action_scheduler')]
#[RunTestsInSeparateProcesses]
class ScheduledActionTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'action_scheduler',
    'datetime',
    'options',
    'system',
    'user',
  ];

  /**
   * A user used as the target of user actions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $targetUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('scheduled_action');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system']);
    // Use up user ID 1 so the target is an ordinary account.
    $this->createUser();
    $this->targetUser = $this->createUser();
  }

  /**
   * Tests a stand-alone action stores its configuration and needs no target.
   */
  public function testSystemAction(): void {
    $schedule = $this->createSchedule('action_message_action', ['message' => 'Hello']);
    $this->assertSame([], $this->getViolations($schedule));
    $schedule->save();

    $schedule = ScheduledAction::load($schedule->id());
    $this->assertSame('action_message_action', $schedule->getActionPluginId());
    $this->assertSame('Hello', $schedule->getActionConfiguration()['message']);
    $plugin = $schedule->getActionPlugin();
    $this->assertInstanceOf(MessageAction::class, $plugin);
    $this->assertSame('Hello', $plugin->getConfiguration()['message']);
    $this->assertSame(ScheduledActionInterface::STATUS_PENDING, $schedule->getStatus());
    $this->assertStringStartsWith('Display a message to the user on ', (string) $schedule->label());
  }

  /**
   * Tests a stand-alone action rejects a target.
   */
  public function testSystemActionRejectsTarget(): void {
    $schedule = $this->createSchedule('action_message_action', ['message' => 'Hello'])
      ->setTargetEntity($this->targetUser);
    $this->assertSame([
      'target_entity_id' => 'The action <em class="placeholder">Display a message to the user</em> does not use a target entity.',
    ], $this->getViolations($schedule));
  }

  /**
   * Tests an action on an entity requires a target of the right type.
   */
  public function testEntityActionTarget(): void {
    $schedule = $this->createSchedule('user_block_user_action');
    $this->assertSame([
      'target_entity_id' => 'The action <em class="placeholder">Block the selected users</em> requires a target <em class="placeholder">user</em>.',
    ], $this->getViolations($schedule));

    $schedule->set('target_entity_type', 'node');
    $schedule->set('target_entity_id', $this->targetUser->id());
    $this->assertSame([
      'target_entity_id' => 'The action <em class="placeholder">Block the selected users</em> can only be executed on a <em class="placeholder">user</em>.',
    ], $this->getViolations($schedule));

    $schedule->setTargetEntity($this->targetUser);
    $this->assertSame([], $this->getViolations($schedule));
    $schedule->save();

    $schedule = ScheduledAction::load($schedule->id());
    $this->assertSame('user', $schedule->getTargetEntityTypeId());
    $this->assertSame((int) $this->targetUser->id(), $schedule->getTargetEntityId());
    $this->assertSame($this->targetUser->id(), $schedule->getTargetEntity()?->id());
  }

  /**
   * Tests a missing target is only rejected while the schedule is pending.
   */
  public function testMissingTarget(): void {
    $schedule = $this->createSchedule('user_block_user_action');
    $schedule->set('target_entity_type', 'user');
    $schedule->set('target_entity_id', 999);
    $this->assertSame([
      'target_entity_id' => 'The target <em class="placeholder">user</em> with ID <em class="placeholder">999</em> does not exist.',
    ], $this->getViolations($schedule));
    $this->assertNull($schedule->getTargetEntity());

    $schedule->setStatus(ScheduledActionInterface::STATUS_FAILED);
    $this->assertSame([], $this->getViolations($schedule));
  }

  /**
   * Tests unknown and non-schedulable plugins are rejected.
   */
  public function testInvalidPlugins(): void {
    $schedule = $this->createSchedule('action_scheduler_missing_plugin');
    $this->assertSame([
      'plugin' => 'The action plugin <em class="placeholder">action_scheduler_missing_plugin</em> does not exist.',
    ], $this->getViolations($schedule));
    $this->assertNull($schedule->getActionPlugin());
    $this->assertStringStartsWith('action_scheduler_missing_plugin on ', (string) $schedule->label());

    $schedule = $this->createSchedule('entity:save_action:scheduled_action');
    $this->assertSame([
      'plugin' => 'The action <em class="placeholder">Save scheduled action</em> cannot be scheduled.',
    ], $this->getViolations($schedule));
  }

  /**
   * Tests processed schedules cannot be deleted.
   */
  public function testProcessedAccess(): void {
    $manager = $this->createUser(['administer scheduled actions']);
    $schedule = $this->createSchedule('action_message_action', ['message' => 'Hello']);
    $schedule->save();
    $this->assertFalse($schedule->isProcessed());
    $this->assertTrue($schedule->access('update', $manager));
    $this->assertTrue($schedule->access('delete', $manager));

    $access_handler = $this->container->get('entity_type.manager')->getAccessControlHandler('scheduled_action');
    foreach ([ScheduledActionInterface::STATUS_EXECUTED, ScheduledActionInterface::STATUS_FAILED] as $status) {
      $schedule->setStatus($status)->save();
      // Access results are cached for the request.
      $access_handler->resetCache();
      $this->assertTrue($schedule->isProcessed());
      // The edit form stays reachable, read-only.
      $this->assertTrue($schedule->access('update', $manager));
      $this->assertFalse($schedule->access('delete', $manager));
    }
  }

  /**
   * Tests processed schedules cannot be changed.
   */
  public function testProcessedChanges(): void {
    $schedule = $this->createSchedule('action_message_action', ['message' => 'Hello']);
    $schedule->save();

    // Pending schedules can be changed, and marked as processed.
    $schedule->set('scheduled', '2026-11-02T10:30:00');
    $schedule->setStatus(ScheduledActionInterface::STATUS_EXECUTED)
      ->setExecutedTime(1793300000);
    $this->assertSame([], $this->getViolations($schedule));
    $schedule->save();

    // Saving a processed schedule unchanged is allowed.
    $schedule = ScheduledAction::load($schedule->id());
    $schedule->setChangedTime(1793400000);
    $this->assertSame([], $this->getViolations($schedule));

    $label = (string) $schedule->label();
    $message = 'The scheduled action <em class="placeholder">' . $label . '</em> has been processed: it can no longer be changed.';
    $changes = [
      'scheduled' => '2026-11-03T10:30:00',
      'status' => ScheduledActionInterface::STATUS_PENDING,
      'configuration' => [['message' => 'Bye']],
    ];
    foreach ($changes as $field_name => $value) {
      $schedule = ScheduledAction::load($schedule->id());
      $schedule->set($field_name, $value);
      $this->assertSame(['' => $message], $this->getViolations($schedule), $field_name);
    }
  }

  /**
   * Tests the plugin options offered by the target resolver.
   */
  public function testPluginOptions(): void {
    $resolver = $this->container->get(ScheduledActionTargetResolver::class);
    $options = $resolver->getPluginOptions();

    $this->assertArrayHasKey('action_message_action', $options['System']);
    $this->assertArrayHasKey('action_send_email_action', $options['System']);
    $this->assertArrayHasKey('user_block_user_action', $options['User']);
    $this->assertArrayNotHasKey('Scheduled action', $options);
    $this->assertSame('user', $resolver->getTargetEntityTypeId('user_block_user_action'));
    $this->assertNull($resolver->getTargetEntityTypeId('action_message_action'));
    $this->assertTrue($resolver->needsConfirmation('user_cancel_user_action'));
    $this->assertFalse($resolver->needsConfirmation('user_block_user_action'));
    $this->assertFalse($resolver->needsConfirmation('action_scheduler_missing_plugin'));

    // Only the actions on users, for the add form of a user's tab.
    $user_options = $resolver->getPluginOptions('user');
    $this->assertSame(['User'], array_keys($user_options));
    $this->assertArrayHasKey('user_block_user_action', $user_options['User']);

    // Users get a tab; scheduled actions themselves do not.
    $this->assertSame(['user'], $resolver->getSupportedEntityTypeIds());
    $entity_type_manager = $this->container->get('entity_type.manager');
    $this->assertSame('/user/{user}/scheduled-actions', $entity_type_manager->getDefinition('user')->getLinkTemplate(ScheduledActionTargetResolver::LINK_TEMPLATE));
    $this->assertFalse($entity_type_manager->getDefinition('scheduled_action')->hasLinkTemplate(ScheduledActionTargetResolver::LINK_TEMPLATE));
  }

  /**
   * Tests deleting a target warns about its pending schedules only.
   */
  public function testTargetDeletionWarning(): void {
    $messenger = $this->container->get('messenger');

    $failed = $this->createSchedule('user_block_user_action')
      ->setTargetEntity($this->targetUser)
      ->setStatus(ScheduledActionInterface::STATUS_FAILED);
    $failed->save();
    $other_user = $this->createUser();
    $other_user->delete();
    $this->assertEmpty($messenger->messagesByType(MessengerInterface::TYPE_WARNING));

    $this->createSchedule('user_block_user_action')
      ->setTargetEntity($this->targetUser)
      ->save();
    $label = $this->targetUser->label();
    $this->targetUser->delete();

    $warnings = $messenger->messagesByType(MessengerInterface::TYPE_WARNING);
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString($label . '</em> has 1 pending', (string) $warnings[0]);
    $this->assertStringContainsString('href="/admin/content/scheduled-action"', (string) $warnings[0]);
    // Schedules are kept: they fail when executed.
    $this->assertCount(2, ScheduledAction::loadMultiple());
  }

  /**
   * Creates an unsaved schedule.
   *
   * @param string $plugin_id
   *   The action plugin ID.
   * @param array $configuration
   *   The plugin configuration.
   *
   * @return \Drupal\action_scheduler\ScheduledActionInterface
   *   The schedule.
   */
  protected function createSchedule(string $plugin_id, array $configuration = []): ScheduledActionInterface {
    $schedule = ScheduledAction::create([
      'scheduled' => '2026-11-01T10:30:00',
    ]);
    assert($schedule instanceof ScheduledActionInterface);
    return $schedule->setActionPlugin($plugin_id, $configuration);
  }

  /**
   * Validates a schedule.
   *
   * @param \Drupal\action_scheduler\ScheduledActionInterface $schedule
   *   The schedule.
   *
   * @return array<string, string>
   *   The violation messages, keyed by property path.
   */
  protected function getViolations(ScheduledActionInterface $schedule): array {
    $messages = [];
    foreach ($schedule->validate() as $violation) {
      $messages[$violation->getPropertyPath()] = (string) $violation->getMessage();
    }
    return $messages;
  }

}
