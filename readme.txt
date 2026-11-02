=== Sky Migrate ===
Contributors: skydun
Tags: migration, migrate, move, copy, hosting
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move your WordPress website to Skydun in a few clicks, with no FTP, database passwords or large backup files.

== Description ==

Sky Migrate copies your existing WordPress website to [Skydun](https://skydun.com). Install it on the website you are moving, paste the migration key from Skydun, and Skydun copies the database, themes, plugins and uploads into your new website.

* **Nothing on your website changes.** Sky Migrate only reads; your website keeps working normally during the copy.
* **Works on small hosts.** Skydun pulls the website in small pieces, so short time and memory limits are not a problem, and the copy resumes where it stopped.
* **No passwords to share.** No FTP, SSH or database credentials are needed.
* **Secure by design.** Every request from Skydun is signed with a secret only your migration key holds, expires after five minutes and cannot be replayed. The secret is deleted when the copy finishes, when you click Disconnect, or when you deactivate the plugin.
* **Only what you need.** `wp-config.php`, WordPress core files, caches, backups, logs and your old host's own plugins are never copied.

A Skydun account is required. Multisite networks are not supported yet.

== External services ==

This plugin connects your website to Skydun (https://skydun.com), the hosting service you are moving the website to. It does nothing until you paste a migration key and click Connect.

**When you click Connect**, the plugin sends a request to the Skydun address in your migration key with: the website's home and site URLs, its title, the WordPress, PHP and plugin versions, the database table prefix, whether it is a multisite network, and the approximate size of the database and files.

**While the website is copied**, Skydun requests, and the plugin returns: the same website details plus the database charset and the list of active plugins; the structure and contents of every database table with the website's table prefix (including users and settings); and the list and contents of the website's files, except those listed above that are never copied. The plugin only answers requests signed with the secret from your migration key.

**When the copy finishes**, or when you click Disconnect or deactivate the plugin, the secret is deleted and Skydun can no longer read the website.

Skydun terms of service: https://skydun.com/terms
Skydun privacy policy: https://skydun.com/privacy

== Installation ==

1. Install and activate Sky Migrate on the website you are moving, from **Plugins → Add New** or by uploading the zip.
2. In Skydun, open the migration page of your new website and copy the migration key.
3. On your old website, go to **Tools → Sky Migrate**, paste the key and click **Connect**.
4. Follow the progress in Skydun. When the copy finishes you can deactivate and delete Sky Migrate.

== Frequently Asked Questions ==

= Does Sky Migrate change my website? =

No. It only reads the database and files. Your website stays online and unchanged.

= What if my security plugin blocks the REST API? =

Sky Migrate also answers through `admin-ajax.php`. The Tools → Sky Migrate page checks both and tells you if Skydun may not be able to reach the website.

= Is my wp-config.php copied? =

No. It holds your current host's passwords, so it is never sent. Your new website on Skydun has its own.

= Can I stop a copy? =

Yes. Click **Disconnect** on the Tools → Sky Migrate page, or deactivate the plugin. Skydun can no longer read the website afterwards.

= Does it work with multisite? =

Not yet.

== Screenshots ==

1. Paste your migration key from Skydun.
2. Connected: follow the copy in Skydun.

== Changelog ==

= 2.0.0 =
* Renamed from Managed WP Migrator to Sky Migrate. Migration keys from Skydun work as before.

= 1.0.0 =
* First release.
