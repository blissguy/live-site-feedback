=== Live Site Feedback ===
Contributors: mixbusmarketing
Tags: feedback, comments, client review, annotations, website review
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.17.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let clients leave comments directly on your website, pinned to exactly what they are looking at.

== Description ==

Getting feedback on a website usually means a long email thread full of "the button on the third section down looks off". This plugin removes that step. Your client opens the site, clicks the spot they want to talk about, and types. The comment stays pinned to that exact place.

Comments are collected in your MarkUp.io account, so you can work through them, reply, and mark them done.

**How it works**

* A small feedback bar appears on the site for the people you choose — your team, your client, or anyone you send a link to.
* Clicking anywhere on the page leaves a pin with a comment attached.
* Logged-in WordPress users comment under their own name automatically, with no separate sign-in.
* Everyone else can sign in through MarkUp.io.

**Requirements**

* A MarkUp.io account with SDK access.
* A publicly reachable website for screenshots to work. Local development sites can still collect comments, but MarkUp.io will not be able to capture a preview image.

== Installation ==

1. Upload the `mbm-live-feedback` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress.
3. Go to **Live Feedback > Settings** and paste your MarkUp.io API key.
4. Click **Set up automatically**.

If you would rather keep your key out of the database, add it to `wp-config.php` instead:

`define( 'MBM_LF_API_KEY', '...' );`

Values in `wp-config.php` always take priority, and the matching field in the admin becomes read-only so it is clear where the setting is coming from.

== Frequently Asked Questions ==

= Where are the comments stored? =

In your MarkUp.io account. This plugin connects your site to it and decides who sees the feedback bar.

= Does every page have its own comment list? =

By default, no — everything goes into one list for the whole site, which is usually what you want when a client is reviewing. Pins still sit on the page they were left on. If you would rather each page had its own list, uncheck "Keep all feedback for this site in one list" on the Feedback bar tab of the settings.

= Can I turn the feedback bar off for one person? =

Yes. Open their profile under **Users** and uncheck **Show this person the feedback bar on the site**. Nothing loads for them after that — no bar, no pins, and they cannot leave comments. Only administrators see that checkbox, so nobody can switch themselves back on.

Everyone else is unaffected, and anything that person already wrote stays where it is.

= Can visitors see the feedback bar? =

No. By default only people who can edit posts see it, and you choose who that includes.

= Is my API key safe in the database? =

It is encrypted before it is stored, using your site's own security keys. Keeping it in `wp-config.php` is still the stronger option for a live site, which is why the settings screen offers it. Note that if your site security keys are ever regenerated, you will need to paste the key in again.

= What happens if I uninstall the plugin? =

The plugin removes its own settings and stops MarkUp.io notifying your site. Your comments and markups stay in your MarkUp.io account, and any tasks already created stay in Motion. Nothing you or your clients wrote is deleted.

= How does the plugin update itself? =

It checks its own repository for new releases and reports them to WordPress, so updating works exactly like any plugin from the WordPress directory. Nothing is sent anywhere and no account is needed.

== Changelog ==

= 0.17.0 =
* Motion no longer depends on MarkUp.io's notifications reaching the site. The site now checks for new comments, replies, resolves and priority changes every five minutes, and a notification just makes that happen sooner. Firewalls such as Cloudflare's Bot Fight Mode had been turning those notifications away without anyone knowing.
* The Motion integration tab says when the site last checked for new comments, and warns if checks have stopped.
* Several replies left close together now all reach the Motion task, not just one.
* Check and repair setup now handles a change of address. It registers the site for its new address and removes the old one's setup, but only after confirming the old address is gone, redirects here, or is this same site.
* A staging copy of a live site gets its own feedback list and pauses Motion when repaired, and live is left untouched. Pushing staging back over live switches live back to its own list.
* A notice on every admin screen says when the site's address has changed since setup.
* Running setup or uninstalling on a copy of a site no longer stops the original site's notifications.
* The Comments screen can show open comments only, or all of them including resolved. Each page lists its comments with a link straight to each one in MarkUp.io.
* The menu is now Live Feedback > Comments and Settings, and the settings tabs are Setup, Feedback bar, People, Motion integration and Advanced. Site identifier has moved to Advanced.
* Shorter, clearer wording throughout, in American English.

= 0.16.0 =
* You can now turn the feedback bar off for one person, on their profile under Users. Nothing loads for them at all.
* Their profile also says so when the feedback bar was never going to show for that account anyway, and links to the setting that decides it.

= 0.15.1 =
* Internal tidy-up of the task queue's database code. No change to how anything behaves.

= 0.15.0 =
* The settings screen is now split into tabs — Setup, Feedback, People, Integrations and Advanced — with one save button covering all of them.
* Connection status shows on every tab, and you are warned before leaving the page with unsaved changes.
* Wording throughout is shorter and clearer.

