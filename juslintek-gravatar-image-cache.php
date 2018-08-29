<?php
/**
 * Plugin Name:     Juslintek Gravatar Image Cache
 * Plugin URI:      PLUGIN SITE HERE
 * Description:     PLUGIN DESCRIPTION HERE
 * Author:          YOUR NAME HERE
 * Author URI:      YOUR SITE HERE
 * Text Domain:     juslintek-gravatar-image-cache
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Juslintek_Gravatar_Image_Cache
 */

if (!defined('WPINC')) {
	die;
}

define('JUSLINTEK_GRAVATAR_IMAGE_CACHE', '0.1.0');

if ( defined( 'WP_CLI' ) && WP_CLI && ! class_exists( 'WP_CLI_Juslintek_GIC_Command' ) ) {
	require_once dirname( __FILE__ ) . '/cli.php';
}

function juslintek_gic_load_textdomain($locale = null)
{
	global $l10n;
	$domain = 'juslintek-gic';

	if (get_locale() == $locale) {
		$locale = null;
	}

	if (empty($locale)) {
		if (is_textdomain_loaded($domain)) {
			return true;
		} else {
			return load_plugin_textdomain($domain, false, $domain . '/languages');
		}
	} else {
		$mo_orig = $l10n[$domain];
		unload_textdomain($domain);

		$mofile = $domain . '-' . $locale . '.mo';
		$path = WP_PLUGIN_DIR . '/' . $domain . '/languages';

		if ($loaded = load_textdomain($domain, $path . '/' . $mofile)) {
			return $loaded;
		} else {
			$mofile = WP_LANG_DIR . '/plugins/' . $mofile;
			return load_textdomain($domain, $mofile);
		}

		$l10n[$domain] = $mo_orig;
	}

	return false;
}

