<?php
/**
 * REST bridge for WordPress Abilities API execute callbacks.
 *
 * Builds WP_REST_Request objects, normalizes MCP-style input to REST route
 * params, and unwraps handler responses for ability consumers.
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
 * Thin bridge between Abilities API execute callbacks and REST_Controller handlers.
 *
 * @since 2.0.4
 */
class Abilities_Rest_Bridge {

	/**
	 * Map MCP-style parameter names to REST route parameter names.
	 *
	 * @var array<string, string>
	 */
	const INPUT_ALIASES = array(
		'post_id'           => 'id',
		'pattern_id'        => 'id',
		'after_top_level'   => 'after',
		'before_top_level'  => 'before',
		'top_level_counter' => 'index',
		'flat_index'        => 'index',
	);

	/**
	 * Normalize ability input to REST parameter names.
	 *
	 * @param array<string, mixed> $input Raw ability input.
	 * @return array<string, mixed>
	 */
	public static function normalize_input( array $input ) {
		$params = array();
		foreach ( $input as $key => $value ) {
			$rest_key            = isset( self::INPUT_ALIASES[ $key ] ) ? self::INPUT_ALIASES[ $key ] : $key;
			$params[ $rest_key ] = $value;
		}
		return $params;
	}

	/**
	 * Build a REST request for a handler invocation.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  REST route path (e.g. /gk-block-api/v1/block-types).
	 * @param array<string, mixed> $params Request parameters.
	 * @return \WP_REST_Request
	 */
	public static function make_request( $method, $route, array $params = array() ) {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Invoke a REST handler and return response data or WP_Error.
	 *
	 * @param callable             $handler REST_Controller or Yoast_Bridge method.
	 * @param \WP_REST_Request     $request Prepared request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function invoke( $handler, \WP_REST_Request $request ) {
		$result = call_user_func( $handler, $request );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $result instanceof \WP_REST_Response ) {
			return $result->get_data();
		}

		if ( is_array( $result ) ) {
			return $result;
		}

		return array( 'result' => $result );
	}

	/**
	 * Return a validation WP_Error for ability execute callbacks.
	 *
	 * @param string $message Human-readable message.
	 * @return \WP_Error
	 */
	public static function validation_error( $message ) {
		return new \WP_Error(
			'invalid_input',
			$message,
			array( 'status' => 400 )
		);
	}

	/**
	 * Require a non-empty post ID from normalized or raw input.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return int|\WP_Error
	 */
	public static function require_post_id( array $input ) {
		$post_id = null;
		if ( isset( $input['post_id'] ) ) {
			$post_id = absint( $input['post_id'] );
		} elseif ( isset( $input['id'] ) ) {
			$post_id = absint( $input['id'] );
		}

		if ( ! $post_id ) {
			return self::validation_error( __( 'post_id is required.', 'gk-block-mcp' ) );
		}

		return $post_id;
	}
}
