<?php

declare(strict_types=1);

namespace Drupal\Tests\action_scheduler\Kernel;

use Drupal\action_scheduler\Entity\ScheduledAction;
use Drupal\action_scheduler\ScheduledActionExecutor;
use Drupal\action_scheduler\ScheduledActionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\entity_test\Entity\EntityTestMulChanged;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the execution of scheduled actions with Drush, cron and the queue.
 */
#[Group('action_scheduler')]
#[RunTestsInSeparateProcesses]
class ScheduledActionExecutionTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'action_scheduler',
    'datetime',
    'entity_test',
    'options',
    'system',
    'user',
  ];

  /**
   * A user allowed to block users.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $admin;

  /**
   * A user without permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $editor;

  /**
   * The executor.
   *
   * @var \Drupal\action_scheduler\ScheduledActionExecutor
   */
  protected ScheduledActionExecutor $executor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('scheduled_action');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system']);
    // Use up user ID 1, which bypasses access checks.
    $this->createUser();
    $this->admin = $this->createUser(['administer users']);
    $this->editor = $this->createUser();
    $this->executor = $this->container->get(ScheduledActionExecutor::class);
  }

  /**
   * Tests the due schedules are executed or failed with the right reason.
   */
  public function testExecuteDue(): void {
    $blocked = $this->createUser();
    $not_blocked = $this->createUser();
    $deleted = $this->createUser();

    $executed = $this->createSchedule('user_block_user_action', $this->admin, '2020-01-01T00:00:00', $blocked);
    $denied = $this->createSchedule('user_block_user_action', $this->editor, '2020-01-01T00:01:00', $not_blocked);
    $missing_target = $this->createSchedule('user_block_user_action', $this->admin, '2020-01-01T00:02:00', $deleted);
    $message = $this->createSchedule('action_message_action', $this->editor, '2020-01-01T00:03:00', NULL, ['message' => 'Hello']);
    $future = $this->createSchedule('user_block_user_action', $this->admin, '2099-01-01T00:00:00', $not_blocked);
    $deleted->delete();

    $this->assertSame([
      (int) $executed->id(),
      (int) $denied->id(),
      (int) $missing_target->id(),
      (int) $message->id(),
    ], $this->executor->getDueIds());

    $request_time = $this->container->get('datetime.time')->getCurrentTime();
    $this->assertSame([
      ScheduledActionInterface::STATUS_EXECUTED => 2,
      ScheduledActionInterface::STATUS_FAILED => 2,
    ], $this->executor->executeDue());

    $this->assertStatus(ScheduledActionInterface::STATUS_EXECUTED, $executed);
    $this->assertTrue(User::load($blocked->id())->isBlocked());
    $this->assertStatus(ScheduledActionInterface::STATUS_FAILED, $denied);
    $this->assertTrue(User::load($not_blocked->id())->isActive());
    $this->assertStatus(ScheduledActionInterface::STATUS_FAILED, $missing_target);
    $this->assertStatus(ScheduledActionInterface::STATUS_EXECUTED, $message);
    $this->assertStatus(ScheduledActionInterface::STATUS_PENDING, $future);
    $this->assertNull(ScheduledAction::load($future->id())->getExecutedTime());
    $this->assertGreaterThanOrEqual($request_time, ScheduledAction::load($executed->id())->getExecutedTime());

    // Nothing is due any more, and a run schedule is never run again.
    $this->assertSame([], $this->executor->getDueIds());
    $this->assertNull($this->executor->executeById((int) $executed->id()));
    $this->assertNull($this->executor->executeById(999));
  }

  /**
   * Tests a schedule fails when its plugin or owner no longer exists.
   */
  public function testMissingPluginAndOwner(): void {
    $missing_plugin = $this->createSchedule('action_scheduler_missing_plugin', $this->admin, '2020-01-01T00:00:00');
    $owner = $this->createUser();
    $missing_owner = $this->createSchedule('action_message_action', $owner, '2020-01-01T00:01:00', NULL, ['message' => 'Hello']);
    $owner->delete();

    $this->assertSame([
      ScheduledActionInterface::STATUS_EXECUTED => 0,
      ScheduledActionInterface::STATUS_FAILED => 2,
    ], $this->executor->executeDue());
    $this->assertStatus(ScheduledActionInterface::STATUS_FAILED, $missing_plugin);
    $this->assertStatus(ScheduledActionInterface::STATUS_FAILED, $missing_owner);
  }

  /**
   * Tests cron queues the due schedules and executes them.
   */
  public function testCron(): void {
    $target = $this->createUser();
    $due = $this->createSchedule('user_block_user_action', $this->admin, '2020-01-01T00:00:00', $target);
    $future = $this->createSchedule('user_block_user_action', $this->admin, '2099-01-01T00:00:00', $target);

    // Nothing is queued again while the queue holds items.
    $queue = $this->container->get('queue')->get(ScheduledActionExecutor::QUEUE_NAME);
    $this->assertSame(1, $this->executor->queueDue());
    $this->assertSame(0, $this->executor->queueDue());
    $this->assertSame(1, $queue->numberOfItems());
    $queue->deleteQueue();

    $this->container->get('cron')->run();
    $this->assertSame(0, $queue->numberOfItems());
    $this->assertStatus(ScheduledActionInterface::STATUS_EXECUTED, $due);
    $this->assertStatus(ScheduledActionInterface::STATUS_PENDING, $future);
    $this->assertTrue(User::load($target->id())->isBlocked());
  }

  /**
   * Tests the action deleting entities right away.
   */
  public function testDeleteEntity(): void {
    $this->installEntitySchema('entity_test_mul_changed');
    // Users are cancelled, not deleted.
    $this->assertFalse($this->container->get('plugin.manager.action')->hasDefinition('action_scheduler_entity_delete:user'));

    $deleted = EntityTestMulChanged::create(['name' => 'Deleted']);
    $deleted->save();
    $kept = EntityTestMulChanged::create(['name' => 'Kept']);
    $kept->save();
    $owner = $this->createUser(['administer entity_test content']);
    $executed = $this->createSchedule('action_scheduler_entity_delete:entity_test_mul_changed', $owner, '2020-01-01T00:00:00', $deleted);
    $denied = $this->createSchedule('action_scheduler_entity_delete:entity_test_mul_changed', $this->editor, '2020-01-01T00:01:00', $kept);
    $this->createSchedule('action_scheduler_entity_delete:entity_test_mul_changed', $owner, '2099-01-01T00:00:00', $deleted);

    $this->executor->executeDue();
    $this->assertStatus(ScheduledActionInterface::STATUS_EXECUTED, $executed);
    $this->assertNull(EntityTestMulChanged::load($deleted->id()));
    // The deletion warning leaves out the schedule that deleted the target.
    $warnings = $this->container->get('messenger')->messagesByType(MessengerInterface::TYPE_WARNING);
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString('Deleted</em> has 1 pending', (string) $warnings[0]);
    $this->assertStatus(ScheduledActionInterface::STATUS_FAILED, $denied);
    $this->assertNotNull(EntityTestMulChanged::load($kept->id()));
  }

  /**
   * Tests the default notification follows the default cancellation method.
   */
  public function testCancelUserDefaults(): void {
    $this->installConfig(['user']);
    $this->config('user.settings')
      ->set('notify.status_blocked', TRUE)
      ->set('notify.status_canceled', FALSE)
      ->set('cancel_method', 'user_cancel_block')
      ->save();
    $action_manager = $this->container->get('plugin.manager.action');
    $this->assertSame([
      'user_cancel_method' => 'user_cancel_block',
      'user_cancel_notify' => TRUE,
    ], $action_manager->createInstance('action_scheduler_user_cancel')->getConfiguration());

    $this->config('user.settings')->set('cancel_method', 'user_cancel_reassign')->save();
    $this->assertSame([
      'user_cancel_method' => 'user_cancel_reassign',
      'user_cancel_notify' => FALSE,
    ], $action_manager->createInstance('action_scheduler_user_cancel')->getConfiguration());
  }

  /**
   * Tests the action cancelling user accounts right away.
   */
  public function testCancelUser(): void {
    $blocked = $this->createUser();
    $deleted = $this->createUser();
    $not_cancelled = $this->createUser();
    $block = $this->createSchedule('action_scheduler_user_cancel', $this->admin, '2020-01-01T00:00:00', $blocked, [
      'user_cancel_method' => 'user_cancel_block',
      'user_cancel_notify' => FALSE,
    ]);
    $delete = $this->createSchedule('action_scheduler_user_cancel', $this->admin, '2020-01-01T00:01:00', $deleted, [
      'user_cancel_method' => 'user_cancel_reassign',
      'user_cancel_notify' => FALSE,
    ]);
    $denied = $this->createSchedule('action_scheduler_user_cancel', $this->editor, '2020-01-01T00:02:00', $not_cancelled);
    // Like the core bulk cancel form, user 1 cannot be cancelled.
    $root = $this->createSchedule('action_scheduler_user_cancel', $this->admin, '2020-01-01T00:03:00', User::load(1));

    // The batch prepared by user_cancel() runs without a route, like on
    // the command line.
    $this->assertSame([
      ScheduledActionInterface::STATUS_EXECUTED => 2,
      ScheduledActionInterface::STATUS_FAILED => 2,
    ], $this->executor->executeDue());
    $this->assertStatus(ScheduledActionInterface::STATUS_EXECUTED, $block);
    $this->assertTrue(User::load($blocked->id())->isBlocked());
    $this->assertStatus(ScheduledActionInterface::STATUS_EXECUTED, $delete);
    $this->assertNull(User::load($deleted->id()));
    // The only schedule of the deleted user is the one that deleted it.
    $this->assertSame([], $this->container->get('messenger')->messagesByType(MessengerInterface::TYPE_WARNING));
    $this->assertStatus(ScheduledActionInterface::STATUS_FAILED, $denied);
    $this->assertTrue(User::load($not_cancelled->id())->isActive());
    $this->assertStatus(ScheduledActionInterface::STATUS_FAILED, $root);
    $this->assertNotNull(User::load(1));
  }

  /**
   * Creates and saves a schedule.
   *
   * @param string $plugin_id
   *   The action plugin ID.
   * @param \Drupal\user\UserInterface $owner
   *   The user the action runs as.
   * @param string $scheduled
   *   The scheduled date, in UTC storage format.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $target
   *   The target entity, if the action needs one.
   * @param array $configuration
   *   The plugin configuration.
   *
   * @return \Drupal\action_scheduler\ScheduledActionInterface
   *   The schedule.
   */
  protected function createSchedule(string $plugin_id, UserInterface $owner, string $scheduled, ?ContentEntityInterface $target = NULL, array $configuration = []): ScheduledActionInterface {
    $schedule = ScheduledAction::create(['scheduled' => $scheduled]);
    assert($schedule instanceof ScheduledActionInterface);
    $schedule->setActionPlugin($plugin_id, $configuration)
      ->setTargetEntity($target)
      ->setOwner($owner)
      ->save();
    return $schedule;
  }

  /**
   * Asserts the stored status of a schedule.
   *
   * @param string $expected
   *   The expected status.
   * @param \Drupal\action_scheduler\ScheduledActionInterface $schedule
   *   The schedule.
   */
  protected function assertStatus(string $expected, ScheduledActionInterface $schedule): void {
    $stored = ScheduledAction::load($schedule->id());
    $this->assertSame($expected, $stored?->getStatus(), sprintf('Schedule %s is %s.', $schedule->id(), $expected));
  }

}
