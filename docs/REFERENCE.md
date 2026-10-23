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

## Change sets and conflicts

Every entry written in one request shares a change set. Undo on any entry undoes the whole set, newest first, so one save that changed five options comes back as one. A set holds at most 100 entries (filterable), so a long WP-CLI import does not become one giant undo.

Only the changes the person made are undone together: entries that cannot be undone, were undone already, or belong to options that are not logged any more stay out. Before it runs, the confirm on the log screen names the other changes it puts back, and `wp activity-log-with-undo undo` lists them and asks, unless `--yes`.

Before anything runs, each entry in the set is compared with how things are now. If something changed since, the undo stops (HTTP 409 over REST and abilities) and the screen shows the entry's after state next to the current one. **Undo anyway** (`--force`, `force: true`) runs it regardless.

The undo is logged like any other change, with `undo_of` pointing at the entry it reversed, and the original gets `undone_by`. Undoing the undo is a redo.

## Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `activity_log_with_undo_capability` | filter | `( string $capability )`. Who can see the log and undo. Default `manage_options` |
| `activity_log_with_undo_recorders` | filter | `( Recorder[] $recorders )`. Add your own recorder (anything implementing `AryanX\ActivityLogWithUndo\Recorders\Recorder`) or remove one. Keyed by recorder ID |
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
| `activity_log_with_undo_logged` | action | `( Entry $entry )`. A change was logged. For an outside log or an alert channel |
| `activity_log_with_undo_before_undo` | action | `( Entry $entry, bool $force )`. An entry is about to be undone |
| `activity_log_with_undo_undone` | action | `( Entry $entry, int[] $entries )`. An entry was undone; `$entries` logged the reversal |
| `activity_log_with_undo_undo_failed` | action | `( Entry $entry, WP_Error $error )`. Undoing an entry failed |
| `activity_log_with_undo_purged` | action | `( int $deleted, string $cutoff )`. Old entries were deleted. `$cutoff` is UTC |
| `activity_log_with_undo_option_skipped` | action | `( string $option, string $reason )`. A setting change was not logged: `background` (no person started the request) or `rate_limited` |
| `activity_log_with_undo_cleaned_up` | action | `( int $deleted, string[] $options )`. The upgrade clean-up deleted the last of the entries it found |
| `activity_log_with_undo_settings_saved` | action | `( array $settings, array $old )`. The settings changed |

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
| `activityLogWithUndo.confirmMessage` | filter | `( message, context )`. The question asked before an undo. Return `''` to undo without asking. `context`: `entryId`, `force`, `redo`, `count`, `deletesUser`, `also` (descriptions of the other changes it undoes) |
| `activityLogWithUndo.undone` | action | `( result, context )`. An undo finished, before the log reloads. `result`: `undone`, `failed`, `entries`, `forced`, `redirect` |
| `activityLogWithUndo.undoFailed` | action | `( error, context )`. An undo was refused or failed (another plugin blocks writes, the change cannot be undone). The message shows in a notice on the same page, which stays as it is. `error`: `code`, `message`, `data` |

```js
wp.hooks.addFilter( 'activityLogWithUndo.confirmMessage', 'my-plugin', ( message, context ) =>
	context.count > 1 ? message : ''
);
```

## Abilities

