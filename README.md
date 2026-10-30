# Action scheduler

Action scheduler runs Drupal **actions** at a date and time you choose.
Each schedule is stored as a **Scheduled action** content entity, which
records:

- the action to run and its configuration;
- the entity the action runs on (its *target*), when the action needs one;
- when the action is scheduled to run;
- when the schedule was created and last changed, and who created it;
- when the action was executed;
- the schedule status: *Pending*, *Executed* or *Failed*.

## Requirements

- Drupal 11
- Core modules: System, Datetime, Options

## Installation

Install the module as usual, for example with Drush:

```bash
drush en action_scheduler
```

Then give the **Administer scheduled actions** permission to the roles that
manage schedules. The permission is restricted: grant it only to trusted
roles, because a schedule can run any action on the site.

## Managing schedules

Schedules are listed under **Content » Scheduled actions**
(`/admin/content/scheduled-action`), latest scheduled date first. From there
you can add, edit and delete them. Schedules have no page of their own: the URL of a schedule
(`/admin/content/scheduled-action/{id}`) opens its edit form.

### The "Scheduled actions" tab of an entity

Entities that actions run on also get a **Scheduled actions** tab, next to
*View* and *Edit*, for example `/node/12/scheduled-actions` or
`/taxonomy/term/3/scheduled-actions`. It lists the schedules of that entity in
two sections:

- **Pending**, next to run first, without the *Status* and *Executed*
  columns;
- **Processed** (executed or failed), last run first, without the
  *Scheduled for* column.

Its **Add scheduled action** button opens the add form with that entity as a
fixed target: only the actions that run on its entity type are offered, and
saving brings you back to the tab.

The tab is added automatically to every content entity type that has a page,
an edit form and at least one action that runs on it (for example content,
taxonomy terms, users, content blocks and custom menu links). It is shown to
users who have the *Administer scheduled actions* permission **and** may edit
the entity.

## How schedules are executed

A schedule is *due* when it is *Pending* and its date has passed.

- **On cron**, the due schedules are added to the
  `action_scheduler_execute` queue, which cron then processes for up to 60
  seconds per run; what is left is processed on the next runs. Nothing new is
  queued while the queue still holds items.
- **With Drush**, `drush action-scheduler:run` (alias `asr`) executes all the
  due schedules right away, without the queue.

Each action runs **as the user who created the schedule**, so it obeys that
user's permissions. A schedule is marked as **Failed** when:

- its action plugin no longer exists or cannot be scheduled;
- its target entity has been deleted;
- the user who created it has been deleted, or is not allowed to perform the
  action on the target;
- the action throws an error.

Otherwise it is marked as **Executed**. Either way the time of the run is
stored and shown in the *Executed* column of the list, and an entry is added
to the site log (channel `action_scheduler`), with the reason when the
schedule failed.

Executed and failed schedules are kept as a record: their edit form can still
be opened, but every field and button is disabled, and they cannot be
deleted. To retry a failed action, create a new schedule.

The same rule applies to schedules changed from code or through web services
(REST, JSON:API): validating an executed or failed schedule whose values have
changed reports a violation. Deleting them from code stays possible, for
example to clean up old records or before uninstalling the module.

## How actions are chosen: plugins, not configured actions

Drupal has two kinds of "action":

|                     | Action **plugin**                                                                                | Configured **action** entity                                                                                       |
|---------------------|--------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------|
| What it is          | The PHP code that does the work, e.g. `action_send_email_action` or `entity:publish_action:node` | A configuration entity (`system.action.*`) that pairs a plugin with saved settings, e.g. *Publish content*         |
| Where it comes from | Core and contributed modules                                                                     | Installed by modules, or created through the Action UI module (`drupal/action`, no longer in core since Drupal 11) |
| Used by             | —                                                                                                | Views bulk operations, among others                                                                                |