function juslintek_gic_activate()
{

	global $wpdb;

	$table_name = $wpdb->prefix . 'avatars_cache';

	juslintek_gic_load_textdomain();

	if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {

		$charset_collate = $wpdb->get_charset_collate();

		$create_table_query = "CREATE TABLE `{$table_name}` (
              `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
              `avatar_id` VARCHAR(255),
              `size` SMALLINT UNSIGNED,
              `url` TEXT NOT NULL,
              `updated` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              INDEX(avatar_id)
            ) ENGINE=InnoDB $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		try {
			dbDelta($create_table_query);
		} catch (Exception $e) {
			wp_die("<pre>" . print_r($e, true) . "</pre>");
		}
	}

	/**
	 * @var WP_Filesystem_Direct $wp_filesystem
	 */
	global $wp_filesystem;
	if (!$wp_filesystem) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$accessType = get_filesystem_method();
		if ($accessType === 'direct') {
			/* you can safely run request_filesystem_credentials() without any issues and don't need to worry about passing in a URL */
			$creds = request_filesystem_credentials(site_url() . '/wp-admin/', '', false, false, array());

			/* initialize the API */
			if (!WP_Filesystem($creds)) {
				/* any problems and we exit */
				return false;
			}
		}
	}

	$upload_dir = wp_get_upload_dir();
	$gravatars_cache_dir = trailingslashit($upload_dir['basedir'] . '/gravatars_cache');

	if ($wp_filesystem !== null && !$wp_filesystem->is_dir($gravatars_cache_dir)) {
		wp_mkdir_p($gravatars_cache_dir);
	}
}

function juslintek_gic_dectivate()
{

	global $wpdb;

	$table_name = $wpdb->prefix . 'avatars_cache';

	juslintek_gic_load_textdomain();

	if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {

		$create_table_query = "DROP TABLE `{$table_name}`;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		try {
			dbDelta($create_table_query);
		} catch (Exception $e) {
			wp_die("<pre>" . print_r($e, true) . "</pre>");
		}
	}

	/**
	 * @var WP_Filesystem_Direct $wp_filesystem
	 */
	global $wp_filesystem;
	if (!$wp_filesystem) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$accessType = get_filesystem_method();
		if ($accessType === 'direct') {
			/* you can safely run request_filesystem_credentials() without any issues and don't need to worry about passing in a URL */
			$creds = request_filesystem_credentials(site_url() . '/wp-admin/', '', false, false, array());

			/* initialize the API */
			if (!WP_Filesystem($creds)) {
				/* any problems and we exit */
				return false;
			}
		}
	}

	$upload_dir = wp_get_upload_dir();

	$gravatars_cache_dir = trailingslashit($upload_dir['basedir'] . '/gravatars_cache');

	if ($wp_filesystem !== null && $wp_filesystem->is_dir($gravatars_cache_dir)) {
		$wp_filesystem->rmdir($gravatars_cache_dir, true);
	}
}

register_activation_hook(__FILE__, 'juslintek_gic_activate');
register_deactivation_hook(__FILE__, 'juslintek_gic_dectivate');

add_action('init', function () {
	juslintek_gic_load_textdomain();
});

/**
 * Class Gravatar_Image_Cache
 */
class JuslintekGravatarImageCache
{

	public static $_instance;
	public $finfo = null;
	public $upload_dir = null;
	private $gravatar_cache_timeout = 7 * 24 * 60 * 60;
	private $gravatars_cache_dir = null;
	private $gravatars_cache_url = null;
	private $table_created = null;
	private $table_name = null;


	/**
	 * Gravatar_Image_Cache constructor.
	 *
	 * @param int $columns
	 * @param array $specific_comments
	 */
	public function __construct()
	{
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'avatars_cache';

		add_filter('get_avatar_url', [$this, 'cache_gravatars'], 10, 3);

		$this->finfo = new finfo();
		$this->upload_dir = wp_get_upload_dir();

		$this->gravatars_cache_dir = trailingslashit($this->upload_dir['basedir'] . '/gravatars_cache');
		$this->gravatars_cache_url = trailingslashit($this->upload_dir['baseurl'] . '/gravatars_cache');
	}

	/**
	 * @param int $columns
	 * @param array $specific_comments
	 *
	 * @return Gravatar_Image_Cache
	 */
	public static function getInstance()
	{
		if (null !== static::$_instance) {
			return static::$_instance;
		}

		static::$_instance = new static();

		return static::$_instance;
	}

	private function is_image($content)
	{
		$mime_type = $this->finfo->buffer($content, FILEINFO_MIME_TYPE);

		return strpos($mime_type, 'image/') !== false;
	}

	/**
	 * @param $avatar_id
	 * @param $size
	 *
	 * @return bool
	 */
	private function update_requests_cache($avatar_id, $size, $avatar_url)
	{
		/**
		 * @var WP_Filesystem_Direct $wp_filesystem
		 */
		global $wpdb;

		$fetch_gravatar_query = "SELECT * FROM {$wpdb->prefix}avatars_cache WHERE avatar_id = %s AND size = %d";
		$avatar_cache = $wpdb->get_row($wpdb->prepare($fetch_gravatar_query, $avatar_id, $size));

		if ($avatar_cache == null) {
			$wpdb->insert($wpdb->prefix . 'avatars_cache', [
				'avatar_id' => $avatar_id,
				'size' => $size,
				'url' => $avatar_url,
			], [
				'%s',
				'%d',
				'%s'
			]);
		} else if (time() - strtotime($avatar_cache->updated) > $this->gravatar_cache_timeout) {
			$wpdb->update($wpdb->prefix . 'avatars_cache', [
				'url' => $avatar_url
			], [
				'avatar_id' => $avatar_id,
				'size' => $size
			], [
				'%s'
			], [
				'%s',
				'%d'
			]);
		}

		return $avatar_url;
	}

	/**
	 * @param $avatar_id
	 * @param $size
	 *
	 * @return array|null|object
	 */
	private function get_from_cache($avatar_id, $size)
	{
		global $wpdb;

		$fetch_gravatar_query = "SELECT * FROM {$wpdb->prefix}avatars_cache WHERE avatar_id = %s AND size = %d";
		$avatar_cache = $wpdb->get_row($wpdb->prepare($fetch_gravatar_query, $avatar_id, $size));

		return $avatar_cache;
	}

	/**
	 * @param $size
	 *
	 * @return array|string|WP_Error
	 */
	private function prefetch_default_avatar($size)
	{
		/**
		 * @var WP_Filesystem_Direct $wp_filesystem
		 */
		global $wp_filesystem;
		$default = get_option('avatar_default', 'mystery');

		switch ($default) {
			case 'mm' :
			case 'mystery' :
			case 'mysteryman' :
				$default = 'mm';
				break;
			case 'gravatar_default' :
				$default = false;
				break;
		}

		$default_image_path = $this->gravatars_cache_dir . '404_' . $size . '.jpg';
		$default_image_url = $this->gravatars_cache_url . '404_' . $size . '.jpg';


		if (!$wp_filesystem->is_file($default_image_path)) {
			$default_image = wp_remote_get('https://secure.gravatar.com/avatar/?d=' . $default . '&s=' . $size);
			$body = wp_remote_retrieve_body($default_image);
			$status = wp_remote_retrieve_response_code($default_image);
			$message = wp_remote_retrieve_response_message($default_image);

			if (is_wp_error($default_image)) {
				return $default_image;
			} else if ($status == 404) {
				return new WP_Error($status, $message ?: $body, $default_image);
			} else if ($this->is_image($body)) {
				$wp_filesystem->put_contents($default_image_path, $body);
			}
		}

		return $default_image_url;
	}

	/**
	 * @param $url
	 * @param $id_email
	 * @param $args
	 *
	 * @return array|string|WP_Error
	 */
	public function cache_gravatars($url, $id_email, $args)
	{
		/**
		 * @var WP_Filesystem_Direct $wp_filesystem
		 */
		global $wp_filesystem;

		$size = $args['size'];
		if (is_integer($id_email)) {
			$gravatar_id = md5(get_userdata($id_email)->user_email);
		} else if (is_object($id_email) && $id_email instanceof WP_Comment) {
			$gravatar_id = md5($id_email->comment_author_email);
		} else {
			$gravatar_id = md5($id_email);
		}

		$avatar_file_path = $this->gravatars_cache_dir . $gravatar_id . '_' . $size . '.jpg';

		if (($avatar = $this->get_from_cache($gravatar_id, $size)) !== null && $wp_filesystem->is_file($avatar_file_path)) {
			$default_404 = $this->prefetch_default_avatar($size);

			if (
				$args['default'] == '404' &&
				$avatar->url == $default_404
			) {
				return false;
			}

			return $avatar->url;
		}

		$avatar_url = $this->gravatars_cache_url . $gravatar_id . '_' . $size . '.jpg';

		if (!$wp_filesystem->is_file($avatar_file_path) || (time() - filemtime($avatar_file_path)) > $this->gravatar_cache_timeout) {

			$avatar_image = wp_remote_get($url);
			$body = wp_remote_retrieve_body($avatar_image);
			$status = wp_remote_retrieve_response_code($avatar_image);
			$message = wp_remote_retrieve_response_message($avatar_image);

			if (is_wp_error($avatar_image)) {
				return $avatar_image;
			} else if ($status == 404) {
				$avatar_url = $this->prefetch_default_avatar($size);
				if ($args['default'] == '404') {
					$this->update_requests_cache($gravatar_id, $size, $avatar_url);

					return new WP_Error($status, $message || $body, $avatar_image);
				}
			} else if ($this->is_image($body)) {
				$wp_filesystem->put_contents($avatar_file_path, $body);
			}
		}

		return $this->update_requests_cache($gravatar_id, $size, $avatar_url);
	}
}


/**
 * Initialize Plugin
 *
 * @since 0.8.0
 */
function juslintek_gic_init()
{
	/**
	 * @var WP_Filesystem_Direct $wp_filesystem
	 */
	global $wp_filesystem;
	if (!$wp_filesystem) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$accessType = get_filesystem_method();
		if ($accessType === 'direct') {
			/* you can safely run request_filesystem_credentials() without any issues and don't need to worry about passing in a URL */
			$creds = request_filesystem_credentials(site_url() . '/wp-admin/', '', false, false, array());

			/* initialize the API */
			if (!WP_Filesystem($creds)) {
				/* any problems and we exit */
				return false;
			}
		}
	}

	JuslintekGravatarImageCache::getInstance();
}

add_action('plugins_loaded', 'juslintek_gic_init');
