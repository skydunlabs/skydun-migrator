# Sky Migrate

The WordPress plugin a customer installs on their old website to copy it into
[Skydun](https://skydun.com). They paste the migration key from the Skydun
migration page under **Tools → Sky Migrate**; Skydun then pulls the website in
small, HMAC-signed requests. Nothing on the old website changes.

- `sky-migrate.php` holds all the PHP; `assets/` the admin page's CSS and JS.
  PHP 7.4+ and WordPress 5.0+.
- `readme.txt` is the WordPress.org listing. Keep its **External services**
  section in step with what the plugin sends.
- Published on WordPress.org as `sky-migrate`. Skydun also serves
  `sky-migrate.zip` from the **latest release** of this repository.

## Releasing

1. Bump `Version:` in the plugin header, `const VERSION` and `Stable tag:` in
   `readme.txt` to the same value, and add a changelog entry.
2. Tag `v<version>` and push the tag. The Release workflow checks the tag
   matches all three, builds the zip, publishes the GitHub release and, when
   the `SVN_USERNAME` and `SVN_PASSWORD` secrets are set, deploys to
   WordPress.org.

Files listed in `.distignore` are left out of the zip and of WordPress.org.
WordPress.org banners, icons and screenshots go in `.wordpress-org/`
(`banner-772x250.png`, `banner-1544x500.png`, `icon-128x128.png`,
`icon-256x256.png`, `screenshot-1.png`, …).

## Contract with Skydun

Keep these in step with the Skydun app (`App\Support\SiteMigrationSignature`
and the migration driver `resources/coolify/wordpress/migration/managed-wp-migrate.php`).
They keep their pre-rename names so existing Skydun releases keep working:

- Key format: `mwp1_` + base64url JSON `{u: app URL, k: source token, s: secret}`.
- Signature: HMAC-SHA256 with the secret over
  `METHOD\npath\ntimestamp\nnonce\nsha256(body)`, sent as `X-MWP-Timestamp`,
  `X-MWP-Nonce` and `X-MWP-Signature`. The path is the URL path for Skydun
  endpoints and the REST route (`/managed-wp-migrator/v1/{endpoint}`) for this
  plugin's endpoints.
- Endpoints: `info`, `tables`, `table-chunk`, `files`, `file-chunk`, `finish`,
  over `?rest_route=` or `wp-admin/admin-ajax.php?action=managed_wp_migrator&endpoint=`.
- Errors are JSON `{code: "managed_wp_migrator_…", message}`.
- `file-chunk` always sends exactly `s` bytes after each header line. If a file
  shrinks while it is sent, the rest is NUL-padded, so its MD5 no longer
  matches `h` and the file should be fetched again.
