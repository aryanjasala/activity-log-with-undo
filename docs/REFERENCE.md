# Reference

## What is logged, and what Undo does

| Recorder | Events | Undo |
| --- | --- | --- |
| Settings and widgets (`options`) | `option_added`, `option_updated`, `option_deleted`, and on multisite `network_option_*`, for settings only (see below) | Writes the old value back, removes an added option, adds a deleted one back. A deleted network option is logged only: WordPress has no hook to read it first |
| Plugins (`plugins`) | `plugin_activated`, `plugin_deactivated`, `plugin_installed`, `plugin_updated`, `plugin_deleted` | Switches the plugin back. Installs, updates and deletions are logged only, because WordPress keeps no copy of the old files |
| Themes (`themes`) | `theme_switched`, `theme_installed`, `theme_updated`, `theme_deleted` | Switches back while the old theme is still installed. The rest is logged only |
| Users and roles (`users`) | `user_role_changed`, `user_updated` (display name, email, website), `user_created`, `user_deleted` | Restores the old set of roles or the old fields. A new user is deleted, with their content given to whoever added them (or to you). Deleted users are logged only |
| Content (`posts`) | `post_created`, `post_status_changed`, `post_trashed`, `post_untrashed`, `post_updated` (title, content, excerpt), `post_attributes_changed` (slug, parent, author), `post_featured_image_changed`, `post_template_changed`, `post_deleted` | Puts the status back; a trashed post returns to its old status. Content edits come back through the matching revision, or the stored fields when there is none. Slug, parent, author, featured image and template are reset. Permanent deletions are logged only |
| Terms (`terms`) | `term_created`, `term_updated`, `term_deleted` | Deletes a new term, restores name, slug, description and parent, and rebuilds a deleted term with its meta, posts and child terms. With Category & Tag Trash active, a deleted term is restored from its trash instead |
| Menus (`menus`) | `menu_created`, `menu_updated`, `menu_deleted` | Puts the whole menu back as it was before the save: removed items come back (with new IDs, children still under them), added items go, the rest is reset |
| Site Editor (`site_editor`) | `site_editor_created`, `site_editor_updated`, `site_editor_deleted` for global styles, templates, template parts and navigation | Restores the previous revision (or the stored content), deletes a new customization so the theme's own version shows again, and brings back one that was cleared |
| Network sites (`network`, multisite only) | `site_created`, `site_deleted` | Logged only, on the main site |

## Which options are logged

Other plugins keep working state in options and rewrite it all the time: caches, indexes, counters, flags. Logging those filled the table with entries nobody can act on, and an undo could write an old copy back. So the options recorder logs settings, the options a person changes on purpose:

- what the core settings screens save (the `options.php` allowlist: General, Writing, Reading, Discussion, Media), plus permalinks, the privacy policy page, the site logo and sticky posts;
- widgets (`widget_*`, `sidebars_widgets`), Customizer settings (`theme_mods_*`) and menu settings;
- every option registered with `register_setting()`, named by its `label` when it has one;
- anything you add to `activity_log_with_undo_logged_options`.

Settings are logged only when a person started the request: wp-admin, the REST API, admin-ajax or XML-RPC with a signed-in user, or WP-CLI. A cron job or a visitor's page view that changes a setting is not logged. Options on the `activity_log_with_undo_logged_options` list are logged in every request.

Three limits keep the table small, each with a filter:

- **Rate limit**: one option gets at most 20 entries a minute. Past that, changes are not logged until the minute is over. Writing one option several times in one request is one entry, with the first old value and the last new one.
- **Option size guard**: a value over 64 KB is not a setting a person typed. The change is logged without its values and cannot be undone.
- **Log size limit**: past 512 MB, new changes are logged without their before and after states and Site Health reports it as critical.

Always left out, whatever the lists say: transients, cron, rewrite rules, locks, caches, salts and keys, database versions, options another recorder covers (active plugins, the theme), and the plugin's own options. WordPress adding an empty option the first time a screen is saved (such as `WPLANG`) is not a change either.

### Upgrading from 1.0.0

