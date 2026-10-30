<?php

declare(strict_types=1);

namespace Drupal\Tests\action_scheduler\Kernel;

use Drupal\action_scheduler\Entity\ScheduledAction;
use Drupal\action_scheduler\Event\ScheduledActionEvents;
use Drupal\action_scheduler\ScheduledActionExecutor;
use Drupal\action_scheduler\ScheduledActionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the events dispatched when scheduled actions are executed.
 */
#[Group('action_scheduler')]
#[RunTestsInSeparateProcesses]
class ScheduledActionEventsTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'action_scheduler',
    'action_scheduler_test',
    'datetime',
    'options',
    'system',
    'user',
  ];

  /**
   * The owner of the schedules.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $owner;

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
    $this->owner = $this->createUser();
    $this->executor = $this->container->get(ScheduledActionExecutor::class);
  }

  /**
   * Tests both events are dispatched, for every outcome.
   */
  public function testEvents(): void {
    $executed = $this->createSchedule('action_message_action', '2020-01-01T00:00:00');
    // The pre-execute event comes before any check.
    $failed = $this->createSchedule('action_scheduler_missing_plugin', '2020-01-01T00:01:00');

    $this->executor->executeDue();
    $missing = 'the action plugin action_scheduler_missing_plugin does not exist or cannot be scheduled.';
    $this->assertSame([
      [ScheduledActionEvents::PRE_EXECUTE, (int) $executed->id(), ScheduledActionInterface::STATUS_PENDING, NULL],
      [ScheduledActionEvents::POST_EXECUTE, (int) $executed->id(), ScheduledActionInterface::STATUS_EXECUTED, NULL],
      [ScheduledActionEvents::PRE_EXECUTE, (int) $failed->id(), ScheduledActionInterface::STATUS_PENDING, NULL],
      [ScheduledActionEvents::POST_EXECUTE, (int) $failed->id(), ScheduledActionInterface::STATUS_FAILED, $missing],
    ], $this->getEvents());
  }

  /**
   * Tests a subscriber can cancel a run.
   */
  public function testCancel(): void {
    $schedule = $this->createSchedule('action_message_action', '2020-01-01T00:00:00');
    $this->container->get('state')->set('action_scheduler_test.cancel', 'Not today');

    $this->assertSame([
      ScheduledActionInterface::STATUS_EXECUTED => 0,
      ScheduledActionInterface::STATUS_FAILED => 0,
      ScheduledActionInterface::STATUS_CANCELLED => 1,
    ], $this->executor->executeDue());

    $schedule = ScheduledAction::load($schedule->id());
    $this->assertSame(ScheduledActionInterface::STATUS_CANCELLED, $schedule->getStatus());
    $this->assertTrue($schedule->isProcessed());
    $this->assertNotNull($schedule->getExecutedTime());
    // The action did not run.
    $this->assertEmpty($this->container->get('messenger')->messagesByType(MessengerInterface::TYPE_STATUS));
    $expected = [
      ScheduledActionEvents::POST_EXECUTE,
      (int) $schedule->id(),
      ScheduledActionInterface::STATUS_CANCELLED,
      'Not today',
    ];
    $this->assertSame($expected, $this->getEvents()[1]);
  }

  /**
   * Tests a subscriber can change the configuration of a run.
   */
  public function testConfiguration(): void {
    $schedule = $this->createSchedule('action_message_action', '2020-01-01T00:00:00');
    $this->container->get('state')->set('action_scheduler_test.configuration', ['message' => 'Changed']);

    $this->executor->executeDue();
    $messages = $this->container->get('messenger')->messagesByType(MessengerInterface::TYPE_STATUS);
    $this->assertSame(['Changed'], array_map('strval', $messages));
    // The schedule keeps its own configuration.
    $schedule = ScheduledAction::load($schedule->id());
    $this->assertSame(ScheduledActionInterface::STATUS_EXECUTED, $schedule->getStatus());
    $this->assertSame('Hello', $schedule->getActionConfiguration()['message']);
  }

  /**
   * Creates and saves a schedule displaying "Hello".
   *
   * @param string $plugin_id
   *   The action plugin ID.
   * @param string $scheduled
   *   The scheduled date, in UTC storage format.
   *
   * @return \Drupal\action_scheduler\ScheduledActionInterface
   *   The schedule.
   */
  protected function createSchedule(string $plugin_id, string $scheduled): ScheduledActionInterface {
    $schedule = ScheduledAction::create(['scheduled' => $scheduled]);
    assert($schedule instanceof ScheduledActionInterface);
    $schedule->setActionPlugin($plugin_id, ['message' => 'Hello'])
      ->setOwner($this->owner)
      ->save();
    return $schedule;
  }

  /**
   * Gets the events recorded by the test subscriber.
   *
   * @return array
   *   Lists of event name, schedule ID, status and reason.
   */
  protected function getEvents(): array {
    return $this->container->get('state')->get('action_scheduler_test.events', []);
  }

}
