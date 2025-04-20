<?php
/**
 * Custom Post Type: Power Payment
 */

declare(strict_types=1);

namespace J7\PowerPayment\Admin;

use J7\PowerPayment\Utils\Base;
use J7\PowerPayment\Plugin;

if (class_exists('J7\PowerPayment\Admin\CPT')) {
	return;
}
/**
 * Class CPT
 */
final class CPT {
	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Post meta
	 *
	 * @var array
	 */
	public $post_meta_array = [];
	/**
	 * Rewrite
	 *
	 * @var array
	 */
	public $rewrite = [];

	/**
	 * Constructor
	 */
	public function __construct() {
		$args = [
			'post_meta_array' => [ 'meta', 'settings' ],
			'rewrite'         => [
				'template_path' => 'test.php',
				'slug'          => 'test',
				'var'           => Plugin::$snake . '_test',
			],
		];

		$this->post_meta_array = $args['post_meta_array'];
		$this->rewrite         = $args['rewrite'] ?? [];

		\add_action( 'init', [ $this, 'init' ] );

		if ( ! empty( $args['post_meta_array'] ) ) {
			\add_action( 'rest_api_init', [ $this, 'add_post_meta' ] );
		}

		\add_action( 'load-post.php', [ $this, 'init_metabox' ] );
		\add_action( 'load-post-new.php', [ $this, 'init_metabox' ] );

		if ( ! empty( $args['rewrite'] ) ) {
			\add_filter( 'query_vars', [ $this, 'add_query_var' ] );
			\add_filter( 'template_include', [ $this, 'load_custom_template' ], 99 );
		}
	}

	/**
	 * Initialize
	 */
	public function init(): void {
		$this->register_cpt();

		// add {$this->post_type}/{slug}/test rewrite rule
		if ( ! empty( $this->rewrite ) ) {
			\add_rewrite_rule( '^power-payment/([^/]+)/' . $this->rewrite['slug'] . '/?$', 'index.php?post_type=power-payment&name=$matches[1]&' . $this->rewrite['var'] . '=1', 'top' );
			\flush_rewrite_rules();
		}
	}

	/**
	 * Register power-payment custom post type
	 */
	public static function register_cpt(): void {

		$labels = [
			'name'                     => \esc_html__( 'power-payment', 'power_payment' ),
			'singular_name'            => \esc_html__( 'power-payment', 'power_payment' ),
			'add_new'                  => \esc_html__( 'Add new', 'power_payment' ),
			'add_new_item'             => \esc_html__( 'Add new item', 'power_payment' ),
			'edit_item'                => \esc_html__( 'Edit', 'power_payment' ),
			'new_item'                 => \esc_html__( 'New', 'power_payment' ),
			'view_item'                => \esc_html__( 'View', 'power_payment' ),
			'view_items'               => \esc_html__( 'View', 'power_payment' ),
			'search_items'             => \esc_html__( 'Search power-payment', 'power_payment' ),
			'not_found'                => \esc_html__( 'Not Found', 'power_payment' ),
			'not_found_in_trash'       => \esc_html__( 'Not found in trash', 'power_payment' ),
			'parent_item_colon'        => \esc_html__( 'Parent item', 'power_payment' ),
			'all_items'                => \esc_html__( 'All', 'power_payment' ),
			'archives'                 => \esc_html__( 'power-payment archives', 'power_payment' ),
			'attributes'               => \esc_html__( 'power-payment attributes', 'power_payment' ),
			'insert_into_item'         => \esc_html__( 'Insert to this power-payment', 'power_payment' ),
			'uploaded_to_this_item'    => \esc_html__( 'Uploaded to this power-payment', 'power_payment' ),
			'featured_image'           => \esc_html__( 'Featured image', 'power_payment' ),
			'set_featured_image'       => \esc_html__( 'Set featured image', 'power_payment' ),
			'remove_featured_image'    => \esc_html__( 'Remove featured image', 'power_payment' ),
			'use_featured_image'       => \esc_html__( 'Use featured image', 'power_payment' ),
			'menu_name'                => \esc_html__( 'power-payment', 'power_payment' ),
			'filter_items_list'        => \esc_html__( 'Filter power-payment list', 'power_payment' ),
			'filter_by_date'           => \esc_html__( 'Filter by date', 'power_payment' ),
			'items_list_navigation'    => \esc_html__( 'power-payment list navigation', 'power_payment' ),
			'items_list'               => \esc_html__( 'power-payment list', 'power_payment' ),
			'item_published'           => \esc_html__( 'power-payment published', 'power_payment' ),
			'item_published_privately' => \esc_html__( 'power-payment published privately', 'power_payment' ),
			'item_reverted_to_draft'   => \esc_html__( 'power-payment reverted to draft', 'power_payment' ),
			'item_scheduled'           => \esc_html__( 'power-payment scheduled', 'power_payment' ),
			'item_updated'             => \esc_html__( 'power-payment updated', 'power_payment' ),
		];
		$args   = [
			'label'                 => \esc_html__( 'power-payment', 'power_payment' ),
			'labels'                => $labels,
			'description'           => '',
			'public'                => true,
			'hierarchical'          => false,
			'exclude_from_search'   => true,
			'publicly_queryable'    => true,
			'show_ui'               => true,
			'show_in_nav_menus'     => false,
			'show_in_admin_bar'     => false,
			'show_in_rest'          => true,
			'query_var'             => false,
			'can_export'            => true,
			'delete_with_user'      => true,
			'has_archive'           => false,
			'rest_base'             => '',
			'show_in_menu'          => true,
			'menu_position'         => 6,
			'menu_icon'             => 'dashicons-store',
			'capability_type'       => 'post',
			'supports'              => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'author' ],
			'taxonomies'            => [],
			'rest_controller_class' => 'WP_REST_Posts_Controller',
			'rewrite'               => [
				'with_front' => true,
			],
		];