Version 1.0.0 logged every option. The first time an administrator opens wp-admin after the update, a notice counts the entries of options that are not settings (for example `block_doctor_index`) and offers to delete them. Deleting runs in batches of 500, the rest through WP-Cron. **Keep them** hides the notice. `wp activity-log-with-undo cleanup` does the same, with `--dry-run` to see the list first. Entries of internal options already in the log are never undone together with a person's change.

## Rolling back to a time

Tools > Activity Log > **Roll back** puts the site back to an earlier moment. Pick 1 hour ago, this morning, the start of yesterday, a time of your own, or a log entry ("just before this change"), and narrow it to one user or one area if you like.

The **preview** lists every change since then, newest first, grouped by area. Each is marked:

- **Will be undone**;
- **Cannot be undone**, with the reason (installs, updates, permanent deletions, entries over the size limit, things you may not edit);
- **Clash**: something that is not in the list changed the same thing again, so putting the old value back would overwrite it. A change that a later change in the list covers is not a clash.

The run undoes change sets newest first, a few per request (the progress bar shows how far it got), and skips clashes and reports them, unless you tick "Stop at the first change that clashes". Changes made by the roll back share one change set with one **Roll back** entry, so Redo on that entry puts everything back. A run handles up to 400 changes; the preview says when some are left for the next run. A roll back nobody finishes can be continued or cancelled on the same tab, and Site Health flags one that has been idle for an hour.

## Alerts and the daily digest

**Tools > Activity Log > Alerts** sends a message when a kind of change is logged. A rule has:

- **Events and areas**: tick the events, or none for any change. Areas are the recorders (settings, plugins, themes, users, content, terms, menus, Site Editor).
- **Objects**: optional setting names, plugin files or IDs, such as `siteurl, home`.
- **Made by**: optional user names and roles. Either one fires the rule.
- **Also needs**: a condition (`becomes_administrator`, `turned_on`, or one you add) and "only outside working hours". Working days and hours are set on the same tab, in the site time zone.
- **Delivery**: email, a webhook, or both. A webhook gets JSON or the Slack format. Send right away, or as one digest a day at the start of working hours.

Six starter rules are offered and added turned off: a new administrator, a plugin deactivated or deleted, the theme switched, the site address changed, anyone can register turned on, and the default role changed.

**One message for a bulk change.** Matching runs at the end of the request, so forty entries from one save are one message. After an instant message, the rule waits the throttle time (10 minutes by default) and sends what happened in between as one combined message. A daily rule queues and sends its digest once a day. **Send a test** on each rule sends a sample through every channel and says whether each worked. A test does not start a throttle window.

**What a message says.** Who, what, the before and after value, and a link to the entry in the log. There is no undo link: undoing happens on the site after you sign in. Lists of words are listed, bigger structures are counted ("3 fields"), and the values of anything named like a password, key, token or salt are left out.

**Only what the reader may edit.** Each email address gets its own message. An address that belongs to a user who may open the log reads about the objects that user may edit. Anything else shows as "A change to something you are not allowed to see", with only who and when. An address that belongs to no user, and a webhook whose rule has no known owner, only ever get that. A webhook reads as the person who made the rule.

**Webhook URLs are secrets.** They are kept in their own option, never autoloaded, and are never printed back, returned by the abilities or put in an error message. The rule form shows the host only. The default allows `https` URLs and uses the safe HTTP API, which refuses private addresses.

## Change sets and conflicts

Every entry written in one request shares a change set. Undo on any entry undoes the whole set, newest first, so one save that changed five options comes back as one. A set holds at most 100 entries (filterable), so a long WP-CLI import does not become one giant undo.

Only the changes the person made are undone together: entries that cannot be undone, were undone already, or belong to options that are not logged any more stay out. Before it runs, the confirm on the log screen names the other changes it puts back, and `wp activity-log-with-undo undo` lists them and asks, unless `--yes`.

Before anything runs, each entry in the set is compared with how things are now. If something changed since, the undo stops (HTTP 409 over REST and abilities) and the screen shows the entry's after state next to the current one. **Undo anyway** (`--force`, `force: true`) runs it regardless.

