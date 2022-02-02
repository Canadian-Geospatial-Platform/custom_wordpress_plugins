<?php
/**
* Plugin Name: Insert Headers and Footers
* Plugin URI: http://www.wpbeginner.com/
* Version: 1.6.0
* Requires at least: 4.6
* Requires PHP: 5.2
* Tested up to: 5.7
* Author: Jeffrey Ashton NRCan <jeffrey.ashton@nrcan-rncan.gc.ca> / WPBeginner
* Author URI: https://github.com/Canadian-Geospatial-Platform/custom_wordpress_plugins.git
* Description: Adds NRCan Adobe Analytics scripts / Allows you to insert code or text in the header or footer of your WordPress blog
* License: GPLv2 or later
*/

/*  Copyright 2019 WPBeginner

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
* Insert Headers and Footers Class
*/
class InsertHeadersAndFooters {
	/**
	* Constructor
	*/
	public function __construct() {
		$file_data = get_file_data( __FILE__, array( 'Version' => 'Version' ) );

		// Plugin Details
		$this->plugin                           = new stdClass;
		$this->plugin->name                     = 'insert-headers-and-footers'; // Plugin Folder
		$this->plugin->displayName              = 'Insert Headers and Footers'; // Plugin Name
		$this->plugin->version                  = $file_data['Version'];
		$this->plugin->folder                   = plugin_dir_path( __FILE__ );
		$this->plugin->url                      = plugin_dir_url( __FILE__ );
		$this->plugin->db_welcome_dismissed_key = $this->plugin->name . '_welcome_dismissed_key';
		$this->body_open_supported              = function_exists( 'wp_body_open' ) && version_compare( get_bloginfo( 'version' ), '5.2', '>=' );

        // NRCan Adobe Analytics header and footer scripts
        $this->staging = 'stage.geo.ca';
        // $this->staging = 'wordpress.dev.geo.ca'; //testing
        $this->prod    = 'geo.ca';

        $this->aa_header_staging = '<script src="//assets.adobedtm.com/be5dfd287373/0127575cd23a/launch-913b1beddf7a-staging.min.js"></script>';
        $this->aa_header_prod = '<script src="//assets.adobedtm.com/be5dfd287373/0127575cd23a/launch-f7c3e6060667.min.js"></script>';

        $this->aa_footer_staging = '<script type="text/javascript">_satellite.pageBottom();</script>';
        $this->aa_footer_prod = '<script type="text/javascript">_satellite.pageBottom();</script>';

        $this->aa_meta_title = '<meta name="dcterms.title" content=';
        $this->aa_meta_language_english = '<meta name="dcterms.language" content="eng"/> ';
        $this->aa_meta_language_french = '<meta name="dcterms.language" content="fra"/> ';
        $this->aa_meta_creator_english = '<meta name="dcterms.creator" content="Natural Resources Canada"/> ';
        $this->aa_meta_creator_french = '<meta name="dcterms.creator" content="Ressources naturelles Canada"/> ';
        $this->aa_meta_service = '<meta name="dcterms.service" content=" NRCan-RNCan"/>  ';
        $this->aa_meta_access_rights_public = '<meta name="dcterms.accessRights” content="2"/>   ';  // public
        $this->aa_meta_access_rights_secured = '<meta name="dcterms.accessRights” content="1"/>   ';  // secured (logged on required)


        // Hooks
		add_action( 'admin_init', array( &$this, 'registerSettings' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'initCodeMirror' ) );
		add_action( 'admin_menu', array( &$this, 'adminPanelsAndMetaBoxes' ) );
		add_action( 'admin_notices', array( &$this, 'dashboardNotices' ) );
		add_action( 'wp_ajax_' . $this->plugin->name . '_dismiss_dashboard_notices', array( &$this, 'dismissDashboardNotices' ) );

		// Frontend Hooks
		add_action( 'wp_head', array( &$this, 'frontendHeader' ) );
		add_action( 'wp_footer', array( &$this, 'frontendFooter' ) );
		if ( $this->body_open_supported ) {
			add_action( 'wp_body_open', array( &$this, 'frontendBody' ), 1 );
		}
	}

	/**
	 * Show relevant notices for the plugin
	 */
	function dashboardNotices() {
		global $pagenow;

		if (
			! get_option( $this->plugin->db_welcome_dismissed_key )
			&& current_user_can( 'manage_options' )
		) {
			if ( ! ( 'options-general.php' === $pagenow && isset( $_GET['page'] ) && 'insert-headers-and-footers' === $_GET['page'] ) ) {
				$setting_page = admin_url( 'options-general.php?page=' . $this->plugin->name );
				// load the notices view
				include_once( $this->plugin->folder . '/views/dashboard-notices.php' );
			}
		}
	}

