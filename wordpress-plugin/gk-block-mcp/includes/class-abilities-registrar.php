<?php
/**
 * Registers Block MCP REST endpoints as WordPress Abilities for mcp-adapter.
 *
 * @package GravityKit\BlockMCP
 * @since   2.0.4
 */

namespace GravityKit\BlockMCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes gk-block-api tools through the WordPress Abilities API.
 *
 * @since 2.0.4
 */
class Abilities_Registrar {

	const CATEGORY = 'gk-block-mcp';

	const NAMESPACE_PREFIX = 'gk-block-mcp/';

	/**
	 * REST controller instance.
	 *
	 * @var REST_Controller
	 */
	private $controller;

	/**
	 * Yoast bridge instance.
	 *
	 * @var Yoast_Bridge
	 */
	private $yoast;

	/**
	 * @param REST_Controller $controller REST controller.
	 * @param Yoast_Bridge    $yoast      Yoast SEO bridge.
	 */
	public function __construct( REST_Controller $controller, Yoast_Bridge $yoast ) {
		$this->controller = $controller;
		$this->yoast      = $yoast;
	}

	/**
	 * Register ability category and all tool abilities.
	 */
	public function register() {
		$this->register_core_abilities();
		$this->register_yoast_abilities();
	}

	/**
	 * Register core block MCP tool abilities.
	 */
	private function register_core_abilities() {
		$abilities = array(
			array(
				'slug'        => 'get-page-blocks',
				'label'       => 'Get post blocks',
				'description' => 'Read a post\'s blocks as structured JSON with paths and stable refs.',
				'readonly'    => true,
				'permission'  => 'read',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'url'     => array( 'type' => 'string' ),
					),
				),
				'execute'     => array( $this, 'execute_get_page_blocks' ),
			),
			array(
				'slug'        => 'get-block',
				'label'       => 'Get one block',
				'description' => 'Fetch one block by stable ref or flat_index.',
				'readonly'    => true,
				'permission'  => 'edit',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer' ),
						'ref'        => array( 'type' => 'string' ),
						'flat_index' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'post_id' ),
				),
				'execute'     => array( $this, 'execute_get_block' ),
			),
			array(
				'slug'        => 'update-block',
				'label'       => 'Update one block',
				'description' => 'Update one block by flat_index or ref.',
				'readonly'    => false,
				'permission'  => 'edit',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer' ),
						'flat_index' => array( 'type' => 'integer' ),
						'ref'        => array( 'type' => 'string' ),
						'attributes' => array( 'type' => 'object' ),
						'innerHTML'  => array( 'type' => 'string' ),
					),
					'required'   => array( 'post_id' ),
				),
				'execute'     => array( $this, 'execute_update_block' ),
			),
			array(
				'slug'        => 'update-blocks',
				'label'       => 'Batch-update blocks',
				'description' => 'Update N independent blocks atomically in one revision.',
				'readonly'    => false,
				'permission'  => 'edit',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'updates' => array( 'type' => 'array' ),
						'verbose' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'post_id', 'updates' ),
				),
				'execute'     => array( $this, 'execute_simple_post_body' ),
				'handler'     => array( $this->controller, 'update_blocks_batch' ),
				'method'      => 'POST',
				'route'       => '/posts/%d/blocks/batch-update',
			),
			array(
				'slug'        => 'insert-blocks',
				'label'       => 'Insert blocks',
				'description' => 'Insert blocks at a position on a post.',
				'readonly'    => false,
				'permission'  => 'edit',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array( 'type' => 'integer' ),
						'blocks'           => array( 'type' => 'array' ),
						'after_top_level'  => array( 'type' => array( 'integer', 'string' ) ),
						'before_top_level' => array( 'type' => 'integer' ),
						'after_ref'        => array( 'type' => 'string' ),
						'before_ref'       => array( 'type' => 'string' ),
					),
					'required'   => array( 'post_id', 'blocks' ),
				),
				'execute'     => array( $this, 'execute_insert_blocks' ),
			),
			array(
				'slug'        => 'delete-block',
				'label'       => 'Delete blocks',
				'description' => 'Remove block(s) by top_level_counter or ref.',
				'readonly'    => false,
				'destructive' => true,
				'permission'  => 'edit',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'           => array( 'type' => 'integer' ),
						'top_level_counter' => array( 'type' => 'integer' ),
						'ref'               => array( 'type' => 'string' ),
						'count'             => array( 'type' => 'integer' ),
					),
					'required'   => array( 'post_id' ),
				),
				'execute'     => array( $this, 'execute_delete_block' ),
			),
			array(
				'slug'        => 'replace-block-range',
				'label'       => 'Replace block range',
				'description' => 'Atomic swap of N top-level blocks for M blocks.',
				'readonly'    => false,
				'permission'  => 'edit',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'start'   => array( 'type' => 'integer' ),
						'count'   => array( 'type' => 'integer' ),
						'blocks'  => array( 'type' => 'array' ),
					),
					'required'   => array( 'post_id', 'start', 'count', 'blocks' ),
				),
				'execute'     => array( $this, 'execute_simple_post_body' ),
				'handler'     => array( $this->controller, 'replace_blocks_range' ),
				'method'      => 'POST',
				'route'       => '/posts/%d/blocks/replace',
			),
			array(
				'slug'        => 'rewrite-post-blocks',
				'label'       => 'Rewrite post blocks',
				'description' => 'Replace all blocks on a page in one revision.',
				'readonly'    => false,
				'destructive' => true,
				'permission'  => 'edit',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'blocks'  => array( 'type' => 'array' ),
					),
					'required'   => array( 'post_id', 'blocks' ),
				),
				'execute'     => array( $this, 'execute_simple_put_body' ),
				'handler'     => array( $this->controller, 'replace_all_blocks' ),
				'route'       => '/posts/%d/blocks',
			),
			array(
				'slug'        => 'edit-block-tree',
				'label'       => 'Edit block tree',
				'description' => 'Path- or ref-based block tree mutation.',
				'readonly'    => false,
				'permission'  => 'edit',
				'input'       => array( 'type' => 'object' ),
				'execute'     => array( $this, 'execute_simple_post_body' ),
				'handler'     => array( $this->controller, 'mutate_block_tree' ),
				'method'      => 'POST',
				'route'       => '/posts/%d/mutate',
			),
			array(
				'slug'        => 'insert-pattern',
				'label'       => 'Insert pattern',
				'description' => 'Insert a synced or registered pattern into a post.',
				'readonly'    => false,
				'permission'  => 'edit',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer' ),
						'pattern_id' => array( 'type' => array( 'integer', 'string' ) ),
					),
					'required'   => array( 'post_id', 'pattern_id' ),
				),
				'execute'     => array( $this, 'execute_insert_pattern' ),
			),
			array(
				'slug'        => 'revert-to-revision',
				'label'       => 'Revert to revision',
				'description' => 'Restore a post to a prior revision.',
				'readonly'    => false,
				'destructive' => true,
				'permission'  => 'edit',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array( 'type' => 'integer' ),
						'revision_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'post_id', 'revision_id' ),
				),
				'execute'     => array( $this, 'execute_simple_post_body' ),
				'handler'     => array( $this->controller, 'revert_to_revision' ),
				'method'      => 'POST',
				'route'       => '/posts/%d/revert',
			),
			array(
				'slug'        => 'list-block-types',
				'label'       => 'List block types',
				'description' => 'Registered block types with preference scoring.',
				'readonly'    => true,
				'permission'  => 'read',
				'input'       => array( 'type' => 'object' ),
				'execute'     => array( $this, 'execute_get_route' ),
				'handler'     => array( $this->controller, 'get_block_types' ),
				'method'      => 'GET',
				'route'       => '/block-types',
			),
			array(
				'slug'        => 'list-patterns',
				'label'       => 'List patterns',
				'description' => 'Block patterns sorted by preference score.',
				'readonly'    => true,
				'permission'  => 'read',
				'input'       => array( 'type' => 'object' ),
				'execute'     => array( $this, 'execute_get_route' ),
				'handler'     => array( $this->controller, 'get_patterns' ),
				'method'      => 'GET',
				'route'       => '/patterns',
			),
			array(
				'slug'        => 'get-pattern',
				'label'       => 'Get pattern',
				'description' => 'Single pattern with parsed block content.',
				'readonly'    => true,
				'permission'  => 'read',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'pattern_id' => array( 'type' => array( 'integer', 'string' ) ),
					),
					'required'   => array( 'pattern_id' ),
				),
				'execute'     => array( $this, 'execute_get_pattern' ),
			),
			array(
				'slug'        => 'get-site-usage',
				'label'       => 'Get site usage',
				'description' => 'Site-wide block and pattern usage statistics.',
				'readonly'    => true,
				'permission'  => 'read',
				'input'       => array( 'type' => 'object' ),
				'execute'     => array( $this, 'execute_get_route' ),
				'handler'     => array( $this->controller, 'get_site_usage' ),
				'method'      => 'GET',
				'route'       => '/site-usage',
			),
			array(
				'slug'        => 'scan-storage-modes',
				'label'       => 'Scan storage modes',
				'description' => 'Auto-discover block storage modes site-wide.',
				'readonly'    => false,
				'permission'  => 'manage',
				'input'       => array( 'type' => 'object' ),
				'execute'     => array( $this, 'execute_get_route' ),
				'handler'     => array( $this->controller, 'scan_storage_modes' ),
				'method'      => 'POST',
				'route'       => '/storage-modes/scan',
			),
			array(
				'slug'        => 'resolve-url',
				'label'       => 'Resolve URL',
				'description' => 'Map a URL or path to a post ID.',
				'readonly'    => true,
				'permission'  => 'read',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'url' => array( 'type' => 'string' ),
					),
					'required'   => array( 'url' ),
				),
				'execute'     => array( $this, 'execute_get_route' ),
				'handler'     => array( $this->controller, 'resolve_url' ),
				'method'      => 'GET',
				'route'       => '/resolve',
			),
			array(
				'slug'        => 'list-posts',
				'label'       => 'List posts',
				'description' => 'Search posts with pagination.',
				'readonly'    => true,
				'permission'  => 'read',
				'input'       => array( 'type' => 'object' ),
				'execute'     => array( $this, 'execute_get_route' ),
				'handler'     => array( $this->controller, 'find_posts' ),
				'method'      => 'GET',
				'route'       => '/find-posts',
			),
			array(
				'slug'        => 'get-post-info',
				'label'       => 'Get post info',
				'description' => 'Look up post metadata by ID, URL, or slug.',
				'readonly'    => true,
				'permission'  => 'read',
				'input'       => array( 'type' => 'object' ),
				'execute'     => array( $this, 'execute_get_route' ),
				'handler'     => array( $this->controller, 'post_info' ),
				'method'      => 'GET',
				'route'       => '/post-info',
			),
			array(
				'slug'        => 'create-post',
				'label'       => 'Create post',
				'description' => 'Create a new post or page.',
				'readonly'    => false,
				'permission'  => 'edit',
				'input'       => array( 'type' => 'object' ),
				'execute'     => array( $this, 'execute_simple_post_body' ),
				'handler'     => array( $this->controller, 'create_post' ),
				'method'      => 'POST',
				'route'       => '/posts',
			),
			array(
				'slug'        => 'update-post',
				'label'       => 'Update post',
				'description' => 'Update post metadata, status, or terms.',
				'readonly'    => false,
				'permission'  => 'edit',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'post_id' ),
				),
				'execute'     => array( $this, 'execute_update_post' ),
			),
			array(
				'slug'        => 'list-terms',
				'label'       => 'List terms',
				'description' => 'List taxonomy terms.',
				'readonly'    => true,
				'permission'  => 'read',
				'input'       => array( 'type' => 'object' ),
				'execute'     => array( $this, 'execute_get_route' ),
				'handler'     => array( $this->controller, 'list_terms' ),
				'method'      => 'GET',
				'route'       => '/terms',
			),
			array(
				'slug'        => 'upload-media',
				'label'       => 'Upload media',
				'description' => 'Upload to the media library via URL or base64.',
				'readonly'    => false,
				'permission'  => 'upload',
				'input'       => array( 'type' => 'object' ),
				'execute'     => array( $this, 'execute_simple_post_body' ),
				'handler'     => array( $this->controller, 'upload_media' ),
				'method'      => 'POST',
				'route'       => '/media',
			),
		);

		foreach ( $abilities as $ability ) {
			$this->register_ability( $ability );
		}
	}

	/**
	 * Register Yoast SEO abilities when Yoast is active.
	 */
	private function register_yoast_abilities() {
		if ( ! Yoast_Bridge::is_yoast_active() ) {
			return;
		}

		$abilities = array(
			array(
				'slug'        => 'yoast-get-seo',
				'label'       => 'Yoast get SEO',
				'description' => 'Read Yoast SEO metadata for a post.',
				'readonly'    => true,
				'permission'  => 'read',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'post_id' ),
				),
				'execute'     => array( $this, 'execute_yoast_get' ),
			),
			array(
				'slug'        => 'yoast-update-seo',
				'label'       => 'Yoast update SEO',
				'description' => 'Update Yoast SEO metadata for a post.',
				'readonly'    => false,
				'permission'  => 'edit',
				'input'       => array( 'type' => 'object' ),
				'execute'     => array( $this, 'execute_yoast_patch' ),
			),
			array(
				'slug'        => 'yoast-bulk-update-seo',
				'label'       => 'Yoast bulk update SEO',
				'description' => 'Bulk-update Yoast SEO metadata.',
				'readonly'    => false,
				'permission'  => 'edit',
				'input'       => array( 'type' => 'object' ),
				'execute'     => array( $this, 'execute_yoast_bulk' ),
			),
		);

		foreach ( $abilities as $ability ) {
			$this->register_ability( $ability );
		}
	}

	/**
	 * Register a single ability from a definition array.
	 *
	 * @param array<string, mixed> $def Ability definition.
	 */
	private function register_ability( array $def ) {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$readonly    = ! empty( $def['readonly'] );
		$destructive = ! empty( $def['destructive'] );

		wp_register_ability(
			self::NAMESPACE_PREFIX . $def['slug'],
			array(
				'label'               => $def['label'],
				'description'         => $def['description'],
				'category'            => self::CATEGORY,
				'input_schema'        => $def['input'],
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => function () use ( $def ) {
					return $this->check_permission( $def['permission'] );
				},
				'execute_callback'    => function ( array $input ) use ( $def ) {
					return call_user_func( $def['execute'], $input, $def );
				},
				'meta'                => array(
					'show_in_rest' => false,
					'mcp'          => array(
						'public'      => true,
						'type'        => 'tool',
						'annotations' => array(
							'readonly'    => $readonly,
							'destructive' => $destructive && ! $readonly,
							'idempotent'  => $readonly,
						),
					),
				),
			)
		);
	}

	/**
	 * Permission gate for an ability.
	 *
	 * @param string $level read|edit|upload|manage.
	 * @return bool|\WP_Error
	 */
	private function check_permission( $level ) {
		switch ( $level ) {
			case 'manage':
				if ( ! current_user_can( 'manage_options' ) ) {
					return new \WP_Error(
						'rest_forbidden',
						__( 'You do not have permission to run this ability.', 'gk-block-mcp' ),
						array( 'status' => 403 )
					);
				}
				return true;
			case 'upload':
				return $this->controller->check_upload_permissions();
			case 'edit':
				return $this->controller->check_edit_permissions();
			case 'read':
			default:
				return $this->controller->check_permissions();
		}
	}

	/**
	 * Generic GET handler.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @param array<string, mixed> $def   Ability definition.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_get_route( array $input, array $def ) {
		$params  = Abilities_Rest_Bridge::normalize_input( $input );
		$request = Abilities_Rest_Bridge::make_request(
			$def['method'],
			REST_Controller::NAMESPACE . $def['route'],
			$params
		);
		return Abilities_Rest_Bridge::invoke( $def['handler'], $request );
	}

	/**
	 * POST handler with post_id in route.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @param array<string, mixed> $def   Ability definition.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_simple_post_body( array $input, array $def ) {
		$post_id = Abilities_Rest_Bridge::require_post_id( $input );
		if ( is_wp_error( $post_id ) ) {
			if ( '/posts' === $def['route'] ) {
				$params  = Abilities_Rest_Bridge::normalize_input( $input );
				$request = Abilities_Rest_Bridge::make_request(
					$def['method'],
					REST_Controller::NAMESPACE . $def['route'],
					$params
				);
				return Abilities_Rest_Bridge::invoke( $def['handler'], $request );
			}
			return $post_id;
		}

		$params  = Abilities_Rest_Bridge::normalize_input( $input );
		$route   = sprintf( REST_Controller::NAMESPACE . $def['route'], $post_id );
		$request = Abilities_Rest_Bridge::make_request( $def['method'], $route, $params );
		return Abilities_Rest_Bridge::invoke( $def['handler'], $request );
	}

	/**
	 * PUT handler with post_id in route.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @param array<string, mixed> $def   Ability definition.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_simple_put_body( array $input, array $def ) {
		$post_id = Abilities_Rest_Bridge::require_post_id( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$params  = Abilities_Rest_Bridge::normalize_input( $input );
		$route   = sprintf( REST_Controller::NAMESPACE . $def['route'], $post_id );
		$request = Abilities_Rest_Bridge::make_request( 'PUT', $route, $params );
		return Abilities_Rest_Bridge::invoke( $def['handler'], $request );
	}

	/**
	 * get_page_blocks — supports post_id or url.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_get_page_blocks( array $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( ! $post_id && ! empty( $input['url'] ) ) {
			$resolved = $this->controller->resolve_url(
				Abilities_Rest_Bridge::make_request(
					'GET',
					REST_Controller::NAMESPACE . '/resolve',
					array( 'url' => $input['url'] )
				)
			);
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$data    = $resolved instanceof \WP_REST_Response ? $resolved->get_data() : $resolved;
			$post_id = isset( $data['post_id'] ) ? absint( $data['post_id'] ) : 0;
		}

		if ( ! $post_id ) {
			return Abilities_Rest_Bridge::validation_error( __( 'Either post_id or url is required.', 'gk-block-mcp' ) );
		}

		$params  = Abilities_Rest_Bridge::normalize_input( $input );
		$params['id'] = $post_id;
		$request = Abilities_Rest_Bridge::make_request(
			'GET',
			REST_Controller::NAMESPACE . '/posts/' . $post_id . '/blocks',
			$params
		);
		return Abilities_Rest_Bridge::invoke( array( $this->controller, 'get_post_blocks' ), $request );
	}

	/**
	 * get_block by ref or flat_index.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_get_block( array $input ) {
		$post_id = Abilities_Rest_Bridge::require_post_id( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$has_ref  = ! empty( $input['ref'] );
		$has_idx  = isset( $input['flat_index'] ) && is_numeric( $input['flat_index'] );
		if ( $has_ref === $has_idx ) {
			return Abilities_Rest_Bridge::validation_error( __( 'Provide exactly one of ref or flat_index.', 'gk-block-mcp' ) );
		}

		$params  = Abilities_Rest_Bridge::normalize_input( $input );
		$params['id'] = $post_id;
		$request = Abilities_Rest_Bridge::make_request(
			'GET',
			REST_Controller::NAMESPACE . '/posts/' . $post_id . '/block',
			$params
		);
		return Abilities_Rest_Bridge::invoke( array( $this->controller, 'get_block' ), $request );
	}

	/**
	 * update_block by ref or flat_index.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_update_block( array $input ) {
		$post_id = Abilities_Rest_Bridge::require_post_id( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$has_ref = ! empty( $input['ref'] );
		$has_idx = isset( $input['flat_index'] ) && is_numeric( $input['flat_index'] );
		if ( ! $has_ref && ! $has_idx ) {
			return Abilities_Rest_Bridge::validation_error( __( 'Provide either flat_index or ref.', 'gk-block-mcp' ) );
		}
		if ( $has_ref && $has_idx ) {
			return Abilities_Rest_Bridge::validation_error( __( 'Provide flat_index OR ref, not both.', 'gk-block-mcp' ) );
		}
		if ( empty( $input['attributes'] ) && ! isset( $input['innerHTML'] ) ) {
			return Abilities_Rest_Bridge::validation_error( __( 'At least one of attributes or innerHTML must be provided.', 'gk-block-mcp' ) );
		}

		$body = array();
		if ( isset( $input['attributes'] ) ) {
			$body['attributes'] = $input['attributes'];
		}
		if ( isset( $input['innerHTML'] ) ) {
			$body['innerHTML'] = $input['innerHTML'];
		}

		if ( $has_ref ) {
			$route = REST_Controller::NAMESPACE . '/posts/' . $post_id . '/blocks/by-ref/' . rawurlencode( (string) $input['ref'] );
			$request = Abilities_Rest_Bridge::make_request( 'PATCH', $route, array_merge( array( 'id' => $post_id, 'ref' => $input['ref'] ), $body ) );
			return Abilities_Rest_Bridge::invoke( array( $this->controller, 'update_block_by_ref' ), $request );
		}

		$index   = absint( $input['flat_index'] );
		$route   = REST_Controller::NAMESPACE . '/posts/' . $post_id . '/blocks/' . $index;
		$request = Abilities_Rest_Bridge::make_request(
			'PATCH',
			$route,
			array_merge(
				array(
					'id'    => $post_id,
					'index' => $index,
				),
				$body
			)
		);
		return Abilities_Rest_Bridge::invoke( array( $this->controller, 'update_block' ), $request );
	}

	/**
	 * insert_blocks with MCP position aliases.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_insert_blocks( array $input ) {
		$post_id = Abilities_Rest_Bridge::require_post_id( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( empty( $input['blocks'] ) || ! is_array( $input['blocks'] ) ) {
			return Abilities_Rest_Bridge::validation_error( __( 'blocks must be a non-empty array.', 'gk-block-mcp' ) );
		}

		$params  = Abilities_Rest_Bridge::normalize_input( $input );
		$params['id'] = $post_id;
		$request = Abilities_Rest_Bridge::make_request(
			'POST',
			REST_Controller::NAMESPACE . '/posts/' . $post_id . '/blocks',
			$params
		);
		return Abilities_Rest_Bridge::invoke( array( $this->controller, 'insert_blocks' ), $request );
	}

	/**
	 * delete_block by ref or top_level_counter.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_delete_block( array $input ) {
		$post_id = Abilities_Rest_Bridge::require_post_id( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$has_ref  = ! empty( $input['ref'] );
		$has_idx  = isset( $input['top_level_counter'] ) && is_numeric( $input['top_level_counter'] );
		if ( ! $has_ref && ! $has_idx ) {
			return Abilities_Rest_Bridge::validation_error( __( 'Provide either top_level_counter or ref.', 'gk-block-mcp' ) );
		}
		if ( $has_ref && $has_idx ) {
			return Abilities_Rest_Bridge::validation_error( __( 'Provide top_level_counter OR ref, not both.', 'gk-block-mcp' ) );
		}

		$count = isset( $input['count'] ) ? absint( $input['count'] ) : 1;

		if ( $has_ref ) {
			$route = REST_Controller::NAMESPACE . '/posts/' . $post_id . '/blocks/by-ref/' . rawurlencode( (string) $input['ref'] );
			$request = Abilities_Rest_Bridge::make_request(
				'DELETE',
				$route,
				array(
					'id'    => $post_id,
					'ref'   => $input['ref'],
					'count' => $count,
				)
			);
			return Abilities_Rest_Bridge::invoke( array( $this->controller, 'delete_block_by_ref' ), $request );
		}

		$index   = absint( $input['top_level_counter'] );
		$route   = REST_Controller::NAMESPACE . '/posts/' . $post_id . '/blocks/' . $index;
		$request = Abilities_Rest_Bridge::make_request(
			'DELETE',
			$route,
			array(
				'id'    => $post_id,
				'index' => $index,
				'count' => $count,
			)
		);
		return Abilities_Rest_Bridge::invoke( array( $this->controller, 'delete_block' ), $request );
	}

	/**
	 * insert_pattern.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_insert_pattern( array $input ) {
		$post_id = Abilities_Rest_Bridge::require_post_id( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( ! isset( $input['pattern_id'] ) ) {
			return Abilities_Rest_Bridge::validation_error( __( 'pattern_id is required.', 'gk-block-mcp' ) );
		}

		$params = Abilities_Rest_Bridge::normalize_input( $input );
		$params['id'] = $post_id;
		$request = Abilities_Rest_Bridge::make_request(
			'POST',
			REST_Controller::NAMESPACE . '/posts/' . $post_id . '/insert-pattern',
			$params
		);
		return Abilities_Rest_Bridge::invoke( array( $this->controller, 'insert_pattern' ), $request );
	}

	/**
	 * get_pattern by ID.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_get_pattern( array $input ) {
		if ( ! isset( $input['pattern_id'] ) ) {
			return Abilities_Rest_Bridge::validation_error( __( 'pattern_id is required.', 'gk-block-mcp' ) );
		}

		$params  = Abilities_Rest_Bridge::normalize_input( $input );
		$pattern = $params['id'];
		$request = Abilities_Rest_Bridge::make_request(
			'GET',
			REST_Controller::NAMESPACE . '/patterns/' . rawurlencode( (string) $pattern ),
			array( 'id' => $pattern )
		);
		return Abilities_Rest_Bridge::invoke( array( $this->controller, 'get_pattern' ), $request );
	}

	/**
	 * update_post by post_id.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_update_post( array $input ) {
		$post_id = Abilities_Rest_Bridge::require_post_id( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$params  = Abilities_Rest_Bridge::normalize_input( $input );
		$params['id'] = $post_id;
		$request = Abilities_Rest_Bridge::make_request(
			'PATCH',
			REST_Controller::NAMESPACE . '/posts/' . $post_id,
			$params
		);
		return Abilities_Rest_Bridge::invoke( array( $this->controller, 'update_post' ), $request );
	}

	/**
	 * yoast_get_seo.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_yoast_get( array $input ) {
		$post_id = Abilities_Rest_Bridge::require_post_id( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$request = Abilities_Rest_Bridge::make_request(
			'GET',
			REST_Controller::NAMESPACE . '/yoast/' . $post_id,
			array( 'post_id' => $post_id )
		);
		return Abilities_Rest_Bridge::invoke( array( $this->yoast, 'get_seo' ), $request );
	}

	/**
	 * yoast_update_seo.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_yoast_patch( array $input ) {
		$post_id = Abilities_Rest_Bridge::require_post_id( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$params  = Abilities_Rest_Bridge::normalize_input( $input );
		$params['post_id'] = $post_id;
		$request = Abilities_Rest_Bridge::make_request(
			'PATCH',
			REST_Controller::NAMESPACE . '/yoast/' . $post_id,
			$params
		);
		return Abilities_Rest_Bridge::invoke( array( $this->yoast, 'update_seo' ), $request );
	}

	/**
	 * yoast_bulk_update_seo.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_yoast_bulk( array $input ) {
		$request = Abilities_Rest_Bridge::make_request(
			'PATCH',
			REST_Controller::NAMESPACE . '/yoast/bulk',
			$input
		);
		return Abilities_Rest_Bridge::invoke( array( $this->yoast, 'bulk_update_seo' ), $request );
	}
}