The undo is logged like any other change, with `undo_of` pointing at the entry it reversed, and the original gets `undone_by`. Undoing the undo is a redo.

## Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `activity_log_with_undo_capability` | filter | `( string $capability )`. Who can see the log and undo. Default `manage_options` |
| `activity_log_with_undo_recorders` | filter | `( Recorder[] $recorders )`. Add your own recorder (anything implementing `AryanX\Axle\ActivityLogWithUndo\Recorders\Recorder`) or remove one. Keyed by recorder ID |
| `activity_log_with_undo_ignored_options` | filter | `( string[] $patterns )`. Options never logged. `*` matches anything. The plugin's own options stay ignored whatever this returns |
| `activity_log_with_undo_logged_options` | filter | `( string[] $patterns )`. Options always logged, in every request, on top of the settings. Default none. `*` matches anything |
| `activity_log_with_undo_setting_options` | filter | `( string[] $patterns, bool $network )`. Options that count as settings, logged when a person changes them. Default: the core settings screens, widgets, theme mods, menu settings. Options registered with `register_setting()` count without being listed |
| `activity_log_with_undo_is_user_request` | filter | `( bool $user, string $via )`. Whether a person started the request. Default: WP-CLI, or a signed-in user in wp-admin, the REST API, admin-ajax or XML-RPC. Settings changed in other requests are not logged |
| `activity_log_with_undo_option_rate_limit` | filter | `( int $limit, string $option )`. Most entries one option gets within the window. Default 20. 0 turns it off |
| `activity_log_with_undo_option_rate_window` | filter | `( int $seconds, string $option )`. The rate limit window. Default 60. At least 1 |
| `activity_log_with_undo_option_size_limit` | filter | `( int $bytes, string $option )`. Largest option value kept. Default 65536 (64 KB). Bigger changes are logged without their values. 0 leaves only the payload limit |
| `activity_log_with_undo_max_log_size` | filter | `( int $bytes )`. How big the log table may grow before changes are logged without their states. Default 536870912 (512 MB). 0 turns it off |
| `activity_log_with_undo_post_types` | filter | `( string[] $types )`. Post types logged. Default every type with an admin screen. Menu items and Site Editor types are always handled by their own recorders |
| `activity_log_with_undo_taxonomies` | filter | `( string[] $taxonomies )`. Taxonomies logged. Default every taxonomy with an admin screen, except menus |
| `activity_log_with_undo_should_log` | filter | `( bool $log, string $event, array $data )`. Skip one change. `$data` holds the recorder, object and both states |
| `activity_log_with_undo_retention_days` | filter | `( int $days )`. How long entries are kept. Default 90 or the setting. At least 1 |
| `activity_log_with_undo_payload_limit` | filter | `( int $bytes )`. Largest before or after state stored. Default 262144 (256 KB) or the setting. Bigger changes are logged without it and cannot be undone |
| `activity_log_with_undo_mask_ip` | filter | `( string $stored, string $ip, string $mode )`. The IP address stored. Anything that is not an IP address is dropped |
| `activity_log_with_undo_can_undo` | filter | `( true\|WP_Error $can, Entry $entry )`. Refuse an undo with your own reason. An entry without its before state can never be undone |
| `activity_log_with_undo_conflict_message` | filter | `( string $message, Entry $entry )`. What people and agents read when things changed since |
| `activity_log_with_undo_change_set_entries` | filter | `( Entry[] $members, Entry $entry )`. The entries undone together with `$entry`. Remove entries to leave them alone. Only entries of the same change set count, and `$entry` is always undone |
| `activity_log_with_undo_confirm_message` | filter | `( string $message, Entry $entry, string[] $also, bool $force )`. The question asked before an undo on the log screen. `$also` describes the other changes it undoes |
| `activity_log_with_undo_object_type_label` | filter | `( string $label, string $type )`. The name of a kind of object on the entry screen ("Page", "Setting") |
| `activity_log_with_undo_field_labels` | filter | `( array $labels )`. Names for stored fields in the before and after table (`post_title` => "Title"). Fields without one show their key |
| `activity_log_with_undo_agent_context` | filter | `( array $agent, array $request, int $approver_id )`. What an entry records about an AI agent whose request Agent Governance approved: `agent` (name), `request` (ID), `approved_by` (user ID). Return `[]` to record nothing |
| `activity_log_with_undo_cleanup_options` | filter | `( array $found )`. Options whose old entries the upgrade clean-up offers to delete, name => entries. Remove one to keep its entries |
| `activity_log_with_undo_cleanup_batch_size` | filter | `( int $size )`. Entries the clean-up deletes per query. Default 500 |
| `activity_log_with_undo_cleanup_message` | filter | `( string $message, array $state )`. The notice that offers the clean-up |
| `activity_log_with_undo_entry_description` | filter | `( string $text, Entry $entry )`. The one-line description in the log, WP-CLI and abilities |
| `activity_log_with_undo_list_per_page` | filter | `( int $per_page )`. Entries per page on Tools > Activity Log. Default 20 |
| `activity_log_with_undo_change_set_size` | filter | `( int $size )`. Most entries in one change set. Default 100 |
| `activity_log_with_undo_term_object_limit` | filter | `( int $limit )`. Most posts a deleted term may have and still be rebuilt. Default 5000 |
| `activity_log_with_undo_revision_search_limit` | filter | `( int $limit )`. Revisions searched for the one to restore. Default 50 |
| `activity_log_with_undo_purge_batch_size` | filter | `( int $size )`. Entries deleted per query by the clean-up. Default 1000 |
| `activity_log_with_undo_default_settings` | filter | `( array $defaults )`. Settings used until someone saves the settings screen: `disabled`, `retention_days`, `ip`, `payload_kb`, `admin_bar` |
| `activity_log_with_undo_can_view_entry` | filter | `( bool $allowed, Entry $entry, int $user_id )`. Whether the current user may see an entry and undo it. Default: they may edit the object it is about (`edit_post` for that post, `edit_term`, `edit_user`, `manage_options` for a setting). Hidden entries show without name or values |
| `activity_log_with_undo_rollback_capability` | filter | `( string $capability )`. Who can roll the site back. Default: the log capability |
| `activity_log_with_undo_rollback_presets` | filter | `( array $presets )`. The moments the Roll back tab offers: key => `array( 'label' => string, 'time' => Unix time in the past )`. Default 1 hour ago, this morning (6 am), start of yesterday |
| `activity_log_with_undo_rollback_batch_size` | filter | `( int $size )`. Change sets undone per request. Default 10, between 1 and 100 |
| `activity_log_with_undo_rollback_max_entries` | filter | `( int $limit )`. Changes one roll back undoes; the rest wait for the next run. Default 400, at most 500 |
| `activity_log_with_undo_rollback_scan_limit` | filter | `( int $limit )`. Log entries a roll back reads. Default 2000 |
| `activity_log_with_undo_rollback_time_budget` | filter | `( int $seconds )`. How long the `run-rollback` ability works before it hands back a running job. Default 20, between 1 and 120 |
| `activity_log_with_undo_rollback_message` | filter | `( string $message, array $result )`. The notice shown when a roll back ends |
| `activity_log_with_undo_before_rollback` | action | `( array $job, array $plan )`. A roll back is about to start undoing changes |
| `activity_log_with_undo_after_rollback` | action | `( array $result, array $job )`. A roll back ended: `state` is `done`, `stopped`, `cancelled` or `abandoned`; `undone`, `skipped` and `summary` (the entry that logs it) say what happened |
| `activity_log_with_undo_logged` | action | `( Entry $entry )`. A change was logged. For an outside log or an alert channel |
| `activity_log_with_undo_before_undo` | action | `( Entry $entry, bool $force )`. An entry is about to be undone |
| `activity_log_with_undo_undone` | action | `( Entry $entry, int[] $entries )`. An entry was undone; `$entries` logged the reversal |
| `activity_log_with_undo_undo_failed` | action | `( Entry $entry, WP_Error $error )`. Undoing an entry failed |
| `activity_log_with_undo_purged` | action | `( int $deleted, string $cutoff )`. Old entries were deleted. `$cutoff` is UTC |
| `activity_log_with_undo_option_skipped` | action | `( string $option, string $reason )`. A setting change was not logged: `background` (no person started the request) or `rate_limited` |
| `activity_log_with_undo_cleaned_up` | action | `( int $deleted, string[] $options )`. The upgrade clean-up deleted the last of the entries it found |
| `activity_log_with_undo_settings_saved` | action | `( array $settings, array $old )`. The settings changed |
| `activity_log_with_undo_alerts_capability` | filter | `( string $capability )`. Who may manage alert rules and send test alerts. Default: the log capability |
| `activity_log_with_undo_alert_starter_rules` | filter | `( array $starters )`. The starter rules offered on the Alerts tab, rule ID => fields (`name`, `events`, `areas`, `objects`, `condition`, `outside_hours`). They are added turned off |
| `activity_log_with_undo_alert_conditions` | filter | `( array $conditions )`. Conditions a rule can add, ID => label. Test your own in `activity_log_with_undo_alert_condition_met` |
| `activity_log_with_undo_alert_condition_met` | filter | `( bool $met, string $condition, Entry $entry )`. Whether a named condition holds for an entry |
| `activity_log_with_undo_alert_matches` | filter | `( bool $match, array $rule, Entry $entry )`. Final say on whether a rule fires for an entry |
| `activity_log_with_undo_alert_outside_hours` | filter | `( bool $outside, int $timestamp )`. Whether a moment counts as outside working hours |
| `activity_log_with_undo_alert_throttle` | filter | `( int $seconds )`. How long a rule waits between instant messages. Default 600 (the saved minutes). Changes in the window are held and sent together. 0 turns it off |
| `activity_log_with_undo_alert_max_rules` | filter | `( int $max )`. Most alert rules a site may have. Default 50 |
| `activity_log_with_undo_alert_max_entries` | filter | `( int $max )`. Entries one message lists; the rest is counted. Default 20 |
| `activity_log_with_undo_alert_queue_limit` | filter | `( int $limit )`. Entries a rule queues for its next message. Default 500 |
| `activity_log_with_undo_alert_value_length` | filter | `( int $length )`. Characters of a before or after value an alert shows. Default 120 |
| `activity_log_with_undo_alert_recipients` | filter | `( string[] $emails, array $rule )`. Addresses of a rule. Each address gets its own message, cut down to what that person may see |
| `activity_log_with_undo_alert_message` | filter | `( array $message, array $rule, int $viewer )`. The message before it is sent: `subject`, `title`, `count`, `items`, `more`. Keep it free of what the reader may not see |
| `activity_log_with_undo_alert_webhook_payload` | filter | `( array $payload, array $rule, array $message, string $format )`. The data a webhook receives, `json` or `slack` |
| `activity_log_with_undo_alert_webhook_args` | filter | `( array $args, array $payload )`. Arguments of the webhook request (`wp_safe_remote_post`) |
| `activity_log_with_undo_alert_webhook_schemes` | filter | `( string[] $schemes )`. URL schemes a webhook may use. Default `https` only |
| `activity_log_with_undo_alert_digest_time` | filter | `( int $timestamp )`. When the first daily digest goes out. Default: tomorrow at the start of working hours |
| `activity_log_with_undo_alert_rule_saved` | action | `( array $rule, bool $new )`. A rule was saved. The rule holds no webhook URL |
| `activity_log_with_undo_alert_rule_deleted` | action | `( array $rule )`. A rule was deleted |
| `activity_log_with_undo_alert_sent` | action | `( array $rule, string $channel, Entry[] $entries, string $kind )`. A message went out. `$channel` is `email` or `webhook`, `$kind` is `instant`, `held`, `digest` or `test` |
| `activity_log_with_undo_alert_failed` | action | `( array $rule, string $channel, string $error, Entry[] $entries )`. A message could not be sent. The error never holds the webhook URL |
| `activity_log_with_undo_alert_throttled` | action | `( array $rule, Entry[] $entries )`. A rule held changes back because it sent a message a moment ago |
| `activity_log_with_undo_alert_digest_sent` | action | `( int $sent )`. The daily digests went out |
| `activity_log_with_undo_alert_test_sent` | action | `( array $rule, array $results )`. A test alert was sent; one result per channel |