= 0.14.0 =
* Tasks now say how wide the browser window was when the comment was left, along with the browser and operating system, so you can reproduce what the client saw at the exact width they saw it.

= 0.13.0 =
* You can now send one page's feedback to a specific person, from the page itself, whoever wrote it.
* New tasks can carry labels from your Motion workspace, so site feedback is easy to pick out from the rest of the board.
* Anything that could not be sent to Motion is now listed one by one, with what was tried and why it stopped, and can be retried or dropped individually.

= 0.12.0 =
* Fixed: feedback left on other sites in the same MarkUp.io workspace could turn into tasks in this site's Motion project. Only comments on pages of this site are sent now.
* New: you can pause the Motion integration without removing your API key or losing any of its settings.

= 0.11.0 =
* New setting: choose the status new feedback arrives in, so tasks land in the column your team picks work up from instead of Motion's default.
* Reopening a resolved comment now returns its task to that same status rather than always Todo.

= 0.10.0 =
* Marking a comment as done now completes its Motion task, and reopening the comment puts the task back.
* A comment's priority is copied to its task.
* Added an option to always give feedback to one person, whoever wrote the page. Useful when pages were built by a freelancer or by somebody who has since left.

= 0.9.0 =
* Added an optional Motion connection. Once you connect it, every new comment becomes a task in the project you choose, given to whoever wrote that page.
* Replies to a comment are added to the same task rather than creating another one.
* Each task quotes the comment and links straight back to the pin on the page.
* Work is sent in the background, so nobody waits on it, and the settings screen shows what is waiting and anything that failed.
* Leave the Motion key empty and none of this runs at all.

= 0.8.1 =
* Clearer wording in a few places, and a tidy-up of the code behind the settings screen. Nothing changes in how the plugin behaves.

= 0.8.0 =
* Added a Feedback item to the toolbar, visible on the site as well as in the admin, showing how many comments are waiting.
* Hover it for the pages that need attention and how many each has. Click a page to go straight there; click the toolbar item itself for the full list.
* The count now updates by itself shortly after a client comments, instead of waiting for someone to open the Feedback screen.

= 0.7.1 =
* Fixed: the Feedback screen stopped halfway down with an error instead of finishing the page.

= 0.7.0 =
* Added a Feedback screen listing every page a client has commented on, how many comments it has, and how many still need attention. Click a page to open it on the site, where the feedback bar shows the comments in place.
* The number of comments waiting appears next to the menu, and on your dashboard.
* MarkUp.io now tells the site as soon as feedback changes, so the counts stay current.
* Removed the option to restrict which part of a page accepts comments — people should be able to comment on the header and footer too.

= 0.6.0 =
* All feedback for a site now goes into a single list by default, so you can work through a client's comments in one pass instead of checking page by page. Pins still appear on the page they were left on.
* The shared list is created for you — there is no ID to fetch or paste anywhere.
* Untick one box if you would rather each page kept its own separate list.
* The feedback bar now also appears on archives, search results and your shop, which previously had nowhere to put comments.

= 0.5.0 =
* Logged-in users now comment under their own name, with nothing extra to sign in to. Everyone else still signs in through MarkUp.io as before.
* Your team's names and pictures appear on their comments automatically, so it is clear who said what. Your WordPress user IDs are never shared — each person is identified by a code that means nothing outside your site.
* People recognised this way can comment, reply, and mark things as done. Deleting a comment is done in MarkUp.io itself.

= 0.4.0 =
* The plugin now updates itself. New versions appear on your Plugins screen like any other plugin, so there is no downloading and uploading files by hand.
* Added an Updates section to the settings screen showing your current version, with a button to check for a new one straight away.

= 0.3.0 =
* Added one-click setup. Paste your API key, press a button, and the plugin registers this site with MarkUp.io and creates its own signing key — no copying identifiers between tabs.
* Pages now get their own MarkUp.io ID automatically the first time an editor views them, so comments on different pages stay in separate lists.
* Added a setup checklist showing exactly what is ready and what still needs attention.
* Added a "Create ID now" button to the editor panel, and a warning when a page could not be set up.

= 0.2.1 =
* Fixed: the site identifier could only be set by editing wp-config.php, so the plugin could not be fully set up from its own settings screen. There is now a field for it.
* Fixed: a "file cannot be read" warning could appear under settings that are not file paths.

= 0.2.0 =
* The feedback bar now appears on your site. Choose which content types collect feedback, who can see the bar, and where it sits on screen.
* Added a Live Site Feedback panel to the editor for pasting a page's MarkUp.io ID, with the option to turn feedback off for individual pages.
* The bar stays hidden inside page builders, previews, and feeds so it never gets in the way while you are working.
* Added an optional site-wide ID for collecting feedback on archives and any page you have not set up individually.

= 0.1.0 =
* First working version. Connects your site to MarkUp.io: settings screen, encrypted key storage, the option to set everything in `wp-config.php` instead, and a connection test.
