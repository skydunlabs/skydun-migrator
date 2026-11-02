<?php
/**
 * Plugin Name:       Sky Migrate
 * Plugin URI:        https://skydun.com
 * Description:       Copies this website to Skydun. Paste the migration key from Skydun under Tools → Sky Migrate.
 * Version:           2.0.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            Skydun
 * Author URI:        https://skydun.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sky-migrate
 *
 * How it works: Skydun pulls this website in small, signed requests, so it
 * runs on hosts with short time and memory limits and needs no FTP or database
 * passwords. Every request to the export endpoints must carry an HMAC-SHA256
 * signature made with the secret from the migration key; nothing is served
 * without it. wp-config.php and WordPress core files are never sent. The
 * secret is deleted when the copy finishes, when the plugin is deactivated,
 * or with Disconnect.
 */
defined('ABSPATH') || exit;

final class Sky_Migrate
{
    const VERSION = '2.0.0';

    const SLUG = 'sky-migrate';

    /** The connection: panel URL, source token, secret, and the migration page URL. */
    const OPTION = 'sky_migrate';

    /** When the last copy finished, and where to see it. */
    const FINISHED_OPTION = 'sky_migrate_finished';

    const ESTIMATES_TRANSIENT = 'sky_migrate_estimates';

    /** 'redirect' right after activation, 'notice' when redirecting would get in the way. */
    const ACTIVATED_TRANSIENT = 'sky_migrate_activated';

    // The key format, REST namespace, AJAX action and error code prefix are
    // the contract with Skydun's migration driver; they keep their original
    // names so existing Skydun releases keep working.

    const KEY_PREFIX = 'mwp1_';

    const REST_NAMESPACE = 'managed-wp-migrator/v1';

    const AJAX_ACTION = 'managed_wp_migrator';

    const ERROR_PREFIX = 'managed_wp_migrator_';

    const ENDPOINTS = ['info', 'tables', 'table-chunk', 'files', 'file-chunk', 'finish'];

    /** How far a request's timestamp may be from now, in seconds. */
    const SIGNATURE_TOLERANCE = 300;

    /** Rows are fetched in batches of this many while a chunk is built. */
    const ROW_BATCH = 200;

    const DEFAULT_CHUNK_BYTES = 2097152;

    const MAX_CHUNK_BYTES = 8388608;

    const MAX_STATEMENT_BYTES = 1048576;

    const MANIFEST_PAGE = 2000;

    const MAX_BATCH_FILES = 500;

    /** Seconds spent listing files per request, so slow disks never hit the host's time limit. */
    const LISTING_SECONDS = 15;

    const ESTIMATE_SECONDS = 8;

    /**
     * Paths never sent, relative to the website root. wp-config.php holds
     * this host's secrets; core comes from WordPress.org on the new website;
     * caches, backups and drop-ins would break or bloat the copy; the Skydun
     * helpers and the object cache plugin are the new website's own.
     */
    const EXCLUDED = [
        'wp-config.php',
        'wp-admin',
        'wp-includes',
        'index.php',
        'license.txt',
        'readme.html',
        'xmlrpc.php',
        'wp-activate.php',
        'wp-blog-header.php',
        'wp-comments-post.php',
        'wp-config-sample.php',
        'wp-cron.php',
        'wp-links-opml.php',
        'wp-load.php',
        'wp-login.php',
        'wp-mail.php',
        'wp-settings.php',
        'wp-signup.php',
        'wp-trackback.php',
        '.htaccess',
        '.user.ini',
        'php.ini',
        '.git',
        '.svn',
        '.well-known',
        'cgi-bin',
        '.maintenance',
        'wp-content/cache',
        'wp-content/upgrade',
        'wp-content/upgrade-temp-backup',
        'wp-content/debug.log',
        'wp-content/object-cache.php',
        'wp-content/advanced-cache.php',
        'wp-content/db.php',
        'wp-content/updraft',
        'wp-content/ai1wm-backups',
        'wp-content/backups-dup-lite',
        'wp-content/backups-dup-pro',
        'wp-content/wpvividbackups',
        'wp-content/backup-db',
        'wp-content/wflogs',
        'wp-content/et-cache',
        'wp-content/litespeed',
        'wp-content/plugins/sky-migrate',
        'wp-content/plugins/managed-wp-migrator',
        'wp-content/plugins/redis-cache',
    ];

    /**
     * Includes the must-use plugins hosts install for themselves (WP Engine,
     * Kinsta, GoDaddy, Bluehost), which fail anywhere else.
     */
    const EXCLUDED_PATTERNS = [
        'wp-content/mu-plugins/managed-wp-*',
        'wp-content/mu-plugins/wpengine-*',
        'wp-content/mu-plugins/mu-plugin.php',
        'wp-content/mu-plugins/kinsta-mu-plugins*',
        'wp-content/mu-plugins/gd-system-plugin*',
        'wp-content/mu-plugins/endurance-*',
        'wp-content/uploads/backwpup-*',
        'wp-content/uploads/wp-migrate-db',
    ];

    const EXCLUDED_NAMES = ['error_log', '.DS_Store', 'Thumbs.db'];

