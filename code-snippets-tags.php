<?php

/*
 * Plugin Name: Code Snippets Tags
 * Plugin URL: https://github.com/bungeshea/code-snippets-tags
 * Description: Adds support for adding tags to snippets to the Code Snippets WordPress plugin. Requires Code Snippets 1.7 or later
 * Author: Shea Bunge
 * Author URI: http://bungeshea.com
 * Version: 1.1
 * License: MIT
 * License URI: http://opensource.org/license/mit-license.php
 * Text Domain: code-snippets-tags
 * Domain Path: /languages/
 */

/**
 * @package Code Snippets
 * @subpackage Extend
 */

class Code_Snippets_Tags {

	/**
	 * The version number for this release of the plugin.
	 * This will later be used for upgrades and enqueueing files
	 *
	 * This should be set to the 'Plugin Version' value,
	 * as defined above in the plugin header
	 *
	 * @since 1.0
	 * @access public
	 * @var string A PHP-standardized version number string
	 */
	public $version = '1.1';

	/**
	 * Create an instance of the class as part
	 * of the $code_snippets global variable
	 *
	 * @since 1.1
	 * @access private
	 */
	static function init() {
		global $code_snippets;
		$class = __CLASS__;
		$code_snippets->tags = new $class;
	}

	/**
	 * The constructor function for our class
	 *
	 * Here we hook our methods to their actions
	 * and filters, and run the upgrade method
	 *
	 * @since 1.0
	 * @access public
	 */
	function __construct() {

		/* Run the upgrade method */
		$this->upgrade();

		/* Load translations */
		load_plugin_textdomain( 'code-snippets-tags', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

		/* Ensure the 'tags' column is created with a snippet database table */
		add_filter( 'code_snippets_database_table_columns', array( $this, 'database_table_column' ) );

		/* Administration */
		add_action( 'code_snippets_admin_single', array( $this, 'admin_single' ) );

		/* Administration :: table column */
		add_filter( 'code_snippets_list_table_columns', array( $this, 'add_table_column' ) );
		add_action( 'code_snippets_list_table_column_tags', array( $this, 'table_column' ) );

		/* Administration :: tags filter */
		add_action( 'code_snippets_list_table_filter_controls', array( $this, 'tags_dropdown' ) );
		add_filter( 'code_snippets_list_table_get_snippets', array( $this, 'filter_snippets' ) );
		add_filter( 'code_snippets_list_table_search_notice', array( $this, 'search_notice' ) );
		add_filter( 'code_snippets_list_table_required_form_fields', array( $this, 'add_form_field' ), 10, 2 );

		/* Serializing snippet data */
		add_filter( 'code_snippets_escape_snippet_data', array( $this, 'escape_snippet_data' ) );
		add_filter( 'code_snippets_unescape_snippet_data', array( $this, 'unescape_snippet_data' ) );

		/* Creating a snippet object */
		add_filter( 'code_snippets_build_default_snippet', array( $this, 'build_default_snippet' ) );
		add_filter( 'code_snippets_build_snippet_object', array( $this, 'build_snippet_object' ), 10, 2 );

		/* Scripts and styles */
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Check if the currently installed plugin version is new or not
	 *
	 * @since 1.0
	 * @access public
	 */
	public function upgrade() {
		global $wpdb, $code_snippets;

		/* Fetch the recorded plugin version from the database */
		$previous_version = get_option( 'code_snippets_tags_version' );

		if ( ! $previous_version ) {

			// make sure that the version is not stored elsewhere
			if ( is_multisite() && get_site_option( 'code_snippets_tags_version' ) ) {
				$previous_version = get_site_option( 'code_snippets_tags_version' );
				add_option( 'code_snippets_tags_version', $previous_version );
				delete_site_option( 'code_snippets_tags_version' );

			} else {

				// first run of this version, record it in the database
				add_option( 'code_snippets_tags_version', $this->version );
				$previous_version = $this->version;

				// don't allow the plugin to run any further if we're less then Code Snippets 1.7.1
				if ( ! method_exists( $code_snippets, 'maybe_create_tables' ) ) {
					return;
				}

				// force upgrade of snippet tables
				$code_snippets->maybe_create_tables( true );
			}
		}

		elseif ( version_compare( $previous_version, $this->version, '<' ) ) {

			// Update the plugin version recorded in the database
			update_option( 'code_snippets_tags_version', $this->version );

			// Perform version-specific upgrades

			if ( 0 === version_compare( '1.0', $previous_version ) ) {

				// Upgrade the database data
				$tables = array();

				if ( $wpdb->get_var( "SHOW TABLES LIKE '$wpdb->snippets'" ) === $wpdb->snippets ) {
					$tables[] = $wpdb->snippets;
				}

				if ( is_multisite() && is_main_site() && $wpdb->get_var( "SHOW TABLES LIKE '$wpdb->ms_snippets'" ) === $wpdb->ms_snippets ) {
					$tables[] = $wpdb->ms_snippets;
				}

				foreach ( $tables as $table ) {
					$snippets = $wpdb->get_results( "SELECT id, tags FROM $table" );

					foreach ( $snippets as $snippet ) {
						$snippet->tags = maybe_unserialize( $snippet->tags );
						$snippet->tags = $this->build_array( $snippet->tags );
						$snippet->tags = implode( ', ', $snippet->tags );

						$wpdb->update( $table,
							array( 'tags' => $snippet->tags ),
							array( 'id' => $snippet->id ),
							array( '%s' ),
							array( '%d' )
						);
					}
				} // end $table foreach
			} // end version-specific upgrades
		} // end old version check
	}

	/**
	 * Add a column to the snippets database table
	 *
	 * @since 1.1
	 * @access private
	 */
	function database_table_column( $table_columns ) {
		$table_columns[] = 'tags longtext';
		return $table_columns;
	}

	/**
	 * Add a tags column to the snippets table
	 *
	 * @since 1.0
	 * @access private
	 */
	function add_table_column( $columns ) {
		$columns['tags'] = __('Tags', 'code-snippets-tags');
		return $columns;
	}

	/**
	 * Output the content of the table column
	 * This function is used once for each row
	 *
	 * @since 1.0
	 * @access private
	 */
	function table_column( $snippet ) {

		if ( ! empty( $snippet->tags ) ) {

			foreach ( $snippet->tags as $tag ) {
				$out[] = sprintf( '<a href="%s">%s</a>',
					add_query_arg( 'tag', esc_attr( $tag ) ),
					esc_html( $tag )
				);
			}
			echo join( ', ', $out );
		} else {
			echo '&#8212;';
		}
	}

	/**
	 * Adds the 'tag' query var as a required form field
	 * so it is preserved over form submissions
	 *
	 * @since 1.0
	 * @access public
	 */
	function add_form_field( $vars, $context ) {

		if ( 'filter_controls' !== $context ) {
			$vars[] = 'tag';
		}

		return $vars;
	}

	/**
	 * Filter the snippets based
	 * on the tag filter
	 *
	 * @since 1.0
	 * @access public
	 */
	function filter_snippets( $snippets ) {

		if ( isset( $_POST['tag'] ) ) {

			if ( ! empty( $_POST['tag'] ) )
				wp_redirect( add_query_arg( 'tag', $_POST['tag'] ) );
			else
				wp_redirect( remove_query_arg( 'tag' ) );
		}

		if ( ! empty( $_GET['tag'] ) ) {
			$snippets = array_filter( $snippets, array( $this, '_filter_snippets_callback' ) );
		}

		return $snippets;
	}

	function _filter_snippets_callback( $snippet ) {

		$tags = explode( ',', $_GET['tag'] );

		foreach ( $tags as $tag ) {
			if ( in_array( $tag, $snippet->tags ) ) {
				return true;
			}
		}
	}

	/**
	 * Adds the tag filter to the search notice
	 *
	 * @since 1.0
	 * @access private
	 */
	function search_notice() {
		if ( ! empty( $_GET['tag'] ) ) {
			return sprintf ( __(' in tag &#8220;%s&#8221;', 'code-snippets-tags' ), $_GET['tag'] );
		}
	}

	/**
	 * Display a dropdown of all of the used tags for filtering items
	 *
	 * @since 1.0
	 * @access public
	 */
	public function tags_dropdown() {
		global $wpdb;

		$tags = $this->get_current_tags();
		$query = isset( $_GET['tag'] ) ? $_GET['tag'] : '';

		if ( ! count( $tags ) )
			return;

		echo '<select name="tag">';

		printf ( "<option %s value=''>%s</option>\n",
			selected( $query, '', false ),
			__('Show all tags', 'code-snippets-tags')
		);

		foreach ( $tags as $tag ) {

			printf( "<option %s value='%s'>%s</option>\n",
				selected( $query, $tag, false ),
				esc_attr( $tag ),
				$tag
			);
		}

		echo '</select>';
	}

	/**
	 * Gets all of the used tags from the database
	 *
	 * @since 1.0
	 * @access public
	 */
	public function get_all_tags() {
		global $wpdb, $code_snippets;

		// grab all tags from the database
		$tags = array();
		$table = $code_snippets->get_table_name();
		$all_tags = $wpdb->get_col( "SELECT tags FROM $table" );

		// merge all tags into a single array
		foreach ( $all_tags as $snippet_tags ) {
			$snippet_tags = maybe_unserialize( $snippet_tags );
			$snippet_tags = $this->build_array( $snippet_tags );
			$tags = array_merge( $snippet_tags, $tags );
		}

		// remove duplicate tags
		return array_values( array_unique( $tags, SORT_REGULAR ) );
	}

	/**
	 * Gets the tags of the snippets currently being viewed in the table
	 *
	 * @since 1.0
	 * @access public
	 */
	public function get_current_tags() {
		global $snippets, $status;

		// if we're not viewing a snippets table, get all used tags instead
		if ( ! isset( $snippets, $status ) )
			return $this->get_all_tags();

		$tags = array();

		// merge all tags into a single array
		foreach ( $snippets[ $status ] as $snippet ) {
			$tags = array_merge( $snippet->tags, $tags );
		}

		// remove duplicate tags
		return array_values( array_unique( $tags, SORT_REGULAR ) );
	}

	/**
	 * Make sure that the tags are a valid array
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param mixed $tags The tags to convert into an array
	 * @return array The converted tags
	 */
	public function build_array( $tags ) {

		// if there are no tags set, create a default empty array
		if ( empty( $tags ) ) {
			$tags = array();
		}

		// if the tags are set as a string, convert them to an array
		elseif ( is_string( $tags ) ) {
			$tags = str_replace( ', ', ',', $tags );
			$tags = explode( ',', $tags );
		}

		// if we still don't have an array, just convert whatever we do have into one
		if ( ! is_array( $tags ) ) {
			$tags = (array) $tags;
		}

		return $tags;
	}

	/**
	 * Escape the tag data for insertion into the database
	 *
	 * @since 1.0
	 * @access private
	 */
	function escape_snippet_data( $snippet ) {
		$snippet->tags = $this->build_array( $snippet->tags );
		$snippet->tags = implode( ', ', $snippet->tags );
		return $snippet;
	}

	/**
	 * Unescape the tag data after retrieval from the database,
	 * ready for use
	 *
	 * @since 1.0
	 * @access private
	 */
	function unescape_snippet_data( $snippet ) {
		$snippet->tags = maybe_unserialize( $snippet->tags );
		$snippet->tags = $this->build_array( $snippet->tags );
		return $snippet;
	}

	/**
	 * Create an empty array for the tags
	 * when building an empty snippet object
	 *
	 * @since 1.0
	 * @access private
	 */
	function build_default_snippet( $snippet ) {
		$snippet->tags = array();
		return $snippet;
	}

	/**
	 * Convert snippet array keys into a
	 * valid snippet object
	 *
	 * @since 1.0
	 * @access private
	 */
	function build_snippet_object( $snippet, $data ) {

		if ( isset( $data['tags'] ) )
			$snippet->tags = $data['tags'];

		elseif ( isset( $data['snippet_tags'] ) )
			$snippet->tags = $data['snippet_tags'];

		return $snippet;
	}

	/**
	 * Enqueue the tag-it scripts and styles
	 * on the edit/add new snippet page
	 *
	 * @since 1.0
	 * @access private
	 */
	function enqueue_scripts( $hook ) {
		global $code_snippets;

		if ( $hook !== $code_snippets->admin_single )
			return;

		$tagit_version = '2.0';

		wp_register_script(
			'tag-it',
			plugins_url( 'assets/tag-it.min.js', __FILE__ ),
			array(
				'jquery-ui-core',
				'jquery-ui-widget',
				'jquery-ui-position',
				'jquery-ui-autocomplete',
				'jquery-effects-blind',
				'jquery-effects-highlight',
			),
			$tagit_version
		);

		wp_register_style(
			'tagit',
			plugins_url( 'assets/jquery.tagit.css', __FILE__ ),
			false,
			$tagit_version
		);

		wp_register_style(
			'tagit-zendesk-ui',
			plugins_url( 'assets/tagit.ui-zendesk.css', __FILE__ ),
			array( 'tagit' ),
			$tagit_version
		);

		wp_enqueue_style( 'tagit' );
		wp_enqueue_style( 'tagit-zendesk-ui' );
		wp_enqueue_script( 'tag-it' );
	}

	/**
	 * Output the interface for editing snippet tags
	 *
	 * @since 1.0
	 * @access public
	 */
	public function admin_single( $snippet ) {
	?>
		<label for="snippet_tags" style="cursor: auto;">
			<h3><?php esc_html_e( 'Tags', 'code-snippets-tags' ); ?>
			<span style="font-weight: normal;"><?php esc_html_e( '(Optional)', 'code-snippets-tags' ); ?></span></h3>
		</label>

		<input type="text" id="snippet_tags" name="snippet_tags" style="width: 100%;" placeholder="Enter a list of tags; separated by commas" value="<?php echo implode( ', ', $snippet->tags ); ?>" />

		<script type="text/javascript">
		jQuery('#snippet_tags').tagit({
			availableTags: ['<?php echo implode( "','", $this->get_all_tags() ); ?>'],
			allowSpaces: true,
			removeConfirmation: true
		});
		</script>

	<?php
	}
}

add_action( 'code_snippets_init', array( 'Code_Snippets_Tags', 'init' ) );