	/**
	 * Dismiss the welcome notice for the plugin
	 */
	function dismissDashboardNotices() {
		check_ajax_referer( $this->plugin->name . '-nonce', 'nonce' );
		// user has dismissed the welcome notice
		update_option( $this->plugin->db_welcome_dismissed_key, 1 );
		exit;
	}

	/**
	* Register Settings
	*/
	function registerSettings() {
		register_setting( $this->plugin->name, 'ihaf_insert_header', 'trim' );
		register_setting( $this->plugin->name, 'ihaf_insert_footer', 'trim' );
		register_setting( $this->plugin->name, 'ihaf_insert_body', 'trim' );
	}

	/**
	* Register the plugin settings panel
	*/
	function adminPanelsAndMetaBoxes() {
		add_submenu_page( 'options-general.php', $this->plugin->displayName, $this->plugin->displayName, 'manage_options', $this->plugin->name, array( &$this, 'adminPanel' ) );
	}

	/**
	* Output the Administration Panel
	* Save POSTed data from the Administration Panel into a WordPress option
	*/
	function adminPanel() {
		/*
		 * Only users with manage_options can access this page.
		 *
		 * The capability included in add_settings_page() means WP should deal
		 * with this automatically but it never hurts to double check.
		 */
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Sorry, you are not allowed to access this page.', 'insert-headers-and-footers' ) );
		}

