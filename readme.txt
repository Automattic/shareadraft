=== Share a Draft ===
Contributors: nbachiyski, automattic
Tags: post, draft, drafts, share, sharing
Requires at least: 4.0
Tested up to: 7.0
Stable tag: 1.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Share private preview links to your drafts

== Description ==

Drafts in WordPress are visible for the author and blog administrators. In many cases, however, you want
to share a draft with your friends or colleagues for either review or approval.

Share a Draft allows you to create a unique link to a draft for a limited time and send it to whoever you want.

== Installation ==

This section describes how to install the plugin and get it working.

e.g.

1. Upload the whole `shareadraft` folder to `/wp-content/plugins/`
1. Activate the plugin through the `Plugins` menu in WordPress
1. Go to Manage → Share a Draft and enjoy!

== Screenshots ==

1. From Posts → Share a Draft you can share a new draft.

2. From Posts → Share a Draft you can managed your shared drafts.


== Changelog ==

= 1.6 =
* Fixed PHP 8.x deprecation notices for undeclared class properties
* Shares whose post has since been deleted no longer emit warnings, and can now be removed
* Expiry times no longer read "14 days, 0 hours, 0 minutes"
* Fixed a post ID comparison that could cause a valid share link to 404
* Tested with WordPress 7.0 and PHP 8.4

= 1.5 =
* Test with newest WordPress versions
* Update copy
* Removed seconds as a granularity level
* Light cleanup of the almost 10-year old code

= 1.4 =
* Included your own scheduled posts to the list
* The link is now a real link
* Dropped PHP4 support
* Internal improvements
* Italian translation thanks to gidibao's Cafe (http://gidibao.net/)
* French translation thanks to Nicolas Brisebois-Tetreault

= 1.3 =
* Fix draft links for installs where WordPress URL is different from website URL

