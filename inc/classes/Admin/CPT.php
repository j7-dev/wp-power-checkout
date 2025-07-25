<?php
/**
 * Custom Post Type: Power Checkout
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Admin;

use J7\PowerCheckout\Utils\Base;
use J7\PowerCheckout\Plugin;

if (class_exists('J7\PowerCheckout\Admin\CPT')) {
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
			\add_rewrite_rule( '^power-checkout/([^/]+)/' . $this->rewrite['slug'] . '/?$', 'index.php?post_type=power-checkout&name=$matches[1]&' . $this->rewrite['var'] . '=1', 'top' );
			\flush_rewrite_rules();
		}
	}

	/**
	 * Register power-checkout custom post type
	 */
	public static function register_cpt(): void {

		$labels = [
			'name'                     => \esc_html__( 'power-checkout', 'power_checkout' ),
			'singular_name'            => \esc_html__( 'power-checkout', 'power_checkout' ),
			'add_new'                  => \esc_html__( 'Add new', 'power_checkout' ),
			'add_new_item'             => \esc_html__( 'Add new item', 'power_checkout' ),
			'edit_item'                => \esc_html__( 'Edit', 'power_checkout' ),
			'new_item'                 => \esc_html__( 'New', 'power_checkout' ),
			'view_item'                => \esc_html__( 'View', 'power_checkout' ),
			'view_items'               => \esc_html__( 'View', 'power_checkout' ),
			'search_items'             => \esc_html__( 'Search power-checkout', 'power_checkout' ),
			'not_found'                => \esc_html__( 'Not Found', 'power_checkout' ),
			'not_found_in_trash'       => \esc_html__( 'Not found in trash', 'power_checkout' ),
			'parent_item_colon'        => \esc_html__( 'Parent item', 'power_checkout' ),
			'all_items'                => \esc_html__( 'All', 'power_checkout' ),
			'archives'                 => \esc_html__( 'power-checkout archives', 'power_checkout' ),
			'attributes'               => \esc_html__( 'power-checkout attributes', 'power_checkout' ),
			'insert_into_item'         => \esc_html__( 'Insert to this power-checkout', 'power_checkout' ),
			'uploaded_to_this_item'    => \esc_html__( 'Uploaded to this power-checkout', 'power_checkout' ),
			'featured_image'           => \esc_html__( 'Featured image', 'power_checkout' ),
			'set_featured_image'       => \esc_html__( 'Set featured image', 'power_checkout' ),
			'remove_featured_image'    => \esc_html__( 'Remove featured image', 'power_checkout' ),
			'use_featured_image'       => \esc_html__( 'Use featured image', 'power_checkout' ),
			'menu_name'                => \esc_html__( 'power-checkout', 'power_checkout' ),
			'filter_items_list'        => \esc_html__( 'Filter power-checkout list', 'power_checkout' ),
			'filter_by_date'           => \esc_html__( 'Filter by date', 'power_checkout' ),
			'items_list_navigation'    => \esc_html__( 'power-checkout list navigation', 'power_checkout' ),
			'items_list'               => \esc_html__( 'power-checkout list', 'power_checkout' ),
			'item_published'           => \esc_html__( 'power-checkout published', 'power_checkout' ),
			'item_published_privately' => \esc_html__( 'power-checkout published privately', 'power_checkout' ),
			'item_reverted_to_draft'   => \esc_html__( 'power-checkout reverted to draft', 'power_checkout' ),
			'item_scheduled'           => \esc_html__( 'power-checkout scheduled', 'power_checkout' ),
			'item_updated'             => \esc_html__( 'power-checkout updated', 'power_checkout' ),
		];
		$args   = [
			'label'                 => \esc_html__( 'power-checkout', 'power_checkout' ),
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

		\register_post_type( 'power-checkout', $args );
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
				__( 'Power Checkout', 'power_checkout' ),
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
		if ( 'power-checkout' !== $post_type ) {
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
