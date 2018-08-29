<?php
/**
 * wp-cli gic
 *
 * @version 0.0.1
 * @author Paolo Tresso <plugins@swergroup.com>
 */

class WP_CLI_Juslintek_GIC_Command extends WP_CLI_Command
{

	private $version = '0.1.0';
	private $gravatar_cache_timeout = 7 * 24 * 60 * 60;
	private $finfo = null;
	private $table_name = null;
	private $gravatars_cache_dir = null;
	private $gravatars_cache_url = null;
	private $gravatar_sizes = [
		65, 130, 72, 144
	];


	/**
	 * Do something.
	 *
	 * ## OPTIONS
	 *
	 * [--flags=<flags>]
	 * : additional commandline flags
	 *
	 * ## EXAMPLES
	 *
	 * wp gic generate
	 *
	 * @since 0.0.1
	 * @when before_wp_load
	 * @synopsis [--flags=<flags>]
	 */
	public function __invoke($args = null, $assoc_args = null)
	{

		$time_start = microtime(true);

		$this->finfo = new finfo();

		$comments = get_comments([
			'no_found_rows' => false,
			'update_comment_meta_cache' => false,
			'update_comment_post_cache' => false,
			'post_type' => 'hosting_providers',
			'orderby' => 'comment_date',
			'order' => 'DESC',
			'status' => 'approve',
			'post_status' => 'publish'
		]);

		$users = get_users();

		$all_emails = [];

		foreach ($comments as $comment) {
			/**
			 * @var WP_Comment $comment
			 */
			if (!in_array($comment->comment_author_email, $all_emails)) {
				array_push($all_emails, $comment->comment_author_email);
			}
		}

		foreach ($users as $user) {
			/**
			 * @var WP_User $user
			 */
			if (!in_array($user->user_email, $all_emails)) {
				array_push($all_emails, $user->user_email);
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

		global $wpdb;

		$this->table_name = $wpdb->prefix . 'avatars_cache';

		if ($wpdb->get_var("SHOW TABLES LIKE '$this->table_name'") != $this->table_name) {

			$charset_collate = $wpdb->get_charset_collate();

			$create_table_query = "CREATE TABLE `{$this->table_name}` (
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

		$upload_dir = wp_get_upload_dir();
		$this->gravatars_cache_dir = trailingslashit($upload_dir['basedir'] . '/gravatars_cache');
		$this->gravatars_cache_url = trailingslashit($upload_dir['baseurl'] . '/gravatars_cache');

		if ($wp_filesystem !== null && !$wp_filesystem->is_dir($this->gravatars_cache_dir)) {
			wp_mkdir_p($this->gravatars_cache_dir);
		}

		$this->prefetch_default_avatar();

		$otal_emails = count($all_emails);
		WP_CLI::line('Total unique emails found: ' . $otal_emails);
		foreach ($all_emails as $email) {
			$avatar_id = md5($email);
			WP_CLI::line('Email processing: ' . $email);
			$this->save_images($avatar_id);
			WP_CLI::line('Email processing done: ' . $email);
			WP_CLI::line('Emails left to process: ' . $otal_emails--);
		}

		$time_end = microtime(true);
		$time = $time_end - $time_start;
		WP_CLI::log( 'Execution time : '.$time.' seconds' );
	}

	/**
	 * @param $size
	 *
	 * @return array|string|WP_Error
	 */
	private function prefetch_default_avatar()
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


		foreach ($this->gravatar_sizes as $size) {
			$default_image_path = $this->gravatars_cache_dir . '404_' . $size . '.jpg';


			if (!$wp_filesystem->is_file($default_image_path)) {
				$default_image = wp_remote_get('https://secure.gravatar.com/avatar/?d=' . $default . '&s=' . $size);
				$body = wp_remote_retrieve_body($default_image);
				$status = wp_remote_retrieve_response_code($default_image);
				$message = wp_remote_retrieve_response_message($default_image);

				WP_CLI::debug('Status: ' . $status);
				WP_CLI::debug('Message: ' . $message);

				if ($this->is_image($body)) {
					if ($wp_filesystem->put_contents($default_image_path, $body)) {
						WP_CLI::success('File saved as: ' . $default_image_path);
					} else {
						WP_CLI::warning('Was not able to save file as: ' . $default_image_path);
					}
				}
			} else {
				$default_image_url = $this->gravatars_cache_url . '404_' . $size . '.jpg';
				WP_CLI::warning('Image ' . $default_image_path . ' already exists. Check it here: ' . $default_image_url);
			}
		}
	}

	private function is_image($content)
	{
		$mime_type = $this->finfo->buffer($content, FILEINFO_MIME_TYPE);

		return strpos($mime_type, 'image/') !== false;
	}

	/**
	 * @param string $gravatar_id
	 */
	private function save_images($gravatar_id)
	{
		/**
		 * @var WP_Filesystem_Direct $wp_filesystem
		 */
		global $wp_filesystem;

		foreach ($this->gravatar_sizes as $size) {
			$avatar_file_path = $this->gravatars_cache_dir . $gravatar_id . '_' . $size . '.jpg';

			if (($avatar = $this->get_from_cache($gravatar_id, $size)) !== null && $wp_filesystem->is_file($avatar_file_path) && (time() - filemtime($avatar_file_path)) < $this->gravatar_cache_timeout) {
				WP_CLI::warning('Image is already cached and up to date');
				continue;
			}

			if (!$wp_filesystem->is_file($avatar_file_path) || (time() - filemtime($avatar_file_path)) > $this->gravatar_cache_timeout) {

				$avatar_image = wp_remote_get('https://secure.gravatar.com/avatar/' . $gravatar_id . '?d=404&s=' . $size);
				$body = wp_remote_retrieve_body($avatar_image);
				$status = wp_remote_retrieve_response_code($avatar_image);
				$message = wp_remote_retrieve_response_message($avatar_image);

				WP_CLI::debug('Url Requests: https://secure.gravatar.com/avatar/' . $gravatar_id . '?d=404&s=' . $size);
				WP_CLI::debug('Status: ' . $status);
				WP_CLI::debug('Message: ' . $message);

				if ($this->is_image($body)) {
					$avatar_file_url = $this->gravatars_cache_url . $gravatar_id . '_' . $size . '.jpg';
					$this->update_requests_cache($gravatar_id, $size, $avatar_file_url);
					if ($wp_filesystem->put_contents($avatar_file_path, $body)) {
						WP_CLI::success('File saved as: ' . $avatar_file_path);
					} else {
						WP_CLI::warning('Was not able to save file as: ' . $avatar_file_path);
					}
				} else {
					$avatar_file_url = $this->gravatars_cache_url . '404_' . $size . '.jpg';
					WP_CLI::warning('Response not image, using default same sized image instead: ' . $avatar_file_url);

					$this->update_requests_cache($gravatar_id, $size, $avatar_file_url);
				}
			}
		}
	}

	/**
	 * @param $avatar_id
	 * @param $size
	 *
	 * @return bool
	 */
	private function update_requests_cache($avatar_id, $size, $avatar_url)
	{
		global $wpdb;

		$fetch_gravatar_query = "SELECT * FROM {$wpdb->prefix}avatars_cache WHERE avatar_id = %s AND size = %d";
		$avatar_cache = $wpdb->get_row($wpdb->prepare($fetch_gravatar_query, $avatar_id, $size));

		if ($avatar_cache == null) {
			if ($wpdb->insert($wpdb->prefix . 'avatars_cache', [
				'avatar_id' => $avatar_id,
				'size' => $size,
				'url' => $avatar_url,
			], [
				'%s',
				'%d',
				'%s'
			])) {
				WP_CLI::success('Request entry inserted width url: ' . $avatar_url);
			} else {
				WP_CLI::warning('Request entry could not be inserted with url: ' . $avatar_url);
			}
		} else if (time() - strtotime($avatar_cache->updated) > $this->gravatar_cache_timeout) {
			if ($wpdb->update($wpdb->prefix . 'avatars_cache', [
				'url' => $avatar_url
			], [
				'avatar_id' => $avatar_id,
				'size' => $size
			], [
				'%s'
			], [
				'%s',
				'%d'
			])) {
				WP_CLI::success('Request entry updated width url: ' . $avatar_url);
			} else {
				WP_CLI::warning('Request entry could not be updated with url: ' . $avatar_url);
			}
		}
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
	 * Return command version
	 *
	 * @since 0.0.1
	 * @when before_wp_load
	 */
	public function version()
	{
		WP_CLI::line('wp-cli gic ' . $this->version);
	}
}

WP_CLI::add_command('gic', 'WP_CLI_Juslintek_GIC_Command');