    public static function boot()
    {
        add_action('admin_menu', [__CLASS__, 'add_page']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_init', [__CLASS__, 'redirect_after_activation']);
        add_action('admin_notices', [__CLASS__, 'activation_notice']);
        add_filter('plugin_action_links_'.plugin_basename(__FILE__), [__CLASS__, 'action_links']);
        add_action('admin_post_sky_migrate_connect', [__CLASS__, 'connect']);
        add_action('admin_post_sky_migrate_disconnect', [__CLASS__, 'disconnect']);
        add_action('wp_ajax_sky_migrate_preflight', [__CLASS__, 'preflight']);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        // Fallback for websites whose security plugin blocks the REST API.
        add_action('wp_ajax_nopriv_'.self::AJAX_ACTION, [__CLASS__, 'dispatch_ajax']);
        add_action('wp_ajax_'.self::AJAX_ACTION, [__CLASS__, 'dispatch_ajax']);
    }

    /**
     * Open the migration page on the next admin page load. Not for WP-CLI,
     * which has no browser to send there.
     */
    public static function activate()
    {
        if (! (defined('WP_CLI') && WP_CLI)) {
            set_transient(self::ACTIVATED_TRANSIENT, 'redirect', 60);
        }
    }

    public static function forget()
    {
        delete_option(self::OPTION);
    }

    public static function uninstall()
    {
        delete_option(self::OPTION);
        delete_option(self::FINISHED_OPTION);
        delete_transient(self::ESTIMATES_TRANSIENT);
        delete_transient(self::ACTIVATED_TRANSIENT);
    }

    // ------------------------------------------------------------------
    // Admin page
    // ------------------------------------------------------------------

    public static function add_page()
    {
        add_management_page(
            __('Sky Migrate', 'sky-migrate'),
            __('Sky Migrate', 'sky-migrate'),
            'manage_options',
            self::SLUG,
            [__CLASS__, 'render_page']
        );
    }

    private static function page_url()
    {
        return admin_url('tools.php?page='.self::SLUG);
    }

    public static function enqueue_assets($hook)
    {
        if ($hook !== 'tools_page_'.self::SLUG) {
            return;
        }

        wp_enqueue_style(self::SLUG, plugins_url('assets/admin.css', __FILE__), [], self::VERSION);
        wp_enqueue_script(self::SLUG, plugins_url('assets/admin.js', __FILE__), [], self::VERSION, true);
        wp_localize_script(
            self::SLUG,
            'skyMigrate',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sky_migrate_preflight'),
                'keyPrefix' => self::KEY_PREFIX,
                'i18n' => [
                    'failed' => __('Could not check this website. Reload the page to try again.', 'sky-migrate'),
                    'badKey' => __('A migration key starts with mwp1_. Copy it again from Skydun.', 'sky-migrate'),
                ],
            ]
        );
    }

    public static function action_links($links)
    {
        array_unshift($links, '<a href="'.esc_url(self::page_url()).'">'.esc_html__('Migrate', 'sky-migrate').'</a>');

        return $links;
    }

    /**
     * Right after a single activation, open the migration page. Bulk,
     * network and AJAX activations (the Activate button on Add Plugins)
     * show activation_notice() instead.
     */
    public static function redirect_after_activation()
    {
        if (get_transient(self::ACTIVATED_TRANSIENT) !== 'redirect') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which screen WordPress redirected to.
        if (wp_doing_ajax() || is_network_admin() || isset($_GET['activate-multi']) || ! current_user_can('manage_options')) {
            set_transient(self::ACTIVATED_TRANSIENT, 'notice', 60);

            return;
        }

        delete_transient(self::ACTIVATED_TRANSIENT);
        wp_safe_redirect(self::page_url());
        exit;
    }

    /**
     * Once, after an activation that did not redirect, point to the
     * migration page.
     */
    public static function activation_notice()
    {
        if (get_transient(self::ACTIVATED_TRANSIENT) !== 'notice' || ! current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen !== null && $screen->id === 'tools_page_'.self::SLUG) {
            return;
        }

        delete_transient(self::ACTIVATED_TRANSIENT);
        ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php esc_html_e('Sky Migrate is ready. Paste your migration key from Skydun to start copying this website.', 'sky-migrate'); ?>
				<a href="<?php echo esc_url(self::page_url()); ?>"><?php esc_html_e('Open Sky Migrate', 'sky-migrate'); ?></a>
			</p>
		</div>
		<?php
    }

    public static function render_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $config = self::config();
        $finished = get_option(self::FINISHED_OPTION);
        $finished = is_array($finished) ? $finished : null;
        $connected = ! empty($config['connected_at']);
        $notice = get_transient('sky_migrate_notice_'.get_current_user_id());
        delete_transient('sky_migrate_notice_'.get_current_user_id());

        if ($connected) {
            $step = 2;
        } elseif ($finished !== null) {
            $step = 3;
        } else {
            $step = 1;
        }

        $steps = [
            1 => __('Connect', 'sky-migrate'),
            2 => __('Copy', 'sky-migrate'),
            3 => __('Done', 'sky-migrate'),
        ];
        ?>
		<div class="wrap sky-migrate">
			<h1><?php esc_html_e('Sky Migrate', 'sky-migrate'); ?></h1>
			<p class="sky-migrate-lead"><?php esc_html_e('Copy this website to Skydun: database, themes, plugins and uploads. Nothing on this website is changed, and it keeps working normally during the copy.', 'sky-migrate'); ?></p>

			<?php if (is_array($notice)) { ?>
				<div class="notice notice-<?php echo esc_attr($notice['type'] === 'error' ? 'error' : 'success'); ?>">
					<p><?php echo esc_html($notice['message']); ?></p>
				</div>
			<?php } ?>

