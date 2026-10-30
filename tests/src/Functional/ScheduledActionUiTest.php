<?php

declare(strict_types=1);

namespace Drupal\Tests\action_scheduler\Functional;

use Drupal\action_scheduler\Entity\ScheduledAction;
use Drupal\action_scheduler\ScheduledActionInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the scheduled action pages without JavaScript.
 */
#[Group('action_scheduler')]
#[RunTestsInSeparateProcesses]
class ScheduledActionUiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['action_scheduler'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests only users with the permission can manage schedules.
   */
  public function testAccess(): void {
    $schedule = ScheduledAction::create([
      'plugin' => 'action_message_action',
      'configuration' => [['message' => 'Hello']],
      'scheduled' => '2026-11-01T10:30:00',
    ]);
    $schedule->save();
    $paths = [
      'admin/content/scheduled-action',
      'admin/content/scheduled-action/add',
      'admin/content/scheduled-action/' . $schedule->id(),
      'admin/content/scheduled-action/' . $schedule->id() . '/delete',
    ];

    foreach ($paths as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);
    }

    $this->drupalLogin($this->drupalCreateUser(['access content']));
    foreach ($paths as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);
    }

    $this->drupalLogin($this->drupalCreateUser(['administer scheduled actions']));
    foreach ($paths as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(200);
    }

    // There is no detail page: the entity URL is the edit form.
    $this->assertSame($schedule->toUrl('edit-form')->toString(), $schedule->toUrl()->toString());
    $this->drupalGet($schedule->toUrl());
    $this->assertSession()->fieldValueEquals('plugin', 'action_message_action');
    $this->assertSession()->fieldValueEquals('settings[configuration][message]', 'Hello');
    $this->assertSession()->buttonExists('Save');
    $this->assertSession()->pageTextContains('Edit Display a message to the user on');
    $this->drupalGet('admin/content/scheduled-action/' . $schedule->id() . '/edit');
    $this->assertSession()->statusCodeEquals(404);

    // The list offers no "View" operation next to "Edit".
    $this->drupalGet('admin/content/scheduled-action');
    $this->assertSession()->linkExists('Edit');
    $this->assertSession()->linkNotExists('View');
  }

  /**
   * Tests the list shows the latest scheduled date first.
   */
  public function testListOrder(): void {
    $ids = [];
    foreach (['2026-11-02T10:00:00', '2026-11-03T10:00:00', '2026-11-01T10:00:00'] as $scheduled) {
      $schedule = ScheduledAction::create([
        'plugin' => 'action_message_action',
        'configuration' => [['message' => 'Hello']],
        'scheduled' => $scheduled,
      ]);
      $schedule->save();
      $ids[$scheduled] = $schedule->toUrl()->toString();
    }
    krsort($ids);

    $this->drupalLogin($this->drupalCreateUser(['administer scheduled actions']));
    $this->drupalGet('admin/content/scheduled-action');
    $links = $this->getSession()->getPage()->findAll('xpath', '//tbody//a[normalize-space()="Edit"]');
    $this->assertSame(array_values($ids), array_map(static fn ($link): string => strtok((string) $link->getAttribute('href'), '?'), $links));
  }

  /**
   * Tests actions relying on a confirmation form show a warning.
   */
  public function testConfirmationWarning(): void {
    $warning = 'This action does not do the work itself: it only prepares a confirmation form for the user who triggers it.';
    $this->drupalLogin($this->drupalCreateUser(['administer scheduled actions']));
    $this->drupalGet('admin/content/scheduled-action/add');
    $this->assertSession()->pageTextNotContains($warning);

    $this->submitForm(['plugin' => 'user_cancel_user_action'], 'Update action settings');
    $this->assertSession()->pageTextContains($warning);

    $this->submitForm(['plugin' => 'user_block_user_action'], 'Update action settings');
    $this->assertSession()->pageTextNotContains($warning);
  }

  /**
   * Tests adding schedules when the settings are rebuilt without Ajax.
   */
  public function testAddWithoutJavascript(): void {
    $target = $this->drupalCreateUser();
    $admin = $this->drupalCreateUser(['administer scheduled actions']);
    $this->drupalLogin($admin);
    $assert = $this->assertSession();

    // An action on an entity: the button reveals the target field.
    $this->drupalGet('admin/content/scheduled-action/add');
    $this->assertAuthorAndStatusHidden();
    $assert->fieldNotExists('settings[target_entity_id]');
    $this->submitForm(['plugin' => 'user_block_user_action'], 'Update action settings');
    $assert->fieldExists('settings[target_entity_id]');
    $assert->fieldNotExists('settings[configuration][message]');

    $this->submitForm([
      'settings[target_entity_id]' => $target->getAccountName() . ' (' . $target->id() . ')',
      'scheduled[0][value][date]' => '2026-11-25',
      'scheduled[0][value][time]' => '08:15:00',
    ], 'Save');
    $assert->pageTextContains('Created the scheduled action Block the selected users on');
    // Without "access user profiles" the target is not linked.
    $assert->linkNotExists($target->getDisplayName());
    $assert->pageTextContains('user ' . $target->id());

    // A stand-alone action: the button reveals its settings, not the target.
    $this->drupalGet('admin/content/scheduled-action/add');
    $this->submitForm(['plugin' => 'action_message_action'], 'Update action settings');
    $assert->fieldNotExists('settings[target_entity_id]');
    $assert->fieldExists('settings[configuration][message]');

    $this->submitForm([
      'settings[configuration][message]' => 'Scheduled hello',
      'scheduled[0][value][date]' => '2026-12-01',
      'scheduled[0][value][time]' => '12:00:00',
    ], 'Save');
    $assert->pageTextContains('Created the scheduled action Display a message to the user on');

    $schedules = ScheduledAction::loadMultiple();
    $this->assertCount(2, $schedules);
    [$block, $message] = array_values($schedules);
    $this->assertSame('user', $block->getTargetEntityTypeId());
    $this->assertSame((int) $target->id(), $block->getTargetEntityId());
    $this->assertNull($message->getTargetEntityId());
    $this->assertSame('Scheduled hello', $message->getActionConfiguration()['message']);
    foreach ([$block, $message] as $schedule) {
      $this->assertSame($admin->id(), $schedule->getOwnerId());
      $this->assertSame(ScheduledActionInterface::STATUS_PENDING, $schedule->getStatus());
    }

    // Editing as another user keeps author and status out of the form and
    // unchanged.
    $this->drupalLogin($this->drupalCreateUser(['administer scheduled actions']));
    $this->drupalGet($block->toUrl('edit-form'));
    $this->assertAuthorAndStatusHidden();
    $this->submitForm([
      'scheduled[0][value][time]' => '09:45:00',
    ], 'Save');
    $assert->pageTextContains('Updated the scheduled action Block the selected users on');
    $block = ScheduledAction::load($block->id());
    $this->assertSame($admin->id(), $block->getOwnerId());
    $this->assertSame(ScheduledActionInterface::STATUS_PENDING, $block->getStatus());
  }

  /**
   * Tests processed schedules are shown read-only and cannot be deleted.
   */
  public function testProcessedReadOnly(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer scheduled actions']));
    $assert = $this->assertSession();
    $pending = ScheduledAction::create([
      'plugin' => 'action_message_action',
      'configuration' => [['message' => 'Pending']],
      'scheduled' => '2026-11-01T10:30:00',
    ]);
    $pending->save();
    $executed = ScheduledAction::create([
      'plugin' => 'action_message_action',
      'configuration' => [['message' => 'Done']],
      'scheduled' => '2026-11-01T10:30:00',
      'status' => ScheduledActionInterface::STATUS_EXECUTED,
    ]);
    $executed->save();

    // A pending schedule is editable and can be deleted.
    $this->drupalGet($pending->toUrl());
    $assert->pageTextNotContains('it can no longer be changed or deleted');
    $assert->fieldEnabled('plugin');
    $assert->fieldEnabled('settings[configuration][message]');
    $assert->buttonExists('Save');
    $assert->linkExists('Delete');

    // A processed schedule is shown with every element disabled.
    $this->drupalGet($executed->toUrl());
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('This scheduled action has been processed: it can no longer be changed or deleted.');
    $assert->fieldDisabled('plugin');
    $assert->fieldDisabled('settings[configuration][message]');
    $assert->fieldValueEquals('settings[configuration][message]', 'Done');
    $assert->fieldDisabled('scheduled[0][value][date]');
    $assert->elementAttributeExists('css', 'input[type="submit"][value="Save"]', 'disabled');
    $assert->elementAttributeExists('css', 'input[value="Delete"]', 'disabled');
    $assert->linkByHrefNotExists($executed->toUrl('delete-form')->toString());

    $this->drupalGet($executed->toUrl('delete-form'));
    $assert->statusCodeEquals(403);

    // The list offers only "Edit" for the processed schedule.
    $this->drupalGet('admin/content/scheduled-action');
    $assert->linkByHrefExists($pending->toUrl('delete-form')->toString());
    $assert->linkByHrefNotExists($executed->toUrl('delete-form')->toString());
  }

  /**
   * Asserts the form shows neither the author nor the status.
   */
  protected function assertAuthorAndStatusHidden(): void {
    $this->assertSession()->fieldNotExists('uid[0][target_id]');
    $this->assertSession()->fieldNotExists('status');
    $this->assertSession()->pageTextNotContains('Created by');
  }

}