On WordPress 6.9 and later, Activity Log with Undo registers [abilities](https://developer.wordpress.org/apis/abilities/), so AI agents can use them through the [MCP adapter](https://github.com/WordPress/mcp-adapter) and anything else through the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability. All of them need the log capability.

| Ability | Does |
| --- | --- |
| `activity-log-with-undo/list-activity` | Read-only. Changes, newest first. Input: `event`, `object_type`, `object`, `user`, `via`, `since`, `until`, `search`, `undoable_only`, `limit`, `page` |
| `activity-log-with-undo/get-entry` | Read-only. One change with `before`, `after`, `can_undo`, `reason`, `changed_since`, `conflicts` and `change_count`. Input: `id` |
| `activity-log-with-undo/undo-change` | Destructive. Undoes `id` with the rest of its change set. Refuses with a 409 when things changed since, unless `force` is true |

Changes made while an ability runs are logged with the source `ability`.

With [Agent Governance](https://github.com/aryanjasala/agent-governance) active, a held agent call that someone approves runs right after the approval. Every change it makes records the agent's name, the request number and who approved it, from the `agent_governance_request_approved` and `agent_governance_request_executed` actions. The log shows "Claude, request #12, approved by maria" under the user, the entry screen has an AI agent row, and WP-CLI and the abilities return it as `agent`.

## WP-CLI

```sh
wp activity-log-with-undo list [--event=<event>] [--user=<id|login|email>] [--via=<via>] [--object-type=<type>] [--object=<id|name>] [--since=<date>] [--until=<date>] [--search=<text>] [--undoable] [--per-page=<n>] [--page=<n>] [--fields=<fields>] [--format=table|csv|json|yaml|count|ids]
wp activity-log-with-undo show <id> [--format=table|json|yaml]
wp activity-log-with-undo undo <id>... [--force] [--yes]
wp activity-log-with-undo purge [--older-than=<days>]
wp activity-log-with-undo cleanup [--dry-run] [--yes] [--format=table|csv|json|yaml]
wp activity-log-with-undo settings [--retention-days=<n>] [--ip=full|masked|off] [--payload-kb=<n>] [--admin-bar=on|off] [--enable=<recorders>] [--disable=<recorders>] [--format=table|json|yaml]
```

Dates take `2026-10-01`, an ISO 8601 time or `"2 days ago"`. `purge --older-than=0` empties the log. `undo` lists the other changes saved together and asks before undoing them all, and undoing a new user asks first too, unless `--yes`. Run it with `--user=<login>` so their content has someone to go to. `cleanup` deletes old entries of options that are not settings (see Upgrading from 1.0.0). Settings registered only in wp-admin are unknown to WP-CLI, so check its list before you confirm.

## PHP API

```php
$plugin = AryanX\ActivityLogWithUndo\Plugin::instance();

$found  = $plugin->store()->find( array( 'event' => 'option_updated', 'per_page' => 10 ) );
$result = $plugin->undoer()->undo( $found['entries'][0]->id );          // array, or WP_Error
$force  = $plugin->undoer()->undo( 42, true );                          // past a conflict
```

A custom recorder implements `Recorder` (`id`, `label`, `events`, `register`, `can_undo`, `current_state`, `undo`, `describe`, `flush`), logs through `$plugin->logger()->log( array( 'event' => ..., 'recorder' => ..., 'object_type' => ..., 'object_id' => ..., 'before' => ..., 'after' => ..., 'undoable' => true ) )`, and joins through the `activity_log_with_undo_recorders` filter.

## Data

| Where | Contents |
| --- | --- |
| `{prefix}activity_log_with_undo` table | One row per change: time (UTC), change set, event, recorder, object type, ID and name, user ID, source, IP address, the before and after state as JSON, whether it can be undone, `undone_by` and `undo_of`, and for an approved AI agent call `agent`, `agent_request` and `approved_by`. `before` is a reserved word in MySQL, so the states live in `before_state` and `after_state` |
| `activity_log_with_undo_settings` | Recorders turned off, retention days, IP mode, payload limit, admin bar |
| `activity_log_with_undo_db_version` | Table version, for upgrades. Version 2 added the agent columns |
| `activity_log_with_undo_cleanup` | State of the upgrade clean-up: status, the options found, entries found and deleted |
| `activity_log_with_undo_cleanup` (job) | WP-Cron job that keeps deleting those entries in batches until done |
| `_transient_activity_log_with_undo_*` | The per-option rate-limit counters, the cached table size, and one-time notices |
| `activity_log_with_undo_purge` | Daily WP-Cron job that deletes old entries |

States that survive a JSON round trip are stored as `{"v": ...}`. Anything else (objects in an option, for example) is stored serialized as `{"s": "..."}`, the way WordPress stores options. A state over the size limit is stored as `{"over_limit": <bytes>}`, or `{"over_limit": <bytes>, "reason": "log_full"}` when the log was over its size limit, and the entry cannot be undone.

Uninstalling drops the table, deletes the options and the job, on every site of a network.

## Privacy

Entries hold the user ID, the source and the IP address of each change: shortened by default (`192.168.1.0`), full or none from the settings, and for an approved agent call the user ID of the approver. They take part in **Tools > Export Personal Data** and **Tools > Erase Personal Data**. Erasing keeps the change history and removes the person from it: the user ID becomes "(removed)", also where they approved an agent call, the IP address goes, and entries about their own account lose the stored profile values. **Settings > Privacy** gets suggested text for your privacy policy.

## Multisite

Each site has its own table, created the first time the plugin loads there. Network options and sites added or deleted are logged on the main site.
