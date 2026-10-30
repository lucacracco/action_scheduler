<?php

declare(strict_types=1);

namespace Drupal\Tests\action_scheduler\Functional;

use Drupal\action_scheduler\Entity\ScheduledAction;
use Drupal\action_scheduler\ScheduledActionInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the "Scheduled actions" tab of the entities actions run on.
 */
#[Group('action_scheduler')]
#[RunTestsInSeparateProcesses]
class ScheduledActionTargetTabTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['action_scheduler', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The user the schedules run on.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $target;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalPlaceBlock('local_tasks_block');
    $this->target = $this->drupalCreateUser([], 'scheduler_target');
  }

  /**
   * Tests the tab is only available to users who may edit the entity.
   */
  public function testAccess(): void {
    $path = 'user/' . $this->target->id() . '/scheduled-actions';
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);

    // Managing schedules is not enough: the entity must be editable too.
    $this->drupalLogin($this->drupalCreateUser(['administer scheduled actions']));
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer users']));
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer scheduled actions', 'administer users']));
    $this->drupalGet($this->target->toUrl('edit-form'));
    $this->assertSession()->linkByHrefExists('/' . $path);
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Scheduled actions for scheduler_target');
  }

  /**
   * Tests the tab lists the entity's schedules, pending ones first.
   */
  public function testListing(): void {
    $later = $this->createSchedule('2026-12-02T10:00:00');
    $sooner = $this->createSchedule('2026-12-01T10:00:00');
    $executed = $this->createSchedule('2026-11-01T10:00:00', ScheduledActionInterface::STATUS_EXECUTED);
    $other_user = $this->drupalCreateUser();
    $this->createSchedule('2026-12-03T10:00:00', ScheduledActionInterface::STATUS_PENDING, $other_user);

    $this->drupalLogin($this->drupalCreateUser(['administer scheduled actions', 'administer users']));
    $this->drupalGet('user/' . $this->target->id() . '/scheduled-actions');

    $pending_links = $this->getEditLinks('Pending');
    $this->assertSame([
      $sooner->toUrl()->toString(),
      $later->toUrl()->toString(),
    ], $pending_links);
    $this->assertSame([$executed->toUrl()->toString()], $this->getEditLinks('Processed'));
    // Each section only shows the columns that matter for it.
    $this->assertSame(['Action', 'Scheduled for', 'Created by', 'Created', 'Operations'], $this->getHeader('Pending'));
    $this->assertSame(['Action', 'Status', 'Executed', 'Created by', 'Created', 'Operations'], $this->getHeader('Processed'));
    // Only processed schedules have no delete link.
    $this->assertSession()->linkByHrefExists($sooner->toUrl('delete-form')->toString());
    $this->assertSession()->linkByHrefNotExists($executed->toUrl('delete-form')->toString());
  }

  /**
   * Tests adding a schedule from the tab, without JavaScript.
   */
  public function testAddFromTab(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer scheduled actions', 'administer users']));
    $tab = 'user/' . $this->target->id() . '/scheduled-actions';
    $this->drupalGet($tab);
    $this->assertSession()->pageTextContains('There are no pending scheduled actions.');
    $this->assertSession()->pageTextContains('There are no processed scheduled actions.');
    $this->clickLink('Add scheduled action');

    // The target is fixed and only actions on users are offered.
    $this->assertSession()->pageTextContains('scheduler_target');
    $this->assertSession()->optionExists('plugin', 'user_block_user_action');
    $this->assertSession()->optionNotExists('plugin', 'action_message_action');
    $this->submitForm(['plugin' => 'user_block_user_action'], 'Update action settings');
    $this->assertSession()->fieldNotExists('settings[target_entity_id]');
    $this->submitForm([
      'scheduled[0][value][date]' => '2026-12-01',
      'scheduled[0][value][time]' => '10:00:00',
    ], 'Save');

    // Back on the tab, with the new pending schedule.
    $this->assertSession()->addressEquals($tab);
    $this->assertSession()->pageTextContains('Created the scheduled action Block the selected users on');
    $schedules = ScheduledAction::loadMultiple();
    $this->assertCount(1, $schedules);
    $schedule = reset($schedules);
    $this->assertSame('user', $schedule->getTargetEntityTypeId());
    $this->assertSame((int) $this->target->id(), $schedule->getTargetEntityId());
    $this->assertSame([$schedule->toUrl()->toString()], $this->getEditLinks('Pending'));
  }

  /**
   * Tests an unsupported target in the add form URL is ignored.
   */
  public function testUnsupportedTarget(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer scheduled actions', 'administer users']));
    $this->drupalGet('admin/content/scheduled-action/add', [
      'query' => ['target_type' => 'scheduled_action', 'target_id' => 1],
    ]);
    $this->assertSession()->optionExists('plugin', 'action_message_action');
    $this->assertSession()->optionExists('plugin', 'user_block_user_action');
  }

  /**
   * Creates a schedule blocking a user.
   *
   * @param string $scheduled
   *   The scheduled date, in UTC storage format.
   * @param string $status
   *   The status.
   * @param \Drupal\user\UserInterface|null $target
   *   The target, or NULL for the default target.
   *
   * @return \Drupal\action_scheduler\ScheduledActionInterface
   *   The schedule.
   */
  protected function createSchedule(string $scheduled, string $status = ScheduledActionInterface::STATUS_PENDING, ?UserInterface $target = NULL): ScheduledActionInterface {
    $schedule = ScheduledAction::create(['scheduled' => $scheduled]);
    assert($schedule instanceof ScheduledActionInterface);
    $schedule->setActionPlugin('user_block_user_action')
      ->setTargetEntity($target ?? $this->target)
      ->setStatus($status)
      ->save();
    return $schedule;
  }

  /**
   * Gets the column titles of a section of the tab.
   *
   * @param string $section
   *   The section title.
   *
   * @return string[]
   *   The column titles, in order.
   */
  protected function getHeader(string $section): array {
    $cells = $this->getSession()->getPage()->findAll('xpath', '//details[summary[normalize-space()="' . $section . '"]]//thead//th');
    return array_map(static fn ($cell): string => trim($cell->getText()), $cells);
  }

  /**
   * Gets the edit links of a section of the tab, in order.
   *
   * @param string $section
   *   The section title.
   *
   * @return string[]
   *   The link paths, without query.
   */
  protected function getEditLinks(string $section): array {
    $links = $this->getSession()->getPage()->findAll('xpath', '//details[summary[normalize-space()="' . $section . '"]]//tbody//a[normalize-space()="Edit"]');
    return array_map(static fn ($link): string => strtok((string) $link->getAttribute('href'), '?'), $links);
  }

}