**Action scheduler uses action plugins directly.** It does not reference
configured action entities. When you create a schedule you pick a plugin, and
if the plugin has settings (for example the recipient, subject and message of
*Send email*) you fill them in the schedule form. Those settings are saved
**inside the schedule**.

This means that:

- **Every schedule has its own settings.** Two schedules can send different
  emails without creating two configuration entities.
- **Schedules do not change behind your back.** Editing or deleting a
  configured action in `/admin/config/system/actions` has no effect on
  schedules: what a schedule stores is exactly what it runs.
- **You do not need the Action UI module** to schedule actions that have
  settings.
- **Configured action entities are ignored.** If you created *Send email to
  the editors* as a configured action, you still need to enter its settings
  in each schedule.

## Actions on an entity vs. stand-alone actions

Each action plugin declares a *type*:

- **An entity type ID** (e.g. `node`, `user`, `taxonomy_term`): the action
  runs on an entity of that type, for example *Publish content* or *Block the
  selected user*. The schedule form then shows a required field to pick the
  target entity, and the autocomplete searches that entity type only.
- **`system`**: the action runs on its own, for example *Send email* or *Display
  a message to the user*. The schedule form shows no target field.

The form updates as soon as you change the action, and the same rules are
enforced when schedules are saved from code.

Only content entities can be targets, because targets are stored by numeric
ID.

## Actions provided by this module

The core actions that delete entities or cancel user accounts do nothing when
scheduled (see *Known limitations*). The module provides two actions that do
the work right away, without a confirmation form:

- **Delete … immediately** (`action_scheduler_entity_delete:*`), one for each
  content entity type except users, e.g. *Delete content item immediately*.
  It deletes the target. The schedule owner needs delete access to it.
- **Cancel user account** (`action_scheduler_user_cancel`). Its settings are
  the cancellation method (the same as on the user cancel form: disable the
  account and keep or unpublish its content, delete the account and reassign
  its content to the anonymous user, or delete the account and its content)
  and whether to notify the user by email. As with the user cancel form, the
  email is only sent if the matching notification (*Account blocked* or
  *Account canceled*) is enabled in the account settings, and it is checked
  by default when that notification is enabled. The account is cancelled like from
  the user cancel form, so other modules can act on its content. The schedule
  owner needs the *Administer users* permission, and user 1 cannot be
  cancelled, as with the core bulk cancel form.

## When a target entity is deleted

Schedules are not deleted with their target entity. When you delete an entity
that has pending schedules, a warning tells you how many there are and links
to the schedule list: delete them manually, otherwise they are marked as
*Failed* when they run. In the list, their target shows as *Deleted (entity type
ID)*. When the entity is deleted by a schedule, that schedule is not counted.

## Known limitations

- Actions that run on scheduled actions themselves (e.g. *Save scheduled
  action*) cannot be scheduled: they are not listed in the form and are
  rejected when a schedule is saved from code.
- Some core actions do not do the work themselves: they prepare a
  confirmation form for the user who triggers them. *Delete* actions
  (`entity:delete_action:*`) and *Cancel the selected user accounts*
  (`user_cancel_user_action`) are two examples. Run from a schedule, they do
  nothing. Avoid scheduling them: the schedule form shows a warning when one
  of them is selected (any plugin that declares a `confirm_form_route_name`).
  Use the actions provided by this module instead.

## Development

The module is developed with [DDEV](https://ddev.com) and the
[ddev-drupal-contrib](https://github.com/ddev/ddev-drupal-contrib) add-on,
which symlinks the module into `web/modules/custom/action_scheduler`.

- The symlinks are created when the project starts. After adding a file to the
  module root, run `ddev symlink-project` (or `ddev restart`) and then
  `ddev drush cr`.
- Code checks: `ddev phpcs` and `ddev phpstan`.
- Tests: `ddev phpunit`. FunctionalJavascript tests use the
  [ddev-selenium-standalone-chrome](https://github.com/ddev/ddev-selenium-standalone-chrome)
  add-on.