		\register_post_type( 'power-payment', $args );
	}

	/**
	 * Register meta fields for post type to show in rest api
	 */
	public function add_post_meta(): void {
		foreach ( $this->post_meta_array as $meta_key ) {
			\register_meta(
				'post',
				Plugin::$snake . '_' . $meta_key,
				[
					'type'         => 'string',
					'show_in_rest' => true,
					'single'       => true,
				]
			);
		}
	}

	/**
	 * Meta box initialization.
	 */
	public function init_metabox(): void {
		\add_action( 'add_meta_boxes', [ $this, 'add_metabox' ] );
		\add_action( 'save_post', [ $this, 'save_metabox' ], 10, 2 );
		\add_filter( 'rewrite_rules_array', [ $this, 'custom_post_type_rewrite_rules' ] );
	}

	/**
	 * Adds the meta box.
	 *
	 * @param string $post_type Post type.
	 */
	public function add_metabox( string $post_type ): void {
		if ( in_array( $post_type, [ Plugin::$kebab ] ) ) {
			\add_meta_box(
				Plugin::$kebab . '-metabox',
				__( 'Power Payment', 'power_payment' ),
				[ $this, 'render_meta_box' ],
				$post_type,
				'advanced',
				'high'
			);
		}
	}

	/**
	 * Render meta box.
	 */
	public function render_meta_box(): void {
		// phpcs:ignore
		echo '<div id="' . substr(Base::APP2_SELECTOR, 1) . '" class="relative"></div>';
	}


	/**
	 * Add query var
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = $this->rewrite['var'];
		return $vars;
	}

	/**
	 * Custom post type rewrite rules
	 *
	 * @param array $rules Rules.
	 * @return array
	 */
	public function custom_post_type_rewrite_rules( $rules ) {
		global $wp_rewrite;
		$wp_rewrite->flush_rules();
		return $rules;
	}

	/**
	 * Save the meta when the post is saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_metabox( $post_id, $post ) { // phpcs:ignore
		// phpcs:disable
		/*
		* We need to verify this came from the our screen and with proper authorization,
		* because save_post can be triggered at other times.
		*/

		// Check if our nonce is set.
		if ( ! isset( $_POST['_wpnonce'] ) ) {
			return $post_id;
		}

		$nonce = $_POST['_wpnonce'];

		/*
		* If this is an autosave, our form has not been submitted,
		* so we don't want to do anything.
		*/
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}

		$post_type = \sanitize_text_field( $_POST['post_type'] ?? '' );

		// Check the user's permissions.
		if ( 'power-payment' !== $post_type ) {
			return $post_id;
		}

		if ( ! \current_user_can( 'edit_post', $post_id ) ) {
			return $post_id;
		}

		/* OK, it's safe for us to save the data now. */

		// Sanitize the user input.
		$meta_data = \sanitize_text_field( $_POST[ Plugin::$snake . '_meta' ] );

		// Update the meta field.
		\update_post_meta( $post_id, Plugin::$snake . '_meta', $meta_data );
	}

	/**
	 * Load custom template
	 * Set {Plugin::$kebab}/{slug}/report  php template
	 *
	 * @param string $template Template.
	 */
	public function load_custom_template( $template ) {
		$report_template_path = Plugin::$dir . '/inc/templates/' . $this->rewrite['template_path'];

		if ( \get_query_var( $this->rewrite['var'] ) ) {
			if ( file_exists( $report_template_path ) ) {
				return $report_template_path;
			}
		}
		return $template;
	}
}
