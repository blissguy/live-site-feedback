=== Live Site Feedback ===
Contributors: mixbusmarketing
Tags: feedback, comments, client review, annotations, website review
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.8.0
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
3. Go to **Live Feedback** in the admin menu and paste your MarkUp.io API key.
4. Click **Test connection** to confirm it worked.

If you would rather keep your key out of the database, add it to `wp-config.php` instead:

`define( 'MBM_LF_API_KEY', '...' );`

Values in `wp-config.php` always take priority, and the matching field in the admin becomes read-only so it is clear where the setting is coming from.

== Frequently Asked Questions ==

= Where are the comments stored? =

In your MarkUp.io account. This plugin connects your site to it and decides who sees the feedback bar.

= Does every page have its own comment list? =

By default, no — everything goes into one list for the whole site, which is usually what you want when a client is reviewing. Pins still sit on the page they were left on. If you would rather each page had its own list, untick "Keep all feedback for this site in one list" on the settings screen.

= Can visitors see the feedback bar? =

No. By default only people who can edit posts see it, and you choose who that includes.

= Is my API key safe in the database? =

It is encrypted before it is stored, using your site's own security keys. Keeping it in `wp-config.php` is still the stronger option for a live site, which is why the settings screen offers it. Note that if your site security keys are ever regenerated, you will need to paste the key in again.

= What happens if I uninstall the plugin? =

The plugin removes its own settings. Your comments and markups stay in your MarkUp.io account, untouched.

= How does the plugin update itself? =

It checks its own repository for new releases and reports them to WordPress, so updating works exactly like any plugin from the WordPress directory. Nothing is sent anywhere and no account is needed.

== Changelog ==

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
