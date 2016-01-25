<?php
/**
 * Plugin Name: Auto Load Next Post Beta Tester
 * Plugin URI: https://github.com/seb86/Auto-Load-Next-Post-Beta-Tester
 * Description: Run bleeding edge versions of Auto Load Next Post from Github. This will replace your installed version of Auto Load Next Post with the latest tagged release on Github - use with caution, and not on production sites. You have been warned.
 * Version: 1.0.1
 * Author: Sebastien Dumont
 * Author URI: http://sebastiendumont.com
 * Requires at least: 4.2
 * Tested up to: 4.4.1
 *
 * Based on WP_GitHub_Updater by Joachim Kudish.
 * Forked from WooCommerce Beta Tester by Mike Jolly and Claudio Sanches.
 */
if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * Confirm Auto Load Next Post is at least installed before doing anything
 * Curiously, developers are discouraged from using WP_PLUGIN_DIR and not given a
 * function with which to get the plugin directory, so this is what we have to do
 */
if ( ! file_exists( trailingslashit( dirname( dirname( __FILE__ ) ) ) . 'auto-load-next-post/auto-load-next-post.php') ) :
	add_action('admin_notices', 'alnpbt_auto_load_next_post_not_installed');

elseif ( ! class_exists('Auto_Load_Next_Post_Beta_Tester') ) :

	/**
	 * Auto_Load_Next_Post_Beta_Tester Main Class
	 */
	class Auto_Load_Next_Post_Beta_Tester {

		/** Config */
		private $config = array();

		/** Github Data */
		protected static $_instance = null;

		/**
		 * Main Instance
		 */
		public static function instance() {
			return self::$_instance = is_null( self::$_instance ) ? new self() : self::$_instance;
		}

		/**
		 * Ran on activation to flush update cache
		 */
		public static function activate() {
			delete_site_transient('update_plugins');
			delete_site_transient('auto_load_next_post_latest_tag');
		}

		/**
		 * Constructor
		 */
		public function __construct() {
			$this->config = array(
				'plugin_file'        => 'auto-load-next-post/auto-load-next-post.php',
				'slug'               => 'auto-load-next-post',
				'proper_folder_name' => 'auto-load-next-post',
				'api_url'            => 'https://api.github.com/repos/seb86/auto-load-next-post',
				'github_url'         => 'https://github.com/seb86/Auto-Load-Next-Post',
				'requires'           => '4.2',
				'tested'             => '4.4.1'
			);
			add_filter('pre_set_site_transient_update_plugins', array($this, 'api_check'));
			add_filter('plugins_api', array($this, 'get_plugin_info'), 10, 3);
			add_filter('upgrader_source_selection', array($this, 'upgrader_source_selection'), 10, 3);
		}

		/**
		 * Update args
		 * @return array
		 */
		public function set_update_args() {
			$plugin_data                    = $this->get_plugin_data();
			$this->config[ 'plugin_name' ]  = $plugin_data['Name'];
			$this->config[ 'version' ]      = $plugin_data['Version'];
			$this->config[ 'author' ]       = $plugin_data['Author'];
			$this->config[ 'homepage' ]     = $plugin_data['PluginURI'];
			$this->config[ 'new_version' ]  = $this->get_latest_tag();
			$this->config[ 'last_updated' ] = $this->get_date();
			$this->config[ 'description' ]  = $this->get_description();
			$this->config[ 'zip_url' ]      = 'https://github.com/seb86/auto-load-next-post/zipball/' . $this->config[ 'new_version' ];
		}

		/**
		 * Check wether or not the transients need to be overruled and API needs to be called for every single page load
		 *
		 * @return bool overrule or not
		 */
		public function overrule_transients() {
			return ( defined( 'ALNP_BETA_TESTER_FORCE_UPDATE' ) && ALNP_BETA_TESTER_FORCE_UPDATE );
		}

		/**
		 * Get New Version from GitHub
		 *
		 * @version 1.0.1
		 * @return int $version the version number
		 */
		public function get_latest_tag() {
			$tagged_version = get_site_transient( md5( $this->config['slug'] ) . '_latest_tag' );

			if ( $this->overrule_transients() || empty( $tagged_version ) ) {

				$raw_response = wp_remote_get( trailingslashit( $this->config['api_url'] ) . 'releases' );

				if ( is_wp_error( $raw_response ) ) {
					return false;
				}

				$releases       = json_decode( $raw_response['body'] );
				$tagged_version = false;

				if ( is_array( $releases ) ) {
					foreach ( $releases as $release ) {
						if ( $release->prerelease ) {
							$tagged_version = $release->tag_name;
							break;
						}
					}
				}

				/*$tags = json_decode( $raw_response['body'] );

				if ( is_array( $tags ) ) {
					$latest_tag     = $tags[0];
					$tagged_version = $latest_tag->name;
				}*/

				// refresh every 6 hours
				if ( ! empty( $tagged_version ) ) {
					set_site_transient( md5( $this->config['slug'] ) . '_latest_tag', $tagged_version, 60*60*6 );
				}
			}

			return $tagged_version;
		}

		/**
		 * Get GitHub Data from the specified repository
		 *
		 * @since 1.0.0
		 * @return array $github_data the data
		 */
		public function get_github_data() {
			if ( ! empty( $this->github_data ) ) {
				$github_data = $this->github_data;
			} else {
				$github_data = get_site_transient( md5( $this->config['slug'] ) . '_github_data' );

				if ( $this->overrule_transients() || ( ! isset( $github_data ) || ! $github_data || '' == $github_data ) ) {
					$github_data = wp_remote_get( $this->config['api_url'] );

					if ( is_wp_error( $github_data ) ) {
						return false;
					}

					$github_data = json_decode( $github_data['body'] );

					// refresh every 6 hours
					set_site_transient( md5( $this->config['slug'] ) . '_github_data', $github_data, 60*60*6 );
				}

				// Store the data in this class instance for future calls
				$this->github_data = $github_data;
			}

			return $github_data;
		}
		/**
		 * Get update date
		 *
		 * @since 1.0.0
		 * @return string $date the date
		 */
		public function get_date() {
			$_date = $this->get_github_data();
			return ! empty( $_date->updated_at ) ? date( 'Y-m-d', strtotime( $_date->updated_at ) ) : false;
		}

		/**
		 * Get plugin description
		 *
		 * @since 1.0.0
		 * @return string $description the description
		 */
		public function get_description() {
			$_description = $this->get_github_data();
			return ! empty( $_description->description ) ? $_description->description : false;
		}

		/**
		 * Get Plugin data
		 *
		 * @since 1.0.0
		 * @return object $data the data
		 */
		public function get_plugin_data() {
			return get_plugin_data( WP_PLUGIN_DIR . '/' . $this->config['plugin_file'] );
		}

		/**
		 * Hook into the plugin update check and connect to GitHub
		 *
		 * @since 1.0.0
		 * @param object  $transient the plugin data transient
		 * @return object $transient updated plugin data transient
		 */
		public function api_check( $transient ) {
			// Check if the transient contains the 'checked' information
			// If not, just return its value without hacking it
			if ( empty( $transient->checked ) ) {
				return $transient;
			}

			// Clear our transient
			delete_site_transient( md5( $this->config['slug'] ) . '_latest_tag' );

			// Update tags
			$this->set_update_args();

			// check the version and decide if it's new
			$update = version_compare( $this->config['new_version'], $this->config['version'], '>' );

			if ( $update ) {
				$response              = new stdClass;
				$response->plugin      = $this->config['slug'];
				$response->new_version = $this->config['new_version'];
				$response->slug        = $this->config['slug'];
				$response->url         = $this->config['github_url'];
				$response->package     = $this->config['zip_url'];

				// If response is false, don't alter the transient
				if ( false !== $response ) {
					$transient->response[ $this->config['plugin_file'] ] = $response;
				}
			}

			return $transient;
		}

		/**
		 * Get Plugin info
		 *
		 * @since 1.0.0
		 * @param bool    $false  always false
		 * @param string  $action the API function being performed
		 * @param object  $args   plugin arguments
		 * @return object $response the plugin info
		 */
		public function get_plugin_info( $false, $action, $response ) {
			// Check if this call API is for the right plugin
			if ( ! isset( $response->slug ) || $response->slug != $this->config['slug'] ) {
				return false;
			}

			// Update tags
			$this->set_update_args();

			$response->slug          = $this->config['slug'];
			$response->plugin        = $this->config['slug'];
			$response->name          = $this->config['plugin_name'];
			$response->plugin_name   = $this->config['plugin_name'];
			$response->version       = $this->config['new_version'];
			$response->author        = $this->config['author'];
			$response->homepage      = $this->config['homepage'];
			$response->requires      = $this->config['requires'];
			$response->tested        = $this->config['tested'];
			$response->downloaded    = 0;
			$response->last_updated  = $this->config['last_updated'];
			$response->sections      = array( 'description' => $this->config['description'] );
			$response->download_link = $this->config['zip_url'];

			return $response;
		}

		/**
		 * Rename the downloaded zip
		 */
		public function upgrader_source_selection( $source, $remote_source, $upgrader ) {
			global $wp_filesystem;

			if ( strstr( $source, '/seb86-Auto-Load-Next-Post-' ) ) {
				$corrected_source = trailingslashit( $remote_source ) . trailingslashit( $this->config[ 'proper_folder_name' ] );

				if ( $wp_filesystem->move( $source, $corrected_source, true ) ) {
					return $corrected_source;
				} else {
					return new WP_Error();
				}
			}

			return $source;
		}
	}

	register_activation_hook(__FILE__, array('Auto_Load_Next_Post_Beta_Tester', 'activate'));

	add_action('admin_init', array('Auto_Load_Next_Post_Beta_Tester', 'instance'));

endif;

/**
 * Auto Load Next Post Not Installed Notice
 */
if ( ! function_exists('alnpbt_auto_load_next_post_not_installed') ) {
	function alnpbt_auto_load_next_post_not_installed() {
		echo '<div class="error"><p>' . sprintf( __('Auto Load Next Post Beta Tester requires %s to be installed.', 'auto-load-next-post-beta-tester'), '<a href="http://autoloadnextpost.com/" target="_blank">Auto Load Next Post</a>') . '</p></div>';
	}
}