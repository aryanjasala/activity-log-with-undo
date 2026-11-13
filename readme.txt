=== Activity Log with Undo ===
Contributors: aryanjasala
Tags: activity log, undo, audit log, revert, history
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An activity log where every change you can reverse has an Undo button: settings, plugins, themes, users, posts, terms, menus and the Site Editor.

== Description ==

Activity logs tell you what changed. They do not help you put it back. Someone changed the site title, deactivated the wrong plugin, gave a user the wrong role or deleted a category, and now you have to remember what it was before.

Activity Log with Undo logs each change together with what it was before, and gives every change it can reverse an Undo button.

What it logs, and what Undo does:

* **Settings and widgets**: the settings people change on purpose. That is everything on the core Settings screens, permalinks, the privacy page, widgets, Customizer settings, and options plugins register with the Settings API. Undo writes the old value back. Other plugins' caches, indexes and counters are left out, and so are settings changed by a cron job or a visitor's page view.
* **Plugins**: activation and deactivation, undone by switching back. Installs, updates and deletions are logged only, because WordPress keeps no copy of the old files.
* **Themes**: a theme switch, undone by switching back while the old theme is still installed.
* **Users**: role changes, display name, email and website, all undoable. Undoing a new user deletes the account and gives their content to whoever added them, after you confirm. Deleted users are logged only.
* **Posts, pages and other content**: publish, unpublish, trash and restore, edits to the title, content and excerpt, and changes to the slug, parent, author, featured image and template. Content edits come back through the matching revision when the post has one.
* **Categories, tags and other terms**: new, edited and deleted. A deleted term is rebuilt with its meta, posts and child terms. With Category & Tag Trash active, it is restored from that trash instead.
* **Menus**: the whole menu with its items is kept before each save, and Undo puts it back.
* **Site Editor**: global styles, templates, template parts and navigation. Undo restores the previous revision, removes a new customization, or brings back one you cleared.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

= Safe to undo =

* **Changed since?** Undo only runs straight away when things still look the way the change left them. If someone changed it again, you see the differences and choose Undo anyway, or leave it.
* **One save, one undo.** Changes made in the same request are a change set. Saving Settings > Reading changes several options, and one Undo puts them all back.
* **You see what else goes back.** When a change set holds more than one change, the confirm lists the others first. On WP-CLI too, unless you pass `--yes`.
* **Only your changes.** Another plugin's state that happened to change in the same request is never part of your undo.
* **Undo is logged too**, so it can be undone. That is your redo.
* **Before and after, side by side.** A word diff for text, a table for everything else.

= Roll back to a time =

"The site was fine at 2 pm." Open Tools > Activity Log > Roll back, pick 1 hour ago, this morning, yesterday, a time of your own, or "just before this change" from any row. You can narrow it to one user or one area.

The preview lists every change since then, newest first, grouped by area. Each one is marked as will be undone, cannot be undone (with the reason), or clashes with a later change that is not in the list. Nothing happens until you press the button. The run undoes the changes in small batches with a progress bar, skips clashes and tells you which, or stops at the first one if you ask. The roll back is logged as one entry, and Redo on it puts everything back.

= Only what you may see =

The log can be opened by people who cannot edit everything in it. An entry about a post, term, user or setting you are not allowed to edit shows as a hidden change, without its name, values or IP address, in the log, the REST API, the abilities and WP-CLI. You cannot undo it either.

= Alerts and a daily digest =

Hear about the changes that matter, with a link to the entry in the message. Open Tools > Activity Log > Alerts and add a rule: pick the events (or any change), narrow it by area, by who made it or their role, and optionally to changes made outside working hours. Six starter rules are offered, all off until you turn them on: a new administrator, a plugin deactivated or deleted, the theme switched, the site address changed, anyone can register turned on, and the default role changed.

Send email, a webhook, or both. The webhook gets JSON, or the Slack format that many chat tools accept. Send right away or as one digest a day. A bulk change is one message, and a rule that just sent waits a few minutes before it sends the next one. Every rule has a Send a test button.

Each message says who changed what, with the old and new value. Undo happens on the site after you sign in: there is no undo link in an email or a webhook. The webhook URL is a secret. It is never shown again after you save it, and the abilities and WP-CLI never return it. Each person only reads about what they may edit. Anything else shows as a change they are not allowed to see.

= Find a change =

Tools > Activity Log lists every change with who made it and how: the dashboard, the REST API or block editor, an application password, WP-CLI, a scheduled task, XML-RPC or an AI agent through the Abilities API. Filter by event, user, source, dates and object, or search. Select several rows to undo them in one go.