```php
// Editors may see the log and undo too.
add_filter( 'activity_log_with_undo_capability', fn() => 'edit_others_posts' );

// Log my plugin's option too, although it is not registered with the Settings API.
add_filter( 'activity_log_with_undo_logged_options', function ( $patterns ) {
	$patterns[] = 'my_plugin_license_*';
	return $patterns;
} );

// Nobody undoes changes to the live payment settings from the log.
add_filter( 'activity_log_with_undo_can_undo', function ( $can, $entry ) {
	if ( 'option' === $entry->object_type && 0 === strpos( $entry->object_id, 'woocommerce_stripe' ) ) {
		return new WP_Error( 'payments', 'Change payment settings on the payments screen.' );
	}
	return $can;
}, 10, 2 );

// Send every plugin change to the team channel.
add_action( 'activity_log_with_undo_logged', function ( $entry ) {
	if ( 'plugin' === $entry->object_type ) {
		my_team_alert( sprintf( '%s by %s', $entry->event, $entry->actor_name() ) );
	}
} );
```

### JavaScript

The log screen runs `wp.hooks`, read when needed, so scripts that load later can still hook in.

| Hook | Type | Use |
| --- | --- | --- |
| `activityLogWithUndo.confirmMessage` | filter | `( message, context )`. What the in-place confirm step says will change before an undo. Return `''` to undo without asking. `context`: `entryId`, `force`, `redo`, `count`, `deletesUser`, `also` (descriptions of the other changes it undoes) |
| `activityLogWithUndo.undone` | action | `( result, context )`. An undo finished, before the log reloads. `result`: `undone`, `failed`, `entries`, `forced`, `redirect` |
| `activityLogWithUndo.rollbackProgress` | action | `( state )`. After each batch of a roll back: `state`, `done`, `total`, `undone`, `skipped`, `summary`, `deferred` |
| `activityLogWithUndo.rollbackDone` | action | `( state )`. A roll back ended, before the log reloads |
| `activityLogWithUndo.undoFailed` | action | `( error, context )`. An undo was refused or failed (another plugin blocks writes, the change cannot be undone). The message shows in a notice on the same page, which stays as it is. `error`: `code`, `message`, `data` |

