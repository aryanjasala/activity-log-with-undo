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

* **Settings and widgets**: any option, added, changed or deleted. Undo writes the old value back. Transients, caches, cron and similar noise are left out.
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
* **Undo is logged too**, so it can be undone. That is your redo.
* **Before and after, side by side.** A word diff for text, a table for everything else.

= Find a change =

Tools > Activity Log lists every change with who made it and how: the dashboard, the REST API or block editor, an application password, WP-CLI, a scheduled task, XML-RPC or an AI agent through the Abilities API. Filter by event, user, source, dates and object, or search. Select several rows to undo them in one go.

Turn on "Undo last change" in the admin bar to undo your own most recent change from any screen.

= From the command line =

`wp activity-log-with-undo list --since="1 day ago" --undoable`
`wp activity-log-with-undo show 42`
`wp activity-log-with-undo undo 42`
`wp activity-log-with-undo undo 42 --force`
`wp activity-log-with-undo purge --older-than=30`
`wp activity-log-with-undo settings --retention-days=30 --ip=off`

= For AI agents =

On WordPress 6.9 and later, three abilities let an agent list the activity, read one change with its before and after state, and undo it. Undo refuses when things changed since, unless the agent passes `force`.

= Privacy =

Entries are kept for 90 days by default. IP addresses are shortened by default, and can be kept in full or not at all. The log takes part in the WordPress personal data export and erase tools: erasing keeps the change history and removes the person from it.

== Frequently Asked Questions ==

= Why can some changes not be undone? =

Undo needs the state from before the change. Plugin and theme updates replace files WordPress does not keep, and a permanently deleted post or user is gone with everything attached to it. Those are logged, with the reason shown next to them. A change whose old value is bigger than the size limit (256 KB by default) is logged without it and cannot be undone either.

= What happens if someone changed it again after the entry? =

Undo stops and shows what is different between the logged change and now. Use Undo anyway to put the old version back and overwrite the later change, or leave it.

= Does it log changes made with WP-CLI? =

Yes, and the source shows as WP-CLI. Every WP-CLI command is its own change set.

= Will it fill up my database? =

Entries older than 90 days are deleted once a day. Change the period on the Settings tab, turn off the kinds of changes you do not need, or run `wp activity-log-with-undo purge`.

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