			<?php if (is_multisite()) { ?>
				<div class="notice notice-error"><p><?php esc_html_e('WordPress multisite networks cannot be migrated yet.', 'sky-migrate'); ?></p></div>
			<?php } else { ?>
				<ol class="sky-migrate-steps">
					<?php foreach ($steps as $number => $label) { ?>
						<?php
                        // The last step is done, not in progress, once reached.
                        $class = $number < $step || ($number === 3 && $step === 3) ? 'is-done' : ($number === $step ? 'is-current' : '');
					    ?>
						<li class="<?php echo esc_attr($class); ?>"<?php echo $number === $step ? ' aria-current="step"' : ''; ?>>
							<span class="sky-migrate-step-number"><?php echo esc_html((string) $number); ?></span>
							<span class="sky-migrate-step-label"><?php echo esc_html($label); ?></span>
						</li>
					<?php } ?>
				</ol>

				<div class="sky-migrate-layout">
					<div class="sky-migrate-main">
						<?php
                        if ($connected) {
                            self::render_connected($config);
                        } else {
                            if ($finished !== null) {
                                self::render_finished($finished);
                            }

                            self::render_connect_form($finished !== null);
                        }
					    ?>
					</div>
					<div class="sky-migrate-side">
						<?php self::render_preflight(); ?>
						<?php self::render_scope(); ?>
					</div>
				</div>
			<?php } ?>
		</div>
		<?php
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function render_connected($config)
    {
        ?>
		<div class="sky-migrate-card">
			<h2><span class="dashicons dashicons-update sky-migrate-icon is-active" aria-hidden="true"></span><?php esc_html_e('Connected to Skydun', 'sky-migrate'); ?></h2>
			<p>
				<?php
            /* translators: %s: how long ago, e.g. "5 mins". */
            echo esc_html(sprintf(__('Connected %s ago.', 'sky-migrate'), human_time_diff((int) $config['connected_at'])));
        ?>
				<?php esc_html_e('Skydun copies this website as soon as your new website is ready. You can close this page.', 'sky-migrate'); ?>
			</p>
			<?php if (! empty($config['migration_url'])) { ?>
				<p><a class="button button-primary button-hero" href="<?php echo esc_url($config['migration_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Follow the progress in Skydun', 'sky-migrate'); ?></a></p>
			<?php } ?>
		</div>

		<div class="sky-migrate-card">
			<h2><?php esc_html_e('Disconnect', 'sky-migrate'); ?></h2>
			<p><?php esc_html_e('Stops Skydun from reading this website and deletes the migration key from it. A running copy then fails.', 'sky-migrate'); ?></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="sky_migrate_disconnect" />
				<?php wp_nonce_field('sky_migrate_disconnect'); ?>
				<?php submit_button(__('Disconnect', 'sky-migrate'), 'secondary', 'submit', false); ?>
			</form>
		</div>
		<?php
    }

    /**
     * @param  array<string, mixed>  $finished
     */
    private static function render_finished($finished)
    {
        ?>
		<div class="sky-migrate-card is-success">
			<h2><span class="dashicons dashicons-yes-alt sky-migrate-icon" aria-hidden="true"></span><?php esc_html_e('Copy finished', 'sky-migrate'); ?></h2>
			<p>
				<?php
            /* translators: %s: how long ago, e.g. "2 hours". */
            echo esc_html(sprintf(__('Skydun finished copying this website %s ago and the migration key was deleted from it.', 'sky-migrate'), human_time_diff((int) $finished['at'])));
        ?>
				<?php esc_html_e('You can now deactivate and delete Sky Migrate.', 'sky-migrate'); ?>
			</p>
			<?php if (! empty($finished['migration_url'])) { ?>
				<p><a class="button button-primary" href="<?php echo esc_url($finished['migration_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open your website in Skydun', 'sky-migrate'); ?></a></p>
			<?php } ?>
		</div>
		<?php
    }

    private static function render_connect_form($again)
    {
        ?>
		<div class="sky-migrate-card">
			<h2><?php $again ? esc_html_e('Copy again', 'sky-migrate') : esc_html_e('Paste your migration key', 'sky-migrate'); ?></h2>
			<p><?php esc_html_e('Find the key on the migration page of your new website in Skydun, copy it, and paste it here.', 'sky-migrate'); ?></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="sky-migrate-connect">
				<input type="hidden" name="action" value="sky_migrate_connect" />
				<?php wp_nonce_field('sky_migrate_connect'); ?>
				<label class="screen-reader-text" for="sky-migrate-key"><?php esc_html_e('Migration key', 'sky-migrate'); ?></label>
				<textarea id="sky-migrate-key" name="migration_key" rows="4" class="large-text code" required autocomplete="off" spellcheck="false" placeholder="mwp1_…" aria-describedby="sky-migrate-key-error"></textarea>
				<p id="sky-migrate-key-error" class="sky-migrate-field-error" hidden></p>
				<?php submit_button(__('Connect', 'sky-migrate'), 'primary', 'submit', true, ['id' => 'sky-migrate-submit']); ?>
			</form>
		</div>
		<?php
    }

    /**
     * Filled by assets/admin.js from preflight(), since sizing the website
     * takes a few seconds.
     */
    private static function render_preflight()
    {
        ?>
		<div class="sky-migrate-card" id="sky-migrate-preflight" aria-live="polite">
			<h2><?php esc_html_e('This website', 'sky-migrate'); ?></h2>
			<dl class="sky-migrate-facts">
				<dt><?php esc_html_e('WordPress', 'sky-migrate'); ?></dt>
				<dd><?php echo esc_html(get_bloginfo('version')); ?></dd>
				<dt><?php esc_html_e('PHP', 'sky-migrate'); ?></dt>
				<dd><?php echo esc_html(PHP_VERSION); ?></dd>
				<dt><?php esc_html_e('Database', 'sky-migrate'); ?></dt>
				<dd data-fact="database"><span class="spinner is-active"></span></dd>
				<dt><?php esc_html_e('Files', 'sky-migrate'); ?></dt>
				<dd data-fact="files"><span class="spinner is-active"></span></dd>
			</dl>
			<ul class="sky-migrate-checks" data-checks>
				<li class="is-pending"><span class="spinner is-active"></span><?php esc_html_e('Checking that Skydun can reach this website…', 'sky-migrate'); ?></li>
			</ul>
		</div>
		<?php
    }