		// only users with `unfiltered_html` can edit scripts.
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$this->errorMessage = '<p>' . __( 'Sorry, only have read-only access to this page. Ask your administrator for assistance editing.', 'insert-headers-and-footers' ) . '</p>';
		}

		// Save Settings
		if ( isset( $_REQUEST['submit'] ) ) {
			// Check permissions and nonce.
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				// Can not edit scripts.
				wp_die( __( 'Sorry, you are not allowed to edit this page.', 'insert-headers-and-footers' ) );
			} elseif ( ! isset( $_REQUEST[ $this->plugin->name . '_nonce' ] ) ) {
				// Missing nonce
				$this->errorMessage = __( 'nonce field is missing. Settings NOT saved.', 'insert-headers-and-footers' );
			} elseif ( ! wp_verify_nonce( $_REQUEST[ $this->plugin->name . '_nonce' ], $this->plugin->name ) ) {
				// Invalid nonce
				$this->errorMessage = __( 'Invalid nonce specified. Settings NOT saved.', 'insert-headers-and-footers' );
			} else {
				// Save
				// $_REQUEST has already been slashed by wp_magic_quotes in wp-settings
				// so do nothing before saving

				update_option( 'ihaf_insert_header', $_REQUEST['ihaf_insert_header'] );
				update_option( 'ihaf_insert_footer', $_REQUEST['ihaf_insert_footer'] );
				update_option( 'ihaf_insert_body', isset( $_REQUEST['ihaf_insert_body'] ) ? $_REQUEST['ihaf_insert_body'] : '' );
				update_option( $this->plugin->db_welcome_dismissed_key, 1 );
				$this->message = __( 'Settings Saved.', 'insert-headers-and-footers' );
			}
		}

		// Get latest settings
		$this->settings = array(
			'ihaf_insert_header' => esc_html( wp_unslash( get_option( 'ihaf_insert_header' ) ) ),
			'ihaf_insert_footer' => esc_html( wp_unslash( get_option( 'ihaf_insert_footer' ) ) ),
			'ihaf_insert_body'   => esc_html( wp_unslash( get_option( 'ihaf_insert_body' ) ) ),
		);

		// Load Settings Form
		include_once( $this->plugin->folder . '/views/settings.php' );
	}

	/**
	 * Enqueue and initialize CodeMirror for the form fields.
	 */
	function initCodeMirror() {
		// Make sure that we don't fatal error on WP versions before 4.9.
		if ( ! function_exists( 'wp_enqueue_code_editor' ) ) {
			return;
		}

		global $pagenow;

		if ( ! ( 'options-general.php' === $pagenow && isset( $_GET['page'] ) && 'insert-headers-and-footers' === $_GET['page'] ) ) {
			return;
		}

		$editor_args = array( 'type' => 'text/html' );

		if ( ! current_user_can( 'unfiltered_html' ) || ! current_user_can( 'manage_options' ) ) {
			$editor_args['codemirror']['readOnly'] = true;
		}

		// Enqueue code editor and settings for manipulating HTML.
		$settings = wp_enqueue_code_editor( $editor_args );

		// Bail if user disabled CodeMirror.
		if ( false === $settings ) {
			return;
		}

		// Custom styles for the form fields.
		$styles = '.CodeMirror{ border: 1px solid #ccd0d4; }';

		wp_add_inline_style( 'code-editor', $styles );

		wp_add_inline_script( 'code-editor', sprintf( 'jQuery( function() { wp.codeEditor.initialize( "ihaf_insert_header", %s ); } );', wp_json_encode( $settings ) ) );
		wp_add_inline_script( 'code-editor', sprintf( 'jQuery( function() { wp.codeEditor.initialize( "ihaf_insert_body", %s ); } );', wp_json_encode( $settings ) ) );
		wp_add_inline_script( 'code-editor', sprintf( 'jQuery( function() { wp.codeEditor.initialize( "ihaf_insert_footer", %s ); } );', wp_json_encode( $settings ) ) );
	}

	/**
	* Outputs script / CSS to the frontend header
	*/
	function frontendHeader() {
		$this->output( 'ihaf_insert_header' );
	}

	/**
	* Outputs script / CSS to the frontend footer
	*/
	function frontendFooter() {
		$this->output( 'ihaf_insert_footer' );
	}

	/**
	* Outputs script / CSS to the frontend below opening body
	*/
	function frontendBody() {
		$this->output( 'ihaf_insert_body' );
	}

	/**
	* Outputs the given setting, if conditions are met
	*
	* @param string $setting Setting Name
	* @return output
	*/
	function output( $setting ) {
		// Ignore admin, feed, robots or trackbacks
		if ( is_admin() || is_feed() || is_robots() || is_trackback() ) {
			return;
		}

		// provide the opportunity to Ignore IHAF - both headers and footers via filters
		if ( apply_filters( 'disable_ihaf', false ) ) {
			return;
		}

		// provide the opportunity to Ignore IHAF - footer only via filters
		if ( 'ihaf_insert_footer' === $setting && apply_filters( 'disable_ihaf_footer', false ) ) {
			return;
		}

		// provide the opportunity to Ignore IHAF - header only via filters
		if ( 'ihaf_insert_header' === $setting && apply_filters( 'disable_ihaf_header', false ) ) {
			return;
		}

		// provide the opportunity to Ignore IHAF - below opening body only via filters
		if ( 'ihaf_insert_body' === $setting && apply_filters( 'disable_ihaf_body', false ) ) {
			return;
		}

        // output NRCan adobe analytics header and footer scripts for Staging and Prod only
        if ( 'ihaf_insert_header' === $setting) {
            $this->adobe_analytics_header();
        }
        if ( 'ihaf_insert_footer' === $setting) {
            $this->adobe_analytics_footer();
        }

        // Get meta
		$meta = get_option( $setting );
		if ( empty( $meta ) ) {
			return;
		}
		if ( trim( $meta ) === '' ) {
			return;
		}

		// Output
		echo wp_unslash( $meta );

	}

    /**
     * output NRCan Adobe Analytics header scripts for Staging and Prod only
     * output <Meta> tags by screen language
     *
     */
    function adobe_analytics_header() {

        If ($_SERVER['HTTP_HOST'] == $this->staging) {
            echo '<!-- Adobe Analytics-->' . PHP_EOL . $this->aa_header_staging . PHP_EOL;
        }
        elseif ($_SERVER['HTTP_HOST'] == $this->prod) {
            echo '<!--Adobe Analytics -->' . PHP_EOL . $this->aa_header_prod . PHP_EOL;
        }
        else {
            return;
        }

        //Adobe Analytics Meta tags

        // check language
        $lang_en = true;
        if (function_exists('get_locale')) {
            $lang = get_locale();
            $lang_en = stripos ($lang,'fr_CA' ) !== false ? false : true;
        }

        // 404 not found page title add request URI
        if (is_404()) {
            echo $this->aa_meta_title . '"' . '404 Not Found: ' . $_SERVER['REQUEST_URI']  .  '" />' . PHP_EOL;
        } else {
            echo $this->aa_meta_title . '"' . get_the_title() . '" />' . PHP_EOL;
        }

        if ($lang_en) {
            echo $this->aa_meta_language_english . PHP_EOL;
            echo $this->aa_meta_creator_english . PHP_EOL;
        } else {
            echo $this->aa_meta_language_french . PHP_EOL;
            echo $this->aa_meta_creator_french . PHP_EOL;
        }

        echo $this->aa_meta_service . PHP_EOL;
        echo $this->aa_meta_access_rights_public . PHP_EOL;
        echo '<!--end Adobe Analytics -->' . PHP_EOL;

        return;
    }

    /**
     * output NRCan Adobe Analytics footer scripts  by domain name (Staging or Prod)
     *
     */
    function adobe_analytics_footer() {

        If ($_SERVER['HTTP_HOST'] == $this->staging) {
            echo PHP_EOL . '<!-- Adobe Analytics -->' . PHP_EOL;
            echo $this->aa_footer_staging;
            echo PHP_EOL . '<!-- end Adobe Analytics -->' . PHP_EOL;
        }
        elseif ($_SERVER['HTTP_HOST'] == $this->prod) {
            echo PHP_EOL . '<!-- Adobe Analytics -->' . PHP_EOL;
            echo $this->aa_footer_prod;
            echo PHP_EOL . '<!-- end Adobe Analytics -->' . PHP_EOL;
        }

        return;

    }
}

$ihaf = new InsertHeadersAndFooters();
