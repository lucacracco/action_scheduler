<?php

declare(strict_types=1);

namespace Drupal\Tests\action_scheduler\FunctionalJavascript;

use Drupal\action_scheduler\Entity\ScheduledAction;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Ajax behaviour of the scheduled action form.
 */
#[Group('action_scheduler')]
#[RunTestsInSeparateProcesses]
class ScheduledActionFormTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['action_scheduler'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer scheduled actions']));
  }

  /**
   * Tests the settings follow the selected action.
   */
  public function testSettingsFollowAction(): void {
    $target = $this->drupalCreateUser();
    $page = $this->getSession()->getPage();
    $assert = $this->assertSession();

    $this->drupalGet('admin/content/scheduled-action/add');
    $this->assertFalse($page->findButton('Update action settings')->isVisible());
    $assert->fieldNotExists('settings[target_entity_id]');

    // An action on a user shows a required user autocomplete.
    $page->selectFieldOption('plugin', 'user_block_user_action');
    $target_field = $assert->waitForField('settings[target_entity_id]');
    $this->assertNotNull($target_field);
    $this->assertTrue($target_field->hasAttribute('required'));
    $this->assertStringContainsString('/entity_reference_autocomplete/user/', (string) $target_field->getAttribute('data-autocomplete-path'));
    $assert->elementTextEquals('css', 'label[for="' . $target_field->getAttribute('id') . '"]', 'User');
    $target_field->setValue($target->getAccountName() . ' (' . $target->id() . ')');

    // A stand-alone action replaces the target with the plugin settings.
    $page->selectFieldOption('plugin', 'action_send_email_action');
    $this->assertNotNull($assert->waitForField('settings[configuration][recipient]'));
    $assert->fieldNotExists('settings[target_entity_id]');
    $assert->fieldExists('settings[configuration][subject]');
    $assert->fieldExists('settings[configuration][message]');

    // Switching back starts from an empty target.
    $page->selectFieldOption('plugin', 'user_block_user_action');
    $target_field = $assert->waitForField('settings[target_entity_id]');
    $this->assertSame('', $target_field->getValue());
    $assert->fieldNotExists('settings[configuration][recipient]');
  }

  /**
   * Tests the plugin configuration form is validated and stored.
   */
  public function testPluginConfiguration(): void {
    $page = $this->getSession()->getPage();
    $assert = $this->assertSession();

    $this->drupalGet('admin/content/scheduled-action/add');
    $page->selectFieldOption('plugin', 'action_send_email_action');
    $this->assertNotNull($assert->waitForField('settings[configuration][recipient]'));
    $page->fillField('settings[configuration][recipient]', 'not-an-email');
    $page->fillField('settings[configuration][subject]', 'Scheduler test');
    $page->fillField('settings[configuration][message]', 'Test message.');
    $page->fillField('scheduled[0][value][date]', '11/20/2026');
    $page->fillField('scheduled[0][value][time]', '09:00:00AM');
    $page->pressButton('Save');

    $assert->pageTextContains('Enter a valid email address');
    $assert->elementAttributeContains('named', ['field', 'settings[configuration][recipient]'], 'class', 'error');

    $page->fillField('settings[configuration][recipient]', 'editor@example.com');
    $page->pressButton('Save');
    $assert->pageTextContains('Created the scheduled action Send email on');

    $schedules = ScheduledAction::loadMultiple();
    $this->assertCount(1, $schedules);
    $schedule = reset($schedules);
    $this->assertSame('action_send_email_action', $schedule->getActionPluginId());
    $this->assertSame('editor@example.com', $schedule->getActionConfiguration()['recipient']);
    $this->assertNull($schedule->getTargetEntityId());
  }

}