    private static function render_scope()
    {
        ?>
		<div class="sky-migrate-card">
			<h2><?php esc_html_e('What is copied', 'sky-migrate'); ?></h2>
			<ul class="sky-migrate-scope is-included">
				<li><?php esc_html_e('The database: posts, pages, users, settings and plugin data', 'sky-migrate'); ?></li>
				<li><?php esc_html_e('Themes, plugins and must-use plugins', 'sky-migrate'); ?></li>
				<li><?php esc_html_e('Uploads and other files in the website folder', 'sky-migrate'); ?></li>
			</ul>
			<h3><?php esc_html_e('What is not copied', 'sky-migrate'); ?></h3>
			<ul class="sky-migrate-scope is-excluded">
				<li><?php esc_html_e('wp-config.php, with this host’s passwords', 'sky-migrate'); ?></li>
				<li><?php esc_html_e('WordPress core files; your new website gets a fresh copy', 'sky-migrate'); ?></li>
				<li><?php esc_html_e('Caches, backups, logs and this host’s own plugins', 'sky-migrate'); ?></li>
			</ul>
		</div>
		<?php
    }

    /**
     * Sizes the website and checks that the export endpoints answer, for the
     * "This website" card.
     */
    public static function preflight()
    {
        check_ajax_referer('sky_migrate_preflight');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(null, 403);
        }

        $estimates = self::estimates();
        $rest = self::probe(rest_url(self::REST_NAMESPACE.'/info'));
        $ajax = self::probe(add_query_arg(['action' => self::AJAX_ACTION, 'endpoint' => 'info'], admin_url('admin-ajax.php')));

        if ($estimates['files_complete']) {
            /* translators: 1: number of files, 2: their total size, e.g. "12 MB". */
            $files = sprintf(__('%1$s files, %2$s', 'sky-migrate'), number_format_i18n($estimates['file_count']), size_format($estimates['file_bytes']));
        } else {
            /* translators: %s: number of files counted so far. */
            $files = sprintf(__('More than %s files', 'sky-migrate'), number_format_i18n($estimates['file_count']));
        }

        $checks = [
            [
                'ok' => $rest,
                'label' => $rest
                    ? __('The REST API answers', 'sky-migrate')
                    : __('The REST API is blocked', 'sky-migrate'),
            ],
            [
                'ok' => $ajax,
                'label' => $ajax
                    ? __('The admin-ajax fallback answers', 'sky-migrate')
                    : __('The admin-ajax fallback is blocked', 'sky-migrate'),
            ],
        ];

        if (! $rest && ! $ajax) {
            $checks[] = [
                'ok' => false,
                'label' => __('Skydun may not be able to read this website. Allow requests to the REST API or admin-ajax.php in your security plugin or firewall, then reload this page.', 'sky-migrate'),
            ];
        }