```js
wp.hooks.addFilter( 'activityLogWithUndo.confirmMessage', 'my-plugin', ( message, context ) =>
	context.count > 1 ? message : ''
);
```

## Abilities

On WordPress 6.9 and later, Activity Log with Undo registers [abilities](https://developer.wordpress.org/apis/abilities/), so AI agents can use them through the [MCP adapter](https://github.com/WordPress/mcp-adapter) and anything else through the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability. All of them need the log capability (the alert abilities need the alerts capability, which defaults to it), and each change is checked against the object it is about: an entry you may not edit comes back with `masked: true` and no object name, values or IP address, and cannot be undone. `run-rollback` also needs the roll back capability.

| Ability | Does |
| --- | --- |
| `activity-log-with-undo/list-activity` | Read-only. Changes, newest first. Input: `event`, `object_type`, `object`, `user`, `via`, `since`, `until`, `search`, `undoable_only`, `limit`, `page` |
| `activity-log-with-undo/get-entry` | Read-only. One change with `before`, `after`, `can_undo`, `reason`, `changed_since`, `conflicts` and `change_count`. Input: `id` |
| `activity-log-with-undo/preview-rollback` | Read-only. Every change since a moment, newest first, each marked `will`, `blocked` (with the reason) or `conflict`, with counts. Input: `to` (a time in the site time zone, a preset `hour`, `morning`, `yesterday`, or a log entry number for "just before it"), `user`, `area` |
| `activity-log-with-undo/run-rollback` | Destructive. Undoes everything since that moment, newest first, and logs it as one entry that can be redone. Clashes are skipped and reported unless `stop_on_conflict` is true. After `activity_log_with_undo_rollback_time_budget` seconds it answers `state: running` with a `job`; call again with `job` to continue |
| `activity-log-with-undo/undo-change` | Destructive. Undoes `id` with the rest of its change set. Refuses with a 409 when things changed since, unless `force` is true |
| `activity-log-with-undo/list-alert-rules` | Read-only. The alert rules: events, areas, objects, mode, whether email is on and whether a webhook is set. Webhook URLs and email addresses are never returned |
| `activity-log-with-undo/set-alert-enabled` | Idempotent. Turns one rule on or off. Input: `id`, `enabled` |
| `activity-log-with-undo/send-test-alert` | Sends a sample message through every channel of one rule and returns one result per channel. Input: `id` |

Changes made while an ability runs are logged with the source `ability`.

With [Agent Governance](https://github.com/aryanjasala/agent-governance) active, a held agent call that someone approves runs right after the approval. Every change it makes records the agent's name, the request number and who approved it, from the `agent_governance_request_approved` and `agent_governance_request_executed` actions. The log shows "Claude, request #12, approved by maria" under the user, the entry screen has an AI agent row, and WP-CLI and the abilities return it as `agent`.

## WP-CLI

```sh
wp activity-log-with-undo list [--event=<event>] [--user=<id|login|email>] [--via=<via>] [--object-type=<type>] [--object=<id|name>] [--since=<date>] [--until=<date>] [--search=<text>] [--undoable] [--per-page=<n>] [--page=<n>] [--fields=<fields>] [--format=table|csv|json|yaml|count|ids]
wp activity-log-with-undo show <id> [--format=table|json|yaml]
wp activity-log-with-undo undo <id>... [--force] [--yes]
wp activity-log-with-undo rollback --to=<time|id> [--user=<id|login|email>] [--area=<area>] [--stop-on-conflict] [--dry-run] [--yes] [--format=table|csv|json|yaml]
wp activity-log-with-undo purge [--older-than=<days>]
wp activity-log-with-undo cleanup [--dry-run] [--yes] [--format=table|csv|json|yaml]
wp activity-log-with-undo settings [--retention-days=<n>] [--ip=full|masked|off] [--payload-kb=<n>] [--admin-bar=on|off] [--enable=<recorders>] [--disable=<recorders>] [--format=table|json|yaml]
wp activity-log-with-undo alerts list [--format=table|csv|json|yaml|count|ids]
wp activity-log-with-undo alerts add <name> [--starter=<id>] [--events=<events>] [--areas=<areas>] [--objects=<objects>] [--users=<ids>] [--roles=<roles>] [--outside-hours] [--emails=<emails>] [--skip-email] [--webhook=<url>] [--format=json|slack] [--mode=instant|daily] [--enable] [--porcelain]
wp activity-log-with-undo alerts enable <id>
wp activity-log-with-undo alerts disable <id>
wp activity-log-with-undo alerts delete <id> [--yes]
wp activity-log-with-undo alerts test <id>
wp activity-log-with-undo alerts digest
```

Dates take `2026-10-01`, an ISO 8601 time or `"2 days ago"`. `purge --older-than=0` empties the log. `rollback --to` takes a time (`"2026-11-13 14:00"`, `"2 hours ago"`) or a log entry number, meaning just before that change. It prints what it would undo, asks, undoes in batches, and logs one entry; `undo` that entry to redo everything. `--area` is one of `options`, `plugins`, `themes`, `users`, `posts`, `terms`, `menus`, `site_editor`. `undo` lists the other changes saved together and asks before undoing them all, and undoing a new user asks first too, unless `--yes`. Run it with `--user=<login>` so their content has someone to go to. `alerts add` is off unless you pass `--enable`; `--starter` copies one of the starter rules (`new-administrator`, `plugin-removed`, `theme-switched`, `site-address`, `registration-on`, `default-role`). A webhook URL is read when you add the rule and is never printed again. `alerts digest` sends the daily digests now. `cleanup` deletes old entries of options that are not settings (see Upgrading from 1.0.0). Settings registered only in wp-admin are unknown to WP-CLI, so check its list before you confirm.

## PHP API

```php
$plugin = AryanX\Axle\ActivityLogWithUndo\Plugin::instance();

$found  = $plugin->store()->find( array( 'event' => 'option_updated', 'per_page' => 10 ) );
$result = $plugin->undoer()->undo( $found['entries'][0]->id );          // array, or WP_Error
$force  = $plugin->undoer()->undo( 42, true );                          // past a conflict
$plan   = $plugin->rollback()->plan( array( 'to' => 'yesterday' ) );    // what a roll back would do
$state  = $plugin->rollback()->run( $plan );                            // undoes it, newest first
```

A custom recorder implements `Recorder` (`id`, `label`, `events`, `register`, `can_undo`, `current_state`, `undo`, `describe`, `flush`), logs through `$plugin->logger()->log( array( 'event' => ..., 'recorder' => ..., 'object_type' => ..., 'object_id' => ..., 'before' => ..., 'after' => ..., 'undoable' => true ) )`, and joins through the `activity_log_with_undo_recorders` filter.

## Data

| Where | Contents |
| --- | --- |
| `{prefix}activity_log_with_undo` table | One row per change: time (UTC), change set, event, recorder, object type, ID and name, user ID, source, IP address, the before and after state as JSON, whether it can be undone, `undone_by` and `undo_of`, and for an approved AI agent call `agent`, `agent_request` and `approved_by`. `before` is a reserved word in MySQL, so the states live in `before_state` and `after_state` |
| `activity_log_with_undo_settings` | Recorders turned off, retention days, IP mode, payload limit, admin bar, working days and hours, wait between alert messages |
| `activity_log_with_undo_db_version` | Table version, for upgrades. Version 2 added the agent columns |
| `activity_log_with_undo_cleanup` | State of the upgrade clean-up: status, the options found, entries found and deleted |
| `activity_log_with_undo_cleanup` (job) | WP-Cron job that keeps deleting those entries in batches until done |
| `_transient_activity_log_with_undo_*` | The per-option rate-limit counters, the cached table size, and one-time notices |
| `activity_log_with_undo_purge` | Daily WP-Cron job that deletes old entries |
| `activity_log_with_undo_rollback_job`, `activity_log_with_undo_rollback_lock` | The roll back in progress (not autoloaded) and its step lock. Both go when it ends; a job nobody touches for an hour is closed by the daily job |
| `activity_log_with_undo_steps_dismissed` (user meta) | Set when someone hides the "Get started" card |
| `activity_log_with_undo_alert_rules` | The alert rules. No webhook URLs |
| `activity_log_with_undo_alert_secrets` | Webhook URLs by rule ID. Never autoloaded, never shown |
| `activity_log_with_undo_alert_state` | Last send and last error per rule, and the held and daily queues (entry IDs). Not autoloaded |
| `activity_log_with_undo_alert_digest`, `activity_log_with_undo_alert_held` | WP-Cron jobs: the daily digest, and the single event that sends held changes |

States that survive a JSON round trip are stored as `{"v": ...}`. Anything else (objects in an option, for example) is stored serialized as `{"s": "..."}`, the way WordPress stores options. A state over the size limit is stored as `{"over_limit": <bytes>}`, or `{"over_limit": <bytes>, "reason": "log_full"}` when the log was over its size limit, and the entry cannot be undone.

Uninstalling drops the table, deletes the options and the job, on every site of a network.

## Privacy

Entries hold the user ID, the source and the IP address of each change: shortened by default (`192.168.1.0`), full or none from the settings, and for an approved agent call the user ID of the approver. They take part in **Tools > Export Personal Data** and **Tools > Erase Personal Data**. Erasing keeps the change history and removes the person from it: the user ID becomes "(removed)", also where they approved an agent call, the IP address goes, and entries about their own account lose the stored profile values. Alert rules keep the email addresses that get alerts, and the user who made the rule. Both are in the personal data export, and erasing removes the address and the owner from the rules and keeps the rules. **Settings > Privacy** gets suggested text for your privacy policy.

## Multisite

Each site has its own table, created the first time the plugin loads there. Network options and sites added or deleted are logged on the main site.
