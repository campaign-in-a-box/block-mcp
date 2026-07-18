<?php
/**
 * Abilities API registration tests.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Abilities_Registrar;
use GravityKit\BlockMCP\REST_Controller;

/**
 * @covers \GravityKit\BlockMCP\Abilities_Registrar
 */
class AbilitiesRegistrarTest extends RestControllerTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->trigger_abilities_init();
	}

	/**
	 * Fire wp_abilities_api_init so the plugin registers its abilities.
	 */
	private function trigger_abilities_init(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API is not available in this environment.' );
		}
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	public function test_registers_core_abilities(): void {
		$this->assertTrue( wp_has_ability( 'gk-block-mcp/get-page-blocks' ) );
		$this->assertTrue( wp_has_ability( 'gk-block-mcp/update-block' ) );
		$this->assertTrue( wp_has_ability( 'gk-block-mcp/list-block-types' ) );
	}

	public function test_get_page_blocks_denied_for_subscriber(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$ability = wp_get_ability( 'gk-block-mcp/get-page-blocks' );
		$this->assertNotNull( $ability );

		$allowed = $ability->check_permissions( array( 'post_id' => 1 ) );
		$this->assertWPError( $allowed );
	}

	public function test_get_page_blocks_executes_for_editor(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$post_id = $this->make_block_post(
			array(
				array(
					'blockName' => 'core/paragraph',
					'attrs'     => array(),
					'innerHTML' => '<p>Abilities test</p>',
				),
			)
		);

		$ability = wp_get_ability( 'gk-block-mcp/get-page-blocks' );
		$this->assertNotNull( $ability );

		$result = $ability->execute( array( 'post_id' => $post_id ) );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'blocks', $result );
		$this->assertNotEmpty( $result['blocks'] );
	}

	public function test_update_block_requires_target(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$post_id = $this->make_block_post(
			array(
				array(
					'blockName' => 'core/paragraph',
					'attrs'     => array(),
					'innerHTML' => '<p>Before</p>',
				),
			)
		);

		$ability = wp_get_ability( 'gk-block-mcp/update-block' );
		$this->assertNotNull( $ability );

		$result = $ability->execute(
			array(
				'post_id'    => $post_id,
				'attributes' => array( 'content' => 'After' ),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_input', $result->get_error_code() );
	}

	/**
	 * MCP flat params must reach Yoast_Bridge::update_seo() as a JSON body.
	 *
	 * @group yoast
	 */
	public function test_yoast_update_seo_accepts_flat_mcp_params(): void {
		if ( ! defined( 'WPSEO_FILE' ) ) {
			$this->markTestSkipped( 'Yoast SEO is not loaded.' );
		}

		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$ability = wp_get_ability( 'gk-block-mcp/yoast-update-seo' );
		$this->assertNotNull( $ability );

		$result = $ability->execute(
			array(
				'post_id'     => $post_id,
				'title'       => 'MCP SEO Title',
				'description' => 'MCP meta description.',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'MCP SEO Title', $result['title'] );
		$this->assertSame( 'MCP meta description.', $result['description'] );
	}

	/**
	 * Bulk ability accepts MCP-style `items` alias for REST `posts`.
	 *
	 * @group yoast
	 */
	public function test_yoast_bulk_update_seo_accepts_items_alias(): void {
		if ( ! defined( 'WPSEO_FILE' ) ) {
			$this->markTestSkipped( 'Yoast SEO is not loaded.' );
		}

		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$ability = wp_get_ability( 'gk-block-mcp/yoast-bulk-update-seo' );
		$this->assertNotNull( $ability );

		$result = $ability->execute(
			array(
				'items' => array(
					array(
						'post_id'     => $post_id,
						'title'       => 'Bulk MCP Title',
						'description' => 'Bulk MCP description.',
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'Bulk MCP Title', $result[0]['title'] );
		$this->assertSame( 'Bulk MCP description.', $result[0]['description'] );
	}

	/**
	 * upload-media schema must advertise Media_Manager keys (data_base64 / alt_text).
	 */
	public function test_upload_media_schema_uses_media_manager_keys(): void {
		$ability = wp_get_ability( 'gk-block-mcp/upload-media' );
		$this->assertNotNull( $ability );

		$schema     = $ability->get_input_schema();
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();

		$this->assertArrayHasKey( 'data_base64', $properties );
		$this->assertArrayHasKey( 'alt_text', $properties );
		$this->assertArrayHasKey( 'url', $properties );
		$this->assertArrayHasKey( 'filename', $properties );
	}

	/**
	 * Legacy ability param names must normalize to Media_Manager keys.
	 */
	public function test_upload_media_aliases_normalize_to_media_manager_keys(): void {
		$normalized = \GravityKit\BlockMCP\Abilities_Rest_Bridge::normalize_input(
			array(
				'base64' => 'Zm9v',
				'alt'    => 'legacy alt',
			)
		);

		$this->assertSame( 'Zm9v', $normalized['data_base64'] );
		$this->assertSame( 'legacy alt', $normalized['alt_text'] );
		$this->assertArrayNotHasKey( 'base64', $normalized );
		$this->assertArrayNotHasKey( 'alt', $normalized );
	}
}