        wp_send_json_success(
            [
                'database' => size_format($estimates['db_bytes']),
                'files' => $files,
                'checks' => $checks,
            ]
        );
    }

    /**
     * Whether an unsigned request to an export endpoint reaches this plugin,
     * which then refuses it with one of its own error codes.
     */
    private static function probe($url)
    {
        $response = wp_remote_post(
            $url,
            [
                'timeout' => 10,
                'redirection' => 2,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '{}',
                'sslverify' => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's loopback filter.
            ]
        );

        if (is_wp_error($response)) {
            return false;
        }

        $reply = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($reply) && isset($reply['code']) && strpos((string) $reply['code'], self::ERROR_PREFIX) === 0;
    }

    /**
     * Store the key, then report this website to Skydun.
     */
    public static function connect()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'sky-migrate'), 403);
        }

        check_admin_referer('sky_migrate_connect');

        // Keys pasted from email or chat often pick up line breaks.
        $key = isset($_POST['migration_key']) ? preg_replace('/\s+/', '', sanitize_textarea_field(wp_unslash($_POST['migration_key']))) : '';
        $parsed = self::parse_key((string) $key);

        if ($parsed === null) {
            self::redirect_with('error', __('This is not a Skydun migration key. Copy it again from the migration page in Skydun.', 'sky-migrate'));
        }

        if (is_multisite()) {
            self::redirect_with('error', __('WordPress multisite networks cannot be migrated yet.', 'sky-migrate'));
        }

        update_option(self::OPTION, $parsed, false);

        $body = wp_json_encode(self::site_info(true));
        $url = $parsed['panel'].'/api/migrations/source/connect';
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $response = wp_remote_post(
            $url,
            [
                'timeout' => 30,
                'headers' => array_merge(
                    self::signature_headers('POST', $path, $body, $parsed['secret']),
                    [
                        'X-MWP-Key' => $parsed['token'],
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ]
                ),
                'body' => $body,
            ]
        );

        if (is_wp_error($response)) {
            self::forget();
            /* translators: %s: the connection error. */
            self::redirect_with('error', sprintf(__('Could not reach Skydun: %s', 'sky-migrate'), $response->get_error_message()));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $reply = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status !== 200 || ! is_array($reply)) {
            self::forget();
            $message = is_array($reply) && ! empty($reply['message']) && $status !== 403
                ? (string) $reply['message']
                : __('Skydun did not accept the key. Copy it again from the migration page in Skydun.', 'sky-migrate');
            self::redirect_with('error', $message);
        }

        $parsed['connected_at'] = time();
        $parsed['migration_url'] = isset($reply['migration_url']) ? esc_url_raw((string) $reply['migration_url']) : '';
        update_option(self::OPTION, $parsed, false);
        delete_option(self::FINISHED_OPTION);

        self::redirect_with('success', __('Connected. Skydun starts copying this website as soon as your new website is ready.', 'sky-migrate'));
    }

    public static function disconnect()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'sky-migrate'), 403);
        }

        check_admin_referer('sky_migrate_disconnect');
        self::forget();

        self::redirect_with('success', __('Disconnected. Skydun can no longer read this website.', 'sky-migrate'));
    }

    private static function redirect_with($type, $message)
    {
        set_transient('sky_migrate_notice_'.get_current_user_id(), ['type' => $type, 'message' => $message], 60);
        wp_safe_redirect(self::page_url());
        exit;
    }

    /**
     * mwp1_ + base64url(JSON {u: panel URL, k: source token, s: secret}).
     *
     * @return array{panel: string, token: string, secret: string}|null
     */
    private static function parse_key($key)
    {
        if (strpos($key, self::KEY_PREFIX) !== 0) {
            return null;
        }

        // The key is base64url-encoded JSON, so it survives copy and paste.
        $json = base64_decode(strtr(substr($key, strlen(self::KEY_PREFIX)), '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || ! isset($data['u'], $data['k'], $data['s'])) {
            return null;
        }

        $panel = untrailingslashit((string) $data['u']);
        $token = (string) $data['k'];
        $secret = (string) $data['s'];

        if (! preg_match('#^https?://[^\s/?\#]+(/[^\s?\#]*)?$#', $panel)
            || ! preg_match('/^[a-f0-9]{64}$/D', $token)
            || ! preg_match('/^[a-f0-9]{64}$/D', $secret)) {
            return null;
        }

        return [
            'panel' => $panel,
            'token' => $token,
            'secret' => $secret,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function config()
    {
        $config = get_option(self::OPTION);

        return is_array($config) ? $config : [];
    }

    // ------------------------------------------------------------------
    // Signed export endpoints
    // ------------------------------------------------------------------

    public static function register_routes()
    {
        register_rest_route(
            self::REST_NAMESPACE,
            '/(?P<endpoint>[a-z-]+)',
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'dispatch_rest'],
                // Public on purpose: Skydun calls these without a WordPress
                // login, and dispatch() refuses any request without a valid
                // HMAC signature made with the migration key's secret.
                'permission_callback' => '__return_true',
            ]
        );
    }

    public static function dispatch_rest($request)
    {
        self::dispatch((string) $request['endpoint']);
    }

    public static function dispatch_ajax()
    {
        self::dispatch(isset($_GET['endpoint']) ? sanitize_key(wp_unslash($_GET['endpoint'])) : ''); // phpcs:ignore WordPress.Security.NonceVerification -- authenticated by the HMAC signature in dispatch().
    }

    /**
     * Verify the signature, then answer one endpoint. Always exits.
     */
    private static function dispatch($endpoint)
    {
        if (! in_array($endpoint, self::ENDPOINTS, true)) {
            self::fail(404, 'unknown_endpoint', 'Unknown endpoint.');
        }

        $config = self::config();

        if (empty($config['secret']) || empty($config['connected_at'])) {
            self::fail(403, 'not_connected', 'This website is not connected to Skydun.');
        }

        // The raw body, since the signature covers its exact bytes.
        $body = (string) file_get_contents('php://input'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the request body, not a file.

        if (! self::verify('/'.self::REST_NAMESPACE.'/'.$endpoint, $body, (string) $config['secret'])) {
            self::fail(401, 'bad_signature', 'The request signature is invalid or expired.');
        }

        $input = json_decode($body, true);
        $input = is_array($input) ? $input : [];

        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- large exports; disabled on some hosts.
        }

        switch ($endpoint) {
            case 'info':
                self::json(self::site_info(false));
                break;
            case 'tables':
                self::json(['tables' => self::tables()]);
                break;
            case 'table-chunk':
                self::json(self::table_chunk($input));
                break;
            case 'files':
                self::json(self::file_page($input));
                break;
            case 'file-chunk':
                self::file_chunk($input);
                break;
            case 'finish':
                self::forget();
                update_option(
                    self::FINISHED_OPTION,
                    [
                        'at' => time(),
                        'migration_url' => isset($config['migration_url']) ? (string) $config['migration_url'] : '',
                    ],
                    false
                );
                self::json(['status' => 'finished']);
                break;
        }
    }

    /**
     * @return array<string, string>
     */
    private static function signature_headers($method, $path, $body, $secret)
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        return [
            'X-MWP-Timestamp' => $timestamp,
            'X-MWP-Nonce' => $nonce,
            'X-MWP-Signature' => self::sign($secret, $method, $path, $timestamp, $nonce, $body),
        ];
    }

    private static function sign($secret, $method, $path, $timestamp, $nonce, $body)
    {
        return hash_hmac('sha256', implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $body)]), $secret);
    }

    private static function request_header($name)
    {
        return isset($_SERVER[$name]) ? sanitize_text_field(wp_unslash($_SERVER[$name])) : '';
    }

    private static function verify($path, $body, $secret)
    {
        $timestamp = self::request_header('HTTP_X_MWP_TIMESTAMP');
        $nonce = self::request_header('HTTP_X_MWP_NONCE');
        $signature = self::request_header('HTTP_X_MWP_SIGNATURE');

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        if (! preg_match('/^[a-f0-9]{16,64}$/D', $nonce)) {
            return false;
        }

        $expected = self::sign($secret, 'POST', $path, $timestamp, $nonce, $body);

        if (! hash_equals($expected, $signature) || get_transient('sky_migrate_nonce_'.$nonce) !== false) {
            return false;
        }

        set_transient('sky_migrate_nonce_'.$nonce, 1, self::SIGNATURE_TOLERANCE * 2);

        return true;
    }

    private static function json($data, $status = 200)
    {
        self::clean_output();
        status_header($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo wp_json_encode($data);
        exit;
    }

    private static function fail($status, $code, $message)
    {
        self::json(
            [
                'code' => self::ERROR_PREFIX.$code,
                'message' => $message,
            ],
            $status
        );
    }

    private static function clean_output()
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    // ------------------------------------------------------------------
    // Website facts
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function site_info($with_estimates)
    {
        global $wpdb;

        $info = [
            'home' => home_url(),
            'siteurl' => site_url(),
            'blogname' => get_bloginfo('name'),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugin_version' => self::VERSION,
            'table_prefix' => $wpdb->prefix,
            'multisite' => is_multisite(),
        ];

        if (! $with_estimates) {
            return array_merge(
                $info,
                [
                    'charset' => $wpdb->charset,
                    'active_plugins' => array_values((array) get_option('active_plugins', [])),
                ]
            );
        }

        $estimates = self::estimates();
        $info['db_bytes'] = $estimates['db_bytes'];
        $info['file_count'] = $estimates['files_complete'] ? $estimates['file_count'] : null;
        $info['file_bytes'] = $estimates['files_complete'] ? $estimates['file_bytes'] : null;

        return $info;
    }

    /**
     * The database and file sizes, counting files for at most a few seconds.
     * Cached briefly, since the admin page and connect() both ask.
     *
     * @return array{db_bytes: int, file_count: int, file_bytes: int, files_complete: bool}
     */
    private static function estimates()
    {
        global $wpdb;

        $cached = get_transient(self::ESTIMATES_TRANSIENT);

        if (is_array($cached)) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- table sizes have no WordPress API.
        $db_bytes = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s',
                DB_NAME,
                $wpdb->esc_like($wpdb->prefix).'%'
            )
        );

        $count = 0;
        $bytes = 0;
        $deadline = microtime(true) + self::ESTIMATE_SECONDS;
        $complete = true;

        foreach (self::walk() as $absolute) {
            $count++;
            $bytes += self::size($absolute);

            if ($count % 200 === 0 && microtime(true) > $deadline) {
                $complete = false;
                break;
            }
        }

        $estimates = [
            'db_bytes' => $db_bytes,
            'file_count' => $count,
            'file_bytes' => $bytes,
            'files_complete' => $complete,
        ];

        set_transient(self::ESTIMATES_TRANSIENT, $estimates, 10 * MINUTE_IN_SECONDS);

        return $estimates;
    }

    /**
     * A file's size, or 0 when it vanished mid-walk.
     */
    private static function size($absolute)
    {
        clearstatcache(true, $absolute);
        $size = is_readable($absolute) ? filesize($absolute) : false;

        return $size === false ? 0 : (int) $size;
    }

    // ------------------------------------------------------------------
    // Database
    //
    // The export reads every table with this website's prefix, including
    // ones plugins created, so it queries $wpdb directly.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
    // ------------------------------------------------------------------

    /**
     * This website's tables (those with its prefix), with the statement that
     * recreates each under the wp_ prefix Skydun uses.
     *
     * @return list<array<string, mixed>>
     */
    private static function tables()
    {
        global $wpdb;

        $tables = [];

        foreach (self::table_names() as $name) {
            $create = $wpdb->get_row('SHOW CREATE TABLE `'.$name.'`', ARRAY_N); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $name comes from SHOW TABLES and has no backticks.
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $name), ARRAY_A);
            $target = self::target_table($name);
            $sql = is_array($create) ? (string) $create[1] : '';
            $sql = preg_replace('/^CREATE TABLE `'.preg_quote($name, '/').'`/', 'CREATE TABLE `'.$target.'`', $sql);
            $tables[] = [
                'name' => $name,
                'target' => $target,
                'rows' => is_array($status) ? (int) $status['Rows'] : 0,
                'bytes' => is_array($status) ? (int) $status['Data_length'] : 0,
                // Base64 so any bytes in the statement survive JSON.
                'create' => base64_encode((string) $sql),
            ];
        }

        return $tables;
    }

    /**
     * @return list<string>
     */
    private static function table_names()
    {
        global $wpdb;

        $names = [];
        $rows = $wpdb->get_results($wpdb->prepare('SHOW FULL TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix).'%'), ARRAY_N);

        foreach ((array) $rows as $row) {
            if (isset($row[1]) && $row[1] === 'BASE TABLE' && strpos($row[0], '`') === false) {
                $names[] = (string) $row[0];
            }
        }

        sort($names, SORT_STRING);

        return $names;
    }

    private static function target_table($name)
    {
        global $wpdb;

        return 'wp_'.substr($name, strlen($wpdb->prefix));
    }

    /**
     * Up to max_bytes of INSERT statements for one table, starting after the
     * cursor. Tables with a single integer primary key page by key; others by
     * offset. Binary columns are sent as hex so no charset conversion touches
     * them.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function table_chunk($input)
    {
        global $wpdb;

        $table = isset($input['table']) ? (string) $input['table'] : '';

        if (! in_array($table, self::table_names(), true)) {
            self::fail(422, 'unknown_table', 'Unknown table.');
        }

        $max_bytes = isset($input['max_bytes']) ? (int) $input['max_bytes'] : self::DEFAULT_CHUNK_BYTES;
        $max_bytes = max(65536, min(self::MAX_CHUNK_BYTES, $max_bytes));
        $columns = $wpdb->get_results('SHOW COLUMNS FROM `'.$table.'`', ARRAY_A);
        $names = [];
        $binary = [];
        $primary = [];

        foreach ((array) $columns as $index => $column) {
            $names[] = '`'.str_replace('`', '``', (string) $column['Field']).'`';

            if (preg_match('/blob|binary|bit\(/i', (string) $column['Type'])) {
                $binary[$index] = true;
            }

            if ($column['Key'] === 'PRI') {
                $primary[] = [
                    'name' => (string) $column['Field'],
                    'integer' => (bool) preg_match('/^(tiny|small|medium|big)?int\b/i', (string) $column['Type']),
                ];
            }
        }

        $key = count($primary) === 1 && $primary[0]['integer'] ? '`'.$primary[0]['name'].'`' : null;
        $after = isset($input['after']) && $input['after'] !== null ? (string) $input['after'] : null;
        $offset = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;
        $order = $primary ? ' ORDER BY '.implode(', ', array_map(function ($column) {
            return '`'.str_replace('`', '``', $column['name']).'`';
        }, $primary)) : '';

        if ($after !== null && ! preg_match('/^-?\d{1,20}$/D', $after)) {
            self::fail(422, 'bad_cursor', 'Invalid cursor.');
        }

        $target = self::target_table($table);
        $prefix = 'INSERT INTO `'.$target.'` ('.implode(',', $names).') VALUES ';
        $sql = '';
        $statement = '';
        $rows = 0;
        $last_key = $after;
        $key_index = null;
        $done = false;

        if ($key !== null) {
            foreach ((array) $columns as $index => $column) {
                if ('`'.$column['Field'].'`' === $key) {
                    $key_index = $index;
                }
            }
        }

        $full = false;

        while (! $full) {
            if ($key !== null) {
                $query = $last_key === null
                    ? "SELECT * FROM `{$table}` ORDER BY {$key} LIMIT ".self::ROW_BATCH
                    : $wpdb->prepare("SELECT * FROM `{$table}` WHERE {$key} > %s ORDER BY {$key} LIMIT ".self::ROW_BATCH, $last_key);
            } else {
                $query = "SELECT * FROM `{$table}`{$order} LIMIT ".($offset + $rows).', '.self::ROW_BATCH;
            }

            $batch = $wpdb->get_results($query, ARRAY_N); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table and key names validated against SHOW TABLES/COLUMNS, values prepared.

            if ($wpdb->last_error !== '') {
                self::fail(500, 'query_failed', 'Reading '.$table.' failed: '.$wpdb->last_error);
            }

            foreach ((array) $batch as $row) {
                $values = [];

                foreach ($row as $index => $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } elseif (isset($binary[$index])) {
                        $values[] = $value === '' ? "''" : '0x'.bin2hex($value);
                    } else {
                        $values[] = "'".$wpdb->remove_placeholder_escape($wpdb->_real_escape($value))."'";
                    }
                }

                $tuple = '('.implode(',', $values).')';

                if ($statement !== '' && strlen($statement) + strlen($tuple) > self::MAX_STATEMENT_BYTES) {
                    $sql .= $statement.";\n";
                    $statement = '';
                }

                $statement .= ($statement === '' ? $prefix : ',').$tuple;
                $rows++;

                if ($key_index !== null) {
                    $last_key = (string) $row[$key_index];
                }

                // A chunk always holds at least one row, however large.
                if (strlen($sql) + strlen($statement) >= $max_bytes) {
                    $full = true;
                    break;
                }
            }

            if (! $full && count((array) $batch) < self::ROW_BATCH) {
                $done = true;
                break;
            }
        }

        if ($statement !== '') {
            $sql .= $statement.";\n";
        }

        $next = null;

        if (! $done) {
            $next = $key !== null ? ['after' => $last_key] : ['offset' => $offset + $rows];
        }

        return [
            // Base64 so binary-safe SQL survives JSON.
            'sql' => base64_encode($sql),
            'rows' => $rows,
            'next' => $next,
        ];
    }

    // phpcs:enable

    // ------------------------------------------------------------------
    // Files
    //
    // Files are streamed straight from disk in bounded pieces; WP_Filesystem
    // would read each whole file into memory first.
    // phpcs:disable WordPress.WP.AlternativeFunctions
    // ------------------------------------------------------------------

    /**
     * The next page of files after the cursor, in a stable order, with sizes.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function file_page($input)
    {
        $after = isset($input['after']) ? (string) $input['after'] : '';
        $files = [];
        $last = null;
        $deadline = microtime(true) + self::LISTING_SECONDS;
        $done = true;

        foreach (self::walk($after) as $logical => $absolute) {
            $files[] = [
                'p' => $logical,
                's' => self::size($absolute),
            ];
            $last = $logical;

            if (count($files) >= self::MANIFEST_PAGE || microtime(true) > $deadline) {
                $done = false;
                break;
            }
        }

        return [
            'files' => $files,
            'next' => $done ? null : $last,
        ];
    }

    /**
     * Stream files: a JSON header line per file ({p, s, h} with the MD5, or
     * {p, e} when it cannot be read) followed by exactly s bytes. With
     * offset/length, only that range of one file, as raw bytes.
     *
     * @param  array<string, mixed>  $input
     */
    private static function file_chunk($input)
    {
        $paths = isset($input['files']) && is_array($input['files']) ? array_slice($input['files'], 0, self::MAX_BATCH_FILES) : [];

        self::clean_output();
        status_header(200);
        header('Content-Type: application/octet-stream');
        header('Cache-Control: no-store');

        if (isset($input['length']) && count($paths) === 1) {
            $absolute = self::resolve((string) $paths[0]);
            $handle = $absolute !== null && is_readable($absolute) ? fopen($absolute, 'rb') : false;

            if ($handle === false) {
                self::fail(404, 'missing_file', 'The file is gone.');
            }

            $length = max(0, min(self::MAX_CHUNK_BYTES, (int) $input['length']));
            fseek($handle, max(0, (int) (isset($input['offset']) ? $input['offset'] : 0)));
            self::stream($handle, $length, false);
            fclose($handle);
            exit;
        }

        foreach ($paths as $logical) {
            $logical = (string) $logical;
            $absolute = self::resolve($logical);
            $handle = $absolute !== null && is_readable($absolute) ? fopen($absolute, 'rb') : false;
            $stat = $handle !== false ? fstat($handle) : false;
            $hash = $handle !== false ? md5_file($absolute) : false;

            if ($stat === false || $hash === false) {
                if ($handle !== false) {
                    fclose($handle);
                }

                echo wp_json_encode(['p' => $logical, 'e' => 'missing'])."\n";

                continue;
            }

            $size = (int) $stat['size'];
            echo wp_json_encode(['p' => $logical, 's' => $size, 'h' => $hash])."\n";
            // Exactly $size bytes even if the file changes meanwhile, so the
            // files after it stay readable; the MD5 then flags this one.
            self::stream($handle, $size, true);
            fclose($handle);
        }

        exit;
    }

    /**
     * Echo up to $length bytes from $handle, padding with NUL bytes to
     * exactly $length when $pad is set and the file ends early.
     *
     * @param  resource  $handle
     */
    private static function stream($handle, $length, $pad)
    {
        while ($length > 0 && ! feof($handle)) {
            $data = fread($handle, min(65536, $length));

            if ($data === false || $data === '') {
                break;
            }

            echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw file bytes for Skydun, not HTML.
            $length -= strlen($data);
        }

        while ($pad && $length > 0) {
            $piece = min(65536, $length);
            echo str_repeat("\0", $piece); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- padding bytes.
            $length -= $piece;
        }
    }

    // phpcs:enable

    /**
     * The file behind a logical path, or null when the path is not one this
     * plugin would list (outside the website, excluded, or a symlink).
     */
    private static function resolve($logical)
    {
        if ($logical === '' || strpos($logical, "\0") !== false || strpos($logical, '\\') !== false) {
            return null;
        }

        $segments = explode('/', $logical);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        for ($depth = 1; $depth <= count($segments); $depth++) {
            if (self::is_excluded(implode('/', array_slice($segments, 0, $depth)))) {
                return null;
            }
        }

        $roots = self::root_entries();

        if (! isset($roots[$segments[0]])) {
            return null;
        }

        $absolute = $roots[$segments[0]].(count($segments) > 1 ? '/'.implode('/', array_slice($segments, 1)) : '');
        $real = realpath($absolute);
        $base = realpath($roots[$segments[0]]);

        if ($real === false || $base === false || is_link($absolute) || ! is_file($real)) {
            return null;
        }

        if ($real !== $base && strpos($real, rtrim($base, '/').'/') !== 0) {
            return null;
        }

        return $real;
    }

    /**
     * Every file to copy after $after, as logical path => absolute path, in
     * depth-first order with each directory sorted byte-wise, which is the
     * segment-wise order the cursor is compared in. The content directory is
     * always listed as wp-content, wherever WP_CONTENT_DIR points.
     *
     * @return Generator<string, string>
     */
    private static function walk($after = '')
    {
        return self::walk_entries('', self::root_entries(), $after === '' ? [] : explode('/', $after));
    }

    /**
     * @return array<string, string>
     */
    private static function root_entries()
    {
        $root = untrailingslashit(ABSPATH);
        $content = untrailingslashit(WP_CONTENT_DIR);
        $entries = self::list_directory($root);

        unset($entries['wp-content']);
        $entries['wp-content'] = $content;
        ksort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * @return array<string, string>
     */
    private static function list_directory($directory)
    {
        // Unreadable directories are skipped.
        $names = is_readable($directory) ? scandir($directory) : false;
        $entries = [];

        foreach (is_array($names) ? $names : [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $entries[(string) $name] = $directory.'/'.$name;
            }
        }

        ksort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * @param  array<string, string>  $entries
     * @param  list<string>  $after
     * @return Generator<string, string>
     */
    private static function walk_entries($prefix, $entries, $after)
    {
        $content = realpath(WP_CONTENT_DIR);

        foreach ($entries as $name => $absolute) {
            $logical = $prefix === '' ? (string) $name : $prefix.'/'.$name;

            if (self::is_excluded($logical) || is_link($absolute)) {
                continue;
            }

            $segments = explode('/', $logical);

            if (is_dir($absolute)) {
                // The content directory is listed once, as wp-content.
                if ($logical !== 'wp-content' && $content !== false && realpath($absolute) === $content) {
                    continue;
                }

                if ($after && self::compare($segments, $after) < 0 && ! self::is_prefix($segments, $after)) {
                    continue;
                }

                foreach (self::walk_entries($logical, self::list_directory($absolute), $after) as $path => $file) {
                    yield $path => $file;
                }
            } elseif (is_file($absolute)) {
                if ($after && self::compare($segments, $after) <= 0) {
                    continue;
                }

                yield $logical => $absolute;
            }
        }
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function compare($a, $b)
    {
        $length = min(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            $result = strcmp($a[$i], $b[$i]);

            if ($result !== 0) {
                return $result;
            }
        }

        return count($a) - count($b);
    }

    /**
     * @param  list<string>  $prefix
     * @param  list<string>  $path
     */
    private static function is_prefix($prefix, $path)
    {
        return count($prefix) < count($path) && array_slice($path, 0, count($prefix)) === $prefix;
    }

    /**
     * This plugin's own folder, whatever it was installed as.
     */
    private static function own_path()
    {
        static $path = null;

        if ($path === null) {
            $folder = dirname(plugin_basename(__FILE__));
            $path = $folder !== '.' ? 'wp-content/plugins/'.$folder : '';
        }

        return $path;
    }

    private static function is_excluded($logical)
    {
        if (in_array($logical, self::EXCLUDED, true) || in_array(basename($logical), self::EXCLUDED_NAMES, true)) {
            return true;
        }

        if ($logical === self::own_path()) {
            return true;
        }

        foreach (self::EXCLUDED_PATTERNS as $pattern) {
            if (fnmatch($pattern, $logical, FNM_PATHNAME)) {
                return true;
            }
        }

        return false;
    }
}

Sky_Migrate::boot();

register_activation_hook(__FILE__, ['Sky_Migrate', 'activate']);
// Deactivating the plugin disconnects the website.
register_deactivation_hook(__FILE__, ['Sky_Migrate', 'forget']);
register_uninstall_hook(__FILE__, ['Sky_Migrate', 'uninstall']);