Turn on "Undo last change" in the admin bar to undo your own most recent change from any screen.

= From the command line =

`wp activity-log-with-undo list --since="1 day ago" --undoable`
`wp activity-log-with-undo show 42`
`wp activity-log-with-undo undo 42`
`wp activity-log-with-undo undo 42 --force`
`wp activity-log-with-undo rollback --to="2026-11-13 14:00" --dry-run`
`wp activity-log-with-undo purge --older-than=30`
`wp activity-log-with-undo cleanup --dry-run`
`wp activity-log-with-undo settings --retention-days=30 --ip=off`
`wp activity-log-with-undo alerts add "Plugin removed" --starter=plugin-removed --enable`
`wp activity-log-with-undo alerts test <id>`

= For AI agents =

On WordPress 6.9 and later, eight abilities let an agent list the activity, read one change with its before and after state, undo it, preview a roll back to a moment (read-only) and run it, list the alert rules, turn one on or off and send a test alert. None of them returns a webhook URL. Undo refuses when things changed since, unless the agent passes `force`.

With Agent Governance active, a change an agent made after someone approved its request says which agent it was, the request number, and who approved it.

= External services =

Only if you add a webhook to an alert rule. Then the site sends a short JSON message about a change (who, what, the old and new value, a link to the entry) to the URL you chose, for example a Slack, Mattermost or Discord incoming webhook, or your own service. Nothing is sent until you add a webhook, and the plugin never chooses one for you. The service you pick has its own terms and privacy policy.

= Privacy =

Entries are kept for 90 days by default. IP addresses are shortened by default, and can be kept in full or not at all. The log takes part in the WordPress personal data export and erase tools: erasing keeps the change history and removes the person from it. Alert rules keep the email addresses that get alerts. They are exported and erased the same way.

== Frequently Asked Questions ==

= Why can some changes not be undone? =

Undo needs the state from before the change. Plugin and theme updates replace files WordPress does not keep, and a permanently deleted post or user is gone with everything attached to it. Those are logged, with the reason shown next to them. A change whose old value is bigger than the size limit (256 KB by default) is logged without it and cannot be undone either.

= What happens if someone changed it again after the entry? =

Undo stops and shows what is different between the logged change and now. Use Undo anyway to put the old version back and overwrite the later change, or leave it.

= Can I roll the whole site back? =

Yes, to a moment you pick, for everything the log can undo. Changes that cannot be undone, such as plugin updates and permanent deletions, stay as they are, and the preview lists them with the reason. Files, uploads and anything the log does not record are not touched.

= Does it log changes made with WP-CLI? =

Yes, and the source shows as WP-CLI. Every WP-CLI command is its own change set.

= Can an alert undo a change from the email? =

No, on purpose. A link that undoes a change when you click it can be clicked by a mail scanner, or forwarded. The message links to the entry in the log. You sign in and press Undo there.

= Who gets told about a change to something they may not edit? =

Nobody. Each address gets its own message, and what it says is cut down to what that person may edit. A person who may not edit the object reads "A change to something you are not allowed to see", with only who made it and when.

= Will it fill up my database? =

No. It logs settings, not every option another plugin writes, and each option is logged at most 20 times a minute. A setting value over 64 KB is logged without the value. If the log still grows past 512 MB, new changes are logged without their old values and Site Health tells you. Entries older than 90 days are deleted once a day. Change the period on the Settings tab, turn off the kinds of changes you do not need, or run `wp activity-log-with-undo purge`. All of these limits have filters.

= Why is a change to my plugin's option not in the log? =

The log keeps settings people change on purpose. If your plugin saves its settings with the Settings API (`register_setting()`), they are logged. For anything else, add the option name to the `activity_log_with_undo_logged_options` filter.

= I updated from an earlier version and the log is huge. =

Version 1.0.0 logged every option, so other plugins' internal state could fill the table. After the update, administrators see a notice that counts those entries and offers to delete them. `wp activity-log-with-undo cleanup` does the same from the command line.

= I use Category & Tag Trash. Who restores deleted terms? =

Category & Tag Trash keeps deleted terms with their posts and menu links. When it is active, Undo restores the term from its trash, and the entry links to it.

== Screenshots ==

1. Tools > Activity Log: every change with who made it, how, and an Undo button.
2. One change with its before and after values side by side.
3. A change that was changed again since: the differences, and Undo anyway.
4. The settings: what to log, how long to keep it, and IP addresses.

== Changelog ==

= 1.0.0 =
* First release.
