<?php
/**
 * Media library uploads.
 *
 * Three single-shot input modes (mutually exclusive — exactly one required):
 *  1. multipart form-data — caller sets $args['file_field'] to the form-data
 *     field name; the file appears in $_FILES.
 *  2. URL sideload — caller passes $args['url']. Server downloads via WP HTTP
 *     and writes to uploads.
 *  3. Base64 inline — caller passes $args['data_base64'] (and required
 *     $args['filename']). Decoded server-side, written to a temp file,
 *     then sideloaded. Practical only for tiny files when the caller is an
 *     LLM (tool-arg truncation yields invalid_base64); prefer url or the
 *     chunked begin/chunk/finish flow for anything larger.
 *
 * Chunked base64 (begin → chunk → finish) stores decoded binary pieces under
 * uploads/gk-block-mcp-uploads/{upload_id}/, verifies an end-to-end MD5 on
 * finish, then sideloads. Sessions wipe on finish/abort and on TTL expiry
 * when next accessed.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Media_Manager
 *
 * Handles media uploads via multipart, URL sideload, and base64 with SSRF defense.
 */
class Media_Manager {

	/** Default size cap for URL sideloads / base64 / chunked assemble (25 MB). */
	const URL_DOWNLOAD_MAX_BYTES = 26214400;

	/** Chunked upload session TTL (seconds). Expired sessions delete on next access. */
	const CHUNKED_UPLOAD_TTL = 3600;

	/** Subdir under uploads for in-flight chunked sessions. */
	const CHUNKED_UPLOAD_DIR = 'gk-block-mcp-uploads';

	/**
	 * Reserved IP ranges to block on URL sideload (SSRF defense).
	 * Includes link-local, loopback, multicast, RFC1918 private ranges,
	 * and IPv6 unique-local + link-local. Overrideable by site admins
	 * via the `gk/block-mcp/media/sideload-blocked-ranges` filter.
	 */
	const SSRF_BLOCKED_IPV4_RANGES = array(
		array( '0.0.0.0', '0.255.255.255' ),       // "This network."
		array( '10.0.0.0', '10.255.255.255' ),      // RFC1918.
		array( '127.0.0.0', '127.255.255.255' ),    // Loopback.
		array( '169.254.0.0', '169.254.255.255' ),  // Link-local (AWS/GCP/Azure metadata).
		array( '172.16.0.0', '172.31.255.255' ),    // RFC1918.
		array( '192.0.0.0', '192.0.0.255' ),        // IETF reserved.
		array( '192.168.0.0', '192.168.255.255' ),  // RFC1918.
		array( '198.18.0.0', '198.19.255.255' ),    // Benchmark.
		array( '224.0.0.0', '255.255.255.255' ),    // Multicast + reserved.
	);

	/**
	 * Option key for the global uploads kill-switch.
	 *
	 * When the option is set to a falsy value, the plugin refuses every
	 * upload mode (multipart, URL sideload, base64) with HTTP 403
	 * `uploads_disabled` before any file I/O or DNS resolution happens.
	 */
	const UPLOADS_OPTION = 'gk_block_api_uploads_enabled';

	/**
	 * Whether MCP-driven media uploads are permitted on this site.
	 *
	 * Default: true (uploads allowed). Site owners can flip this to a
	 * read-only block surface in two ways:
	 *
	 *   1. Programmatic: `update_option( 'gk_block_api_uploads_enabled', false );`
	 *   2. Filter:       `add_filter( 'gk/block-mcp/media/uploads-enabled', '__return_false' );`
	 *
	 * The filter wins over the option so emergencies (e.g., a
	 * compromised API token) can be patched without writing to the DB.
	 *
	 * @return bool
	 */
	public static function uploads_enabled(): bool {
		// Stored as the string '0' or '1' (not a PHP bool) — update_option()
		// silently no-ops when storing boolean false against a missing key
		// because get_option() returns false as both the missing-default and
		// the actual value, and update_option's equality check short-circuits
		// before any DB write. The string form avoids that ambiguity.
		$raw     = get_option( self::UPLOADS_OPTION, '1' );
		$enabled = ( '0' !== (string) $raw && false !== $raw );
		/**
		 * Flip the AI's media library access to read-only in one line.
		 *
		 * There's a setting for this, but the filter is the emergency brake: it
		 * wins over the stored option and needs no database write, so you can
		 * shut off every MCP upload instantly — from code, a must-use plugin, or
		 * an incident-response hook — the moment a token looks compromised or a
		 * policy says "no agent-uploaded files." Return false to refuse all
		 * uploads (multipart, URL sideload, and base64) with a clean 403.
		 *
		 * @since 2.0.0
		 *
		 * @example
		 * // Make the media library read-only for the AI agent.
		 * add_filter( 'gk/block-mcp/media/uploads-enabled', '__return_false' );
		 *
		 * @param bool $enabled Whether uploads are currently allowed by the stored option.
		 */
		return (bool) apply_filters( 'gk/block-mcp/media/uploads-enabled', $enabled );
	}

	/**
	 * Upload media to the WordPress media library.
	 *
	 * @param array $args See docs/specs/2026-04-27-docs-lifecycle-tools.md §3.4.
	 * @return array|\WP_Error
	 */
	public function upload( array $args ) {
		// Hard kill-switch. Site owners can disable every MCP-driven upload
		// path (multipart / URL sideload / base64) with a single setting —
		// useful when an integrator wants the plugin's block-editing surface
		// but doesn't trust agents to write to the media library.
		if ( ! self::uploads_enabled() ) {
			return new \WP_Error(
				'uploads_disabled',
				__( 'Media uploads via the block API are disabled on this site.', 'gk-block-mcp' ),
				array( 'status' => 403 )
			);
		}

		$this->require_admin_includes();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST endpoint is authenticated via Application Password; no nonce expected.
		$has_multipart = ! empty( $args['file_field'] )
			&& isset( $_FILES[ $args['file_field'] ] )    // phpcs:ignore WordPress.Security.NonceVerification.Missing
			&& ! empty( $_FILES[ $args['file_field'] ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$has_url       = ! empty( $args['url'] ) && is_string( $args['url'] );
		$has_base64    = ! empty( $args['data_base64'] ) && is_string( $args['data_base64'] );

		$mode_count = (int) $has_multipart + (int) $has_url + (int) $has_base64;
		if ( 0 === $mode_count ) {
			return new \WP_Error(
				'missing_file',
				__( 'Provide one of: multipart "file" field, "url", or "data_base64".', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}
		if ( $mode_count > 1 ) {
			return new \WP_Error(
				'multiple_inputs',
				__( 'Only one of "file", "url", or "data_base64" may be supplied.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		if ( $has_multipart ) {
			$attachment_id = $this->handle_multipart( $args );
		} elseif ( $has_url ) {
			$attachment_id = $this->handle_url( $args );
		} else {
			$attachment_id = $this->handle_base64( $args );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$this->apply_metadata( (int) $attachment_id, $args );

		return $this->format_attachment( (int) $attachment_id );
	}

	/**
	 * Handle a multipart file upload from $_FILES.
	 *
	 * @param array $args Upload arguments including file_field and optional post_id.
	 * @return int|\WP_Error Attachment ID or WP_Error.
	 */
	private function handle_multipart( array $args ) {
		$field       = $args['file_field'];
		$post_parent = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST endpoint authenticated via Application Password; no nonce expected.
		if ( ! isset( $_FILES[ $field ] ) ) {
			return new \WP_Error( 'no_file', __( 'No file uploaded.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}
		$file = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller is REST-authenticated.

		// PHP transport-level error check. UPLOAD_ERR_INI_SIZE / FORM_SIZE /
		// PARTIAL / NO_FILE / NO_TMP_DIR / CANT_WRITE / EXTENSION can leave
		// $file['tmp_name'] empty or partial, which would crash the
		// downstream wp_check_filetype_and_ext() / media_handle_upload()
		// calls with confusing errors. Surface a clean 400 instead.
		$err_code = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $err_code ) {
			if ( ! empty( $file['tmp_name'] ) ) {
				wp_delete_file( $file['tmp_name'] );
			}
			return new \WP_Error(
				'upload_error',
				sprintf( /* translators: %d: PHP UPLOAD_ERR_* code */ __( 'File upload failed (PHP code %d).', 'gk-block-mcp' ), $err_code ),
				array(
					'status'           => 400,
					'php_upload_error' => $err_code,
				)
			);
		}

		// Enforce site upload-size limit before move/copy. media_handle_upload
		// will also enforce, but failing fast keeps us out of the WP error path.
		$max = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
		if ( $max > 0 && isset( $file['size'] ) && (int) $file['size'] > $max ) {
			wp_delete_file( $file['tmp_name'] );
			return new \WP_Error(
				'file_too_large',
				__( 'Uploaded file exceeds the site upload limit.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		// Defensive MIME check before media_handle_upload runs its own. Catches
		// disallowed types early so the temp file isn't moved into uploads.
		$mime = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( empty( $mime['type'] ) ) {
			wp_delete_file( $file['tmp_name'] );
			return new \WP_Error(
				'disallowed_mime',
				sprintf( /* translators: %s: filename */ __( 'Disallowed file type for "%s".', 'gk-block-mcp' ), sanitize_file_name( $file['name'] ) ),
				array( 'status' => 400 )
			);
		}

		$default_overrides = array( 'test_form' => false );

		/**
		 * Fine-tune how WordPress handles an agent's multipart file upload.
		 *
		 * Every file the agent uploads through `POST /media` is finally handed
		 * to core's `wp_handle_upload()`, and this array is the control panel for
		 * that step. Hook in when you need to bend the rules for a specific
		 * deployment — relax a check, accept an extra MIME type, or route the
		 * file through a different upload action. The value is forwarded verbatim
		 * as the overrides argument to `media_handle_upload()`. Most sites should
		 * leave the default `array( 'test_form' => false )` alone; if a filter
		 * returns anything that isn't an array, the plugin safely falls back to
		 * that default.
		 *
		 * @since 2.0.0
		 *
		 * @example
		 * // Permit an extra file type for agent uploads.
		 * add_filter( 'gk/block-mcp/media/upload-overrides', function ( $overrides, $field ) {
		 *     $overrides['mimes'] = array( 'webp' => 'image/webp' );
		 *     return $overrides;
		 * }, 10, 2 );
		 *
		 * @param array  $default_overrides Overrides passed to media_handle_upload(). Default array( 'test_form' => false ).
		 * @param string $field             The $_FILES key whose upload is being processed.
		 */
		$overrides = apply_filters(
			'gk/block-mcp/media/upload-overrides',
			$default_overrides,
			$field
		);

		if ( ! is_array( $overrides ) ) {
			$overrides = $default_overrides;
		}

		return media_handle_upload( $field, $post_parent, array(), $overrides );
	}

	/**
	 * Handle a URL sideload upload.
	 *
	 * @param array $args Upload arguments including url and optional post_id.
	 * @return int|\WP_Error Attachment ID or WP_Error.
	 */
	private function handle_url( array $args ) {
		$url = esc_url_raw( $args['url'] );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'invalid_url', __( 'URL is not valid or not allowed.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		// SSRF defense: resolve host and reject reserved/private/link-local IPs.
		// `wp_http_validate_url()` only catches loopback/0.0.0.0; cloud metadata
		// endpoints (169.254.169.254) and RFC1918 private ranges sail past it.
		$ssrf_check = $this->guard_ssrf( $url );
		if ( is_wp_error( $ssrf_check ) ) {
			return $ssrf_check;
		}

		// Use a tighter timeout than core's 300s default. Slow-source amplification
		// drops to ~10s of resource hold per request.
		$tmp = download_url( $url, 10 );
		if ( is_wp_error( $tmp ) ) {
			return new \WP_Error( 'url_fetch_failed', $tmp->get_error_message(), array( 'status' => 502 ) );
		}

		if ( filesize( $tmp ) > self::URL_DOWNLOAD_MAX_BYTES ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'file_too_large', __( 'Downloaded file exceeds size cap.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$path     = wp_parse_url( $url, PHP_URL_PATH );
		$basename = $path ? basename( (string) $path ) : 'remote-file';
		$filename = isset( $args['filename'] )
			? sanitize_file_name( (string) $args['filename'] )
			: sanitize_file_name( $basename );

		$mime = wp_check_filetype_and_ext( $tmp, $filename );
		if ( empty( $mime['type'] ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'disallowed_mime', sprintf( /* translators: %s: filename */ __( 'Disallowed file type for "%s".', 'gk-block-mcp' ), $filename ), array( 'status' => 400 ) );
		}

		$post_parent   = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$file          = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);
		$attachment_id = media_handle_sideload( $file, $post_parent );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'sideload_failed', $attachment_id->get_error_message(), array( 'status' => 500 ) );
		}
		return $attachment_id;
	}

	/**
	 * Handle a base64-encoded file upload.
	 *
	 * @param array $args Upload arguments including data_base64, filename, and optional post_id.
	 * @return int|\WP_Error Attachment ID or WP_Error.
	 */
	private function handle_base64( array $args ) {
		if ( empty( $args['filename'] ) ) {
			return new \WP_Error( 'invalid_filename', __( '"filename" is required for base64 uploads.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$decoded = $this->decode_base64_payload( (string) $args['data_base64'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$cap_err = $this->enforce_decoded_size_cap( $decoded );
		if ( is_wp_error( $cap_err ) ) {
			return $cap_err;
		}

		return $this->sideload_decoded_bytes(
			$decoded,
			sanitize_file_name( (string) $args['filename'] ),
			isset( $args['post_id'] ) ? (int) $args['post_id'] : 0
		);
	}

	/**
	 * Normalize and decode a base64 payload (data-URI + whitespace tolerant).
	 *
	 * @param string $raw Caller-supplied base64 (or data:…;base64,…).
	 * @return string|\WP_Error Decoded binary bytes or WP_Error.
	 */
	private function decode_base64_payload( $raw ) {
		$normalized = $this->normalize_base64_payload( $raw );
		if ( '' === $normalized ) {
			return new \WP_Error(
				'invalid_base64',
				__( 'data_base64 is empty after normalization. If this was a larger file, the payload was likely truncated in transit — prefer url sideload or the chunked upload-media-begin/chunk/finish flow.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		// Bound the encoded payload BEFORE decoding to limit memory consumption.
		$encoded_max = (int) ceil( self::URL_DOWNLOAD_MAX_BYTES * 4 / 3 );
		if ( strlen( $normalized ) > $encoded_max ) {
			return new \WP_Error(
				'file_too_large',
				__( 'data_base64 exceeds size cap before decoding.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$decoded = base64_decode( $normalized, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Caller-supplied base64 payload from REST / MCP.
		if ( false === $decoded || '' === $decoded ) {
			return new \WP_Error(
				'invalid_base64',
				__( 'data_base64 is not valid base64 (often truncated by an LLM tool-call size limit). Prefer url sideload, or upload-media-begin → upload-media-chunk → upload-media-finish with content_md5 for larger files.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		return $decoded;
	}

	/**
	 * Strip data-URI prefix and whitespace from a base64 string.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	private function normalize_base64_payload( $raw ) {
		$raw = (string) $raw;
		if ( preg_match( '/^data:[^;]*;base64,/i', $raw ) ) {
			$raw = (string) preg_replace( '/^data:[^;]*;base64,/i', '', $raw, 1 );
		}
		return preg_replace( '/\s+/', '', $raw ) ?? '';
	}

	/**
	 * Enforce URL_DOWNLOAD_MAX_BYTES and wp_max_upload_size on decoded bytes.
	 *
	 * @param string $decoded Binary payload.
	 * @return true|\WP_Error
	 */
	private function enforce_decoded_size_cap( $decoded ) {
		$max = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
		$cap = $max > 0 ? min( $max, self::URL_DOWNLOAD_MAX_BYTES ) : self::URL_DOWNLOAD_MAX_BYTES;
		if ( strlen( $decoded ) > $cap ) {
			return new \WP_Error( 'file_too_large', __( 'Decoded data exceeds size cap.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Write decoded bytes to a temp file and media_handle_sideload them.
	 *
	 * @param string $decoded  Binary file contents.
	 * @param string $filename Sanitized filename.
	 * @param int    $post_parent Parent post ID (0 = none).
	 * @return int|\WP_Error Attachment ID or WP_Error.
	 */
	private function sideload_decoded_bytes( $decoded, $filename, $post_parent = 0 ) {
		$tmp = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return new \WP_Error( 'sideload_failed', __( 'Could not create temp file.', 'gk-block-mcp' ), array( 'status' => 500 ) );
		}
		$bytes_written = file_put_contents( $tmp, $decoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temp file written before media_handle_sideload moves it to the upload dir.
		if ( false === $bytes_written ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'sideload_failed', __( 'Could not write temp file.', 'gk-block-mcp' ), array( 'status' => 500 ) );
		}

		$mime = wp_check_filetype_and_ext( $tmp, $filename );
		if ( empty( $mime['type'] ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'disallowed_mime', sprintf( /* translators: %s: filename */ __( 'Disallowed file type for "%s".', 'gk-block-mcp' ), $filename ), array( 'status' => 400 ) );
		}

		$file          = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);
		$attachment_id = media_handle_sideload( $file, $post_parent );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'sideload_failed', $attachment_id->get_error_message(), array( 'status' => 500 ) );
		}
		return $attachment_id;
	}

	/**
	 * Apply title, caption, description, and alt text metadata to an attachment.
	 *
	 * @param int   $attachment_id Attachment post ID.
	 * @param array $args          Upload arguments potentially containing title, caption, description, and alt_text.
	 */
	private function apply_metadata( $attachment_id, array $args ) {
		$updates = array( 'ID' => $attachment_id );
		if ( isset( $args['title'] ) ) {
			$updates['post_title'] = sanitize_text_field( (string) $args['title'] );
		}
		if ( isset( $args['caption'] ) ) {
			$updates['post_excerpt'] = sanitize_text_field( (string) $args['caption'] );
		}
		if ( isset( $args['description'] ) ) {
			$updates['post_content'] = wp_kses_post( (string) $args['description'] );
		}
		if ( count( $updates ) > 1 ) {
			// wp_update_post() runs wp_unslash() on string fields (expects
			// $_POST-shaped slashed input). Our sanitized values are
			// already unslashed, so wp_slash them first — without this
			// every backslash-escape (\n in caption, \" in description,
			// `<a href=\"…\">` in kses-allowed markup) would be stripped
			// of its leading backslash. Matches save_post_content's
			// handling of block markup in Block_Writer.
			wp_update_post( wp_slash( $updates ) );
		}
		if ( isset( $args['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $args['alt_text'] ) );
		}
	}

	/**
	 * Build the response array for a newly uploaded attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array|\WP_Error
	 */
	private function format_attachment( $attachment_id ) {
		$post = get_post( $attachment_id );
		if ( ! $post ) {
			return new \WP_Error( 'attachment_missing', __( 'Attachment not found after upload.', 'gk-block-mcp' ), array( 'status' => 500 ) );
		}
		$meta     = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $attachment_id ) : array();
		$src      = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $attachment_id ) : '';
		$filename = function_exists( 'get_attached_file' )
			? wp_basename( (string) get_attached_file( $attachment_id ) )
			: '';

		$out = array(
			'success'     => true,
			'id'          => (int) $attachment_id,
			'title'       => $post->post_title,
			'filename'    => $filename,
			'url'         => $src,
			'source_url'  => $src,
			'mime_type'   => $post->post_mime_type,
			'alt_text'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'post_parent' => (int) $post->post_parent,
		);

		if ( is_array( $meta ) && isset( $meta['width'], $meta['height'] ) ) {
			$out['width']  = (int) $meta['width'];
			$out['height'] = (int) $meta['height'];
			$out['sizes']  = array();
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( array_keys( $meta['sizes'] ) as $size_name ) {
					$src_arr = function_exists( 'wp_get_attachment_image_src' )
						? wp_get_attachment_image_src( $attachment_id, $size_name )
						: false;
					if ( $src_arr ) {
						$out['sizes'][ $size_name ] = array(
							'url'    => $src_arr[0],
							'width'  => (int) $src_arr[1],
							'height' => (int) $src_arr[2],
						);
					}
				}
			}
			$full = function_exists( 'wp_get_attachment_image_src' )
				? wp_get_attachment_image_src( $attachment_id, 'full' )
				: false;
			if ( $full ) {
				$out['sizes']['full'] = array(
					'url'    => $full[0],
					'width'  => (int) $full[1],
					'height' => (int) $full[2],
				);
			}
		}

		return $out;
	}

	/**
	 * SSRF defense for URL sideload. Rejects URLs whose host resolves to a
	 * reserved/private/link-local IP. Cloud metadata endpoints (AWS/GCP/Azure
	 * `169.254.169.254`) and RFC1918 ranges are explicitly blocked.
	 *
	 * Site admins can extend the block list via the
	 * `gk/block-mcp/media/sideload-blocked-ranges` filter (returns array of
	 * `[start, end]` pairs in IPv4 dotted notation).
	 *
	 * @param string $url URL to validate against reserved IP ranges.
	 * @return true|\WP_Error
	 */
	private function guard_ssrf( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return new \WP_Error( 'invalid_url', __( 'URL has no host.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		// Resolve A and AAAA records — both IPv4 and IPv6 must be checked.
		// A host that resolves to both a public IPv4 and link-local IPv6
		// could otherwise bypass the guard (cURL may pick IPv6 by default).
		$ipv4 = array();
		$ipv6 = array();
		if ( function_exists( 'dns_get_record' ) ) {
			$a_records = @dns_get_record( $host, DNS_A ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $a_records ) ) {
				foreach ( $a_records as $r ) {
					if ( ! empty( $r['ip'] ) ) {
						$ipv4[] = $r['ip'];
					}
				}
			}
			$aaaa_records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $aaaa_records ) ) {
				foreach ( $aaaa_records as $r ) {
					if ( ! empty( $r['ipv6'] ) ) {
						$ipv6[] = $r['ipv6'];
					}
				}
			}
		}
		// Last-resort IPv4 fallback (gethostbyname is IPv4-only).
		if ( empty( $ipv4 ) && empty( $ipv6 ) ) {
			$resolved = @gethostbyname( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $resolved && filter_var( $resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				$ipv4[] = $resolved;
			}
		}
		if ( empty( $ipv4 ) && empty( $ipv6 ) ) {
			return new \WP_Error(
				'invalid_url',
				sprintf(
					/* translators: %s: hostname */
					__( 'Could not resolve host "%s".', 'gk-block-mcp' ),
					$host
				),
				array( 'status' => 400 )
			);
		}

		// IPv4 ranges (filterable).
		$v4_ranges = self::SSRF_BLOCKED_IPV4_RANGES;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Adjust which IPv4 ranges are off-limits when sideloading from a URL.
			 *
			 * Before fetching any remote image, the plugin resolves the host and
			 * refuses private, loopback, link-local, and other reserved IPv4
			 * ranges — that's the SSRF guard that stops an agent from being
			 * tricked into reading your internal network or cloud metadata
			 * endpoint. Use this filter to lock things down even harder by adding
			 * a corporate CIDR you never want fetched, or — carefully — to allow a
			 * specific internal range you trust (for example, an on-LAN media
			 * server). Each entry is a `[start, end]` pair of dotted-quad strings.
			 *
			 * @since 2.0.0
			 *
			 * @example
			 * // Also block your office network from URL sideloads.
			 * add_filter( 'gk/block-mcp/media/sideload-blocked-ranges', function ( $ranges ) {
			 *     $ranges[] = array( '203.0.113.0', '203.0.113.255' );
			 *     return $ranges;
			 * } );
			 *
			 * @param array<int,array{0:string,1:string}> $v4_ranges Blocked IPv4 ranges as [start, end] dotted-quad pairs.
			 */
			$filtered = apply_filters( 'gk/block-mcp/media/sideload-blocked-ranges', $v4_ranges );
			if ( is_array( $filtered ) ) {
				$v4_ranges = $filtered;
			}
		}

		foreach ( $ipv4 as $ip ) {
			$ip_long = ip2long( $ip );
			if ( false === $ip_long ) {
				return new \WP_Error(
					'invalid_url',
					sprintf(
						/* translators: 1: IP address, 2: hostname */
						__( 'Could not validate IP "%1$s" for "%2$s".', 'gk-block-mcp' ),
						$ip,
						$host
					),
					array( 'status' => 400 )
				);
			}
			foreach ( $v4_ranges as $range ) {
				$start = ip2long( $range[0] );
				$end   = ip2long( $range[1] );
				if ( false !== $start && false !== $end && $ip_long >= $start && $ip_long <= $end ) {
					return new \WP_Error(
						'invalid_url',
						sprintf(
							/* translators: 1: hostname, 2: IPv4 address */
							__( 'URL host "%1$s" resolves to a reserved or private IPv4 (%2$s).', 'gk-block-mcp' ),
							$host,
							$ip
						),
						array( 'status' => 400 )
					);
				}
			}
		}

		// IPv6 reserved-range check. CIDRs cover the same classes as IPv4:
		// ::/128             unspecified
		// ::1/128            loopback
		// fc00::/7           unique-local (private)
		// fe80::/10          link-local
		// ::ffff:0:0/96      IPv4-mapped (catch the IPv4 ranges via wrapper too)
		// 100::/64           discard-only
		// 2001::/23          IETF protocol assignments
		// ff00::/8           multicast
		// Filterable via `gk/block-mcp/media/sideload-blocked-ipv6-cidrs`.
		$v6_cidrs = array(
			'::/128',
			'::1/128',
			'fc00::/7',
			'fe80::/10',
			'::ffff:0:0/96',
			'100::/64',
			'2001::/23',
			'ff00::/8',
		);
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Adjust which IPv6 ranges are off-limits when sideloading from a URL.
			 *
			 * The IPv6 companion to the SSRF guard: it blocks loopback,
			 * unique-local, link-local, IPv4-mapped, and other reserved IPv6
			 * space before any remote fetch, so a malicious URL can't reach your
			 * internal services over IPv6. Add your own CIDRs to extend the block
			 * list to ranges specific to your network. Values are standard CIDR
			 * strings (e.g. `fc00::/7`).
			 *
			 * @since 2.0.0
			 *
			 * @example
			 * // Block an additional internal IPv6 prefix.
			 * add_filter( 'gk/block-mcp/media/sideload-blocked-ipv6-cidrs', function ( $cidrs ) {
			 *     $cidrs[] = '2001:db8::/32';
			 *     return $cidrs;
			 * } );
			 *
			 * @param array<int,string> $v6_cidrs Blocked IPv6 ranges as CIDR strings.
			 */
			$filtered = apply_filters( 'gk/block-mcp/media/sideload-blocked-ipv6-cidrs', $v6_cidrs );
			if ( is_array( $filtered ) ) {
				$v6_cidrs = $filtered;
			}
		}

		foreach ( $ipv6 as $ip ) {
			$ip_packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $ip_packed ) {
				return new \WP_Error(
					'invalid_url',
					sprintf(
						/* translators: 1: IPv6 address, 2: hostname */
						__( 'Could not validate IPv6 "%1$s" for "%2$s".', 'gk-block-mcp' ),
						$ip,
						$host
					),
					array( 'status' => 400 )
				);
			}
			foreach ( $v6_cidrs as $cidr ) {
				if ( $this->ipv6_in_cidr( $ip_packed, $cidr ) ) {
					return new \WP_Error(
						'invalid_url',
						sprintf(
							/* translators: 1: hostname, 2: IPv6 address, 3: CIDR */
							__( 'URL host "%1$s" resolves to a reserved or private IPv6 (%2$s, in %3$s).', 'gk-block-mcp' ),
							$host,
							$ip,
							$cidr
						),
						array( 'status' => 400 )
					);
				}
			}
		}
		return true;
	}

	/**
	 * Test whether a packed-binary IPv6 address falls inside a CIDR block.
	 *
	 * @param string $ip_packed 16-byte binary IPv6 from `inet_pton()`.
	 * @param string $cidr      e.g. `fe80::/10`.
	 * @return bool
	 */
	private function ipv6_in_cidr( $ip_packed, $cidr ) {
		$parts = explode( '/', $cidr, 2 );
		if ( count( $parts ) !== 2 ) {
			return false;
		}
		$net_packed = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$prefix     = (int) $parts[1];
		if ( false === $net_packed || $prefix < 0 || $prefix > 128 ) {
			return false;
		}
		// Build a 16-byte mask with `$prefix` MSBs set.
		$mask     = str_repeat( "\xff", intdiv( $prefix, 8 ) );
		$rem_bits = $prefix % 8;
		if ( $rem_bits ) {
			$mask .= chr( 0xff << ( 8 - $rem_bits ) & 0xff );
		}
		$mask = str_pad( $mask, 16, "\x00" );
		return ( $ip_packed & $mask ) === ( $net_packed & $mask );
	}

	// -------------------------------------------------------------------------
	// Chunked base64 upload (begin → chunk → finish / abort)
	// -------------------------------------------------------------------------

	/**
	 * Start a chunked upload session.
	 *
	 * @param array $args {
	 *     @type string $filename     Required.
	 *     @type string $content_md5  Required. Hex MD5 of the raw file bytes.
	 *     @type int    $byte_size    Optional. Expected decoded byte length.
	 *     @type string $title        Optional metadata.
	 *     @type string $alt_text     Optional.
	 *     @type string $caption      Optional.
	 *     @type string $description  Optional.
	 *     @type int    $post_id      Optional parent post.
	 * }
	 * @return array|\WP_Error { upload_id, expires_in }
	 */
	public function begin_chunked_upload( array $args ) {
		if ( ! self::uploads_enabled() ) {
			return new \WP_Error(
				'uploads_disabled',
				__( 'Media uploads via the block API are disabled on this site.', 'gk-block-mcp' ),
				array( 'status' => 403 )
			);
		}

		$filename = isset( $args['filename'] ) ? sanitize_file_name( (string) $args['filename'] ) : '';
		if ( '' === $filename ) {
			return new \WP_Error( 'invalid_filename', __( '"filename" is required for chunked uploads.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$content_md5 = isset( $args['content_md5'] ) ? strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $args['content_md5'] ) ?? '' ) : '';
		if ( 32 !== strlen( $content_md5 ) ) {
			return new \WP_Error(
				'invalid_checksum',
				__( '"content_md5" must be a 32-character hex MD5 of the raw file bytes.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$byte_size = isset( $args['byte_size'] ) ? absint( $args['byte_size'] ) : 0;
		if ( $byte_size > 0 ) {
			$max = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
			$cap = $max > 0 ? min( $max, self::URL_DOWNLOAD_MAX_BYTES ) : self::URL_DOWNLOAD_MAX_BYTES;
			if ( $byte_size > $cap ) {
				return new \WP_Error( 'file_too_large', __( 'byte_size exceeds size cap.', 'gk-block-mcp' ), array( 'status' => 400 ) );
			}
		}

		$root = $this->chunked_uploads_root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$upload_id = wp_generate_uuid4();
		$session   = $root . '/' . $upload_id;
		if ( ! wp_mkdir_p( $session ) ) {
			return new \WP_Error( 'session_create_failed', __( 'Could not create upload session directory.', 'gk-block-mcp' ), array( 'status' => 500 ) );
		}

		$meta = array(
			'upload_id'   => $upload_id,
			'created_at'  => time(),
			'user_id'     => get_current_user_id(),
			'filename'    => $filename,
			'content_md5' => $content_md5,
			'byte_size'   => $byte_size,
			'title'       => isset( $args['title'] ) ? (string) $args['title'] : null,
			'alt_text'    => isset( $args['alt_text'] ) ? (string) $args['alt_text'] : null,
			'caption'     => isset( $args['caption'] ) ? (string) $args['caption'] : null,
			'description' => isset( $args['description'] ) ? (string) $args['description'] : null,
			'post_id'     => isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0,
			'chunks'      => array(),
		);

		if ( ! $this->write_session_meta( $session, $meta ) ) {
			$this->delete_session_dir( $session );
			return new \WP_Error( 'session_create_failed', __( 'Could not write upload session metadata.', 'gk-block-mcp' ), array( 'status' => 500 ) );
		}

		return array(
			'upload_id'  => $upload_id,
			'expires_in' => self::CHUNKED_UPLOAD_TTL,
		);
	}

	/**
	 * Append one base64-encoded chunk (decoded to binary on disk).
	 *
	 * Chunk the **raw file bytes**, then base64-encode each piece separately
	 * (do not split a single base64 string). Recommended ~2–3 KB of raw bytes
	 * per chunk so LLM tool calls stay reliable.
	 *
	 * @param string $upload_id Session id from begin_chunked_upload.
	 * @param array  $args {
	 *     @type int    $index       Required. Zero-based contiguous index.
	 *     @type string $data_base64 Required. Base64 of this chunk's raw bytes.
	 * }
	 * @return array|\WP_Error { upload_id, index, bytes, chunk_count }
	 */
	public function append_chunk( $upload_id, array $args ) {
		$session = $this->resolve_writable_session( $upload_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$meta = $this->read_session_meta( $session );
		if ( is_wp_error( $meta ) ) {
			$this->delete_session_dir( $session );
			return $meta;
		}

		if ( ! array_key_exists( 'index', $args ) || ! is_numeric( $args['index'] ) ) {
			return new \WP_Error( 'missing_params', __( '"index" is required for each chunk (non-negative integer).', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}
		$index = (int) $args['index'];
		if ( $index < 0 || (string) $index !== (string) (int) $args['index'] ) {
			return new \WP_Error( 'invalid_index', __( '"index" must be a non-negative integer.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		if ( in_array( $index, $meta['chunks'], true ) ) {
			return new \WP_Error( 'duplicate_chunk', __( 'That chunk index was already received.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		if ( empty( $args['data_base64'] ) || ! is_string( $args['data_base64'] ) ) {
			return new \WP_Error( 'missing_params', __( '"data_base64" is required for each chunk.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$decoded = $this->decode_base64_payload( $args['data_base64'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		// Running total must stay under the site cap.
		$so_far = $this->session_assembled_bytes( $session, $meta['chunks'] );
		if ( is_wp_error( $so_far ) ) {
			$this->delete_session_dir( $session );
			return $so_far;
		}
		$projected = $so_far + strlen( $decoded );
		$max       = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
		$cap       = $max > 0 ? min( $max, self::URL_DOWNLOAD_MAX_BYTES ) : self::URL_DOWNLOAD_MAX_BYTES;
		if ( $projected > $cap ) {
			return new \WP_Error( 'file_too_large', __( 'Chunked upload exceeds size cap.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}
		if ( ! empty( $meta['byte_size'] ) && $projected > (int) $meta['byte_size'] ) {
			return new \WP_Error(
				'byte_size_exceeded',
				__( 'Received bytes exceed the byte_size declared at begin.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$chunk_path = $this->chunk_path( $session, $index );
		$written    = file_put_contents( $chunk_path, $decoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written ) {
			return new \WP_Error( 'chunk_write_failed', __( 'Could not write chunk to disk.', 'gk-block-mcp' ), array( 'status' => 500 ) );
		}

		$meta['chunks'][] = $index;
		sort( $meta['chunks'], SORT_NUMERIC );
		if ( ! $this->write_session_meta( $session, $meta ) ) {
			wp_delete_file( $chunk_path );
			return new \WP_Error( 'session_update_failed', __( 'Could not update upload session metadata.', 'gk-block-mcp' ), array( 'status' => 500 ) );
		}

		return array(
			'upload_id'   => $meta['upload_id'],
			'index'       => $index,
			'bytes'       => strlen( $decoded ),
			'chunk_count' => count( $meta['chunks'] ),
		);
	}

	/**
	 * Assemble chunks, verify MD5, sideload into the media library.
	 *
	 * @param string $upload_id Session id.
	 * @return array|\WP_Error Same shape as upload() on success.
	 */
	public function finish_chunked_upload( $upload_id ) {
		$session = $this->resolve_writable_session( $upload_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$meta = $this->read_session_meta( $session );
		if ( is_wp_error( $meta ) ) {
			$this->delete_session_dir( $session );
			return $meta;
		}

		$chunks = $meta['chunks'];
		if ( empty( $chunks ) ) {
			$this->delete_session_dir( $session );
			return new \WP_Error( 'incomplete_upload', __( 'No chunks were received.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$max_index = max( $chunks );
		for ( $i = 0; $i <= $max_index; $i++ ) {
			if ( ! in_array( $i, $chunks, true ) ) {
				$this->delete_session_dir( $session );
				return new \WP_Error(
					'incomplete_upload',
					sprintf(
						/* translators: %d: missing chunk index */
						__( 'Missing chunk index %d (indexes must be contiguous from 0).', 'gk-block-mcp' ),
						$i
					),
					array( 'status' => 400 )
				);
			}
		}

		$assembled = '';
		for ( $i = 0; $i <= $max_index; $i++ ) {
			$path = $this->chunk_path( $session, $i );
			if ( ! is_readable( $path ) ) {
				$this->delete_session_dir( $session );
				return new \WP_Error( 'incomplete_upload', __( 'A chunk file is missing on disk.', 'gk-block-mcp' ), array( 'status' => 400 ) );
			}
			$piece = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $piece ) {
				$this->delete_session_dir( $session );
				return new \WP_Error( 'assemble_failed', __( 'Could not read a chunk file.', 'gk-block-mcp' ), array( 'status' => 500 ) );
			}
			$assembled .= $piece;
		}

		if ( ! empty( $meta['byte_size'] ) && strlen( $assembled ) !== (int) $meta['byte_size'] ) {
			$this->delete_session_dir( $session );
			return new \WP_Error(
				'byte_size_mismatch',
				__( 'Assembled byte length does not match byte_size from begin.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$actual_md5 = md5( $assembled );
		if ( ! hash_equals( (string) $meta['content_md5'], $actual_md5 ) ) {
			$this->delete_session_dir( $session );
			return new \WP_Error(
				'checksum_mismatch',
				__( 'Assembled file MD5 does not match content_md5 from begin.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$cap_err = $this->enforce_decoded_size_cap( $assembled );
		if ( is_wp_error( $cap_err ) ) {
			$this->delete_session_dir( $session );
			return $cap_err;
		}

		$this->require_admin_includes();
		$attachment_id = $this->sideload_decoded_bytes(
			$assembled,
			(string) $meta['filename'],
			(int) $meta['post_id']
		);

		// Wipe session before returning either success or sideload error.
		$meta_for_apply = $meta;
		$this->delete_session_dir( $session );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$apply_args = array();
		foreach ( array( 'title', 'alt_text', 'caption', 'description' ) as $key ) {
			if ( null !== $meta_for_apply[ $key ] ) {
				$apply_args[ $key ] = $meta_for_apply[ $key ];
			}
		}
		$this->apply_metadata( (int) $attachment_id, $apply_args );

		return $this->format_attachment( (int) $attachment_id );
	}

	/**
	 * Abort and delete a chunked upload session.
	 *
	 * @param string $upload_id Session id.
	 * @return array|\WP_Error { success, upload_id }
	 */
	public function abort_chunked_upload( $upload_id ) {
		$session = $this->resolve_writable_session( $upload_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$this->delete_session_dir( $session );
		return array(
			'success'   => true,
			'upload_id' => (string) $upload_id,
		);
	}

	/**
	 * Absolute path to the chunked-uploads root under wp uploads.
	 *
	 * @return string|\WP_Error
	 */
	private function chunked_uploads_root() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'upload_dir_error', (string) $uploads['error'], array( 'status' => 500 ) );
		}
		$root = trailingslashit( $uploads['basedir'] ) . self::CHUNKED_UPLOAD_DIR;
		if ( ! wp_mkdir_p( $root ) ) {
			return new \WP_Error( 'session_create_failed', __( 'Could not create chunked uploads directory.', 'gk-block-mcp' ), array( 'status' => 500 ) );
		}
		return $root;
	}

	/**
	 * Resolve upload_id to a session dir the current user may write.
	 * Deletes and errors if the session is past TTL.
	 *
	 * @param string $upload_id UUID.
	 * @return string|\WP_Error Absolute session directory.
	 */
	private function resolve_writable_session( $upload_id ) {
		$upload_id = (string) $upload_id;
		if ( ! preg_match( '/^[a-f0-9-]{36}$/i', $upload_id ) ) {
			return new \WP_Error( 'invalid_upload_id', __( 'Invalid upload_id.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$root = $this->chunked_uploads_root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$session = $root . '/' . $upload_id;
		if ( ! is_dir( $session ) ) {
			return new \WP_Error( 'upload_not_found', __( 'Upload session not found.', 'gk-block-mcp' ), array( 'status' => 404 ) );
		}

		$meta = $this->read_session_meta( $session );
		if ( is_wp_error( $meta ) ) {
			$this->delete_session_dir( $session );
			return $meta;
		}

		$age = time() - (int) $meta['created_at'];
		if ( $age > self::CHUNKED_UPLOAD_TTL ) {
			$this->delete_session_dir( $session );
			return new \WP_Error( 'upload_expired', __( 'Upload session expired.', 'gk-block-mcp' ), array( 'status' => 410 ) );
		}

		$user_id = get_current_user_id();
		$owner   = (int) $meta['user_id'];
		if ( $owner && $user_id !== $owner && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not own this upload session.', 'gk-block-mcp' ), array( 'status' => 403 ) );
		}

		return $session;
	}

	/**
	 * @param string $session Absolute session dir.
	 * @return array|\WP_Error
	 */
	private function read_session_meta( $session ) {
		$path = $session . '/meta.json';
		if ( ! is_readable( $path ) ) {
			return new \WP_Error( 'upload_not_found', __( 'Upload session metadata missing.', 'gk-block-mcp' ), array( 'status' => 404 ) );
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			return new \WP_Error( 'session_read_failed', __( 'Could not read upload session metadata.', 'gk-block-mcp' ), array( 'status' => 500 ) );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['upload_id'] ) ) {
			return new \WP_Error( 'session_corrupt', __( 'Upload session metadata is corrupt.', 'gk-block-mcp' ), array( 'status' => 500 ) );
		}
		if ( ! isset( $data['chunks'] ) || ! is_array( $data['chunks'] ) ) {
			$data['chunks'] = array();
		}
		$data['chunks'] = array_map( 'intval', $data['chunks'] );
		return $data;
	}

	/**
	 * @param string $session Absolute session dir.
	 * @param array  $meta    Session metadata.
	 * @return bool
	 */
	private function write_session_meta( $session, array $meta ) {
		$json = wp_json_encode( $meta );
		if ( false === $json ) {
			return false;
		}
		$path = $session . '/meta.json';
		$ok   = file_put_contents( $path, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== $ok;
	}

	/**
	 * @param string $session Absolute session dir.
	 * @param int    $index   Chunk index.
	 * @return string
	 */
	private function chunk_path( $session, $index ) {
		return $session . '/chunk-' . sprintf( '%05d', (int) $index ) . '.bin';
	}

	/**
	 * @param string $session Absolute session dir.
	 * @param int[]  $chunks  Received indexes.
	 * @return int|\WP_Error
	 */
	private function session_assembled_bytes( $session, array $chunks ) {
		$total = 0;
		foreach ( $chunks as $index ) {
			$path = $this->chunk_path( $session, (int) $index );
			if ( ! is_readable( $path ) ) {
				return new \WP_Error( 'session_corrupt', __( 'A recorded chunk file is missing.', 'gk-block-mcp' ), array( 'status' => 500 ) );
			}
			$size = filesize( $path );
			if ( false === $size ) {
				return new \WP_Error( 'session_corrupt', __( 'Could not stat a chunk file.', 'gk-block-mcp' ), array( 'status' => 500 ) );
			}
			$total += (int) $size;
		}
		return $total;
	}

	/**
	 * Recursively delete a session directory.
	 *
	 * @param string $session Absolute path.
	 * @return void
	 */
	private function delete_session_dir( $session ) {
		if ( ! is_dir( $session ) ) {
			return;
		}
		$entries = @scandir( $session ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = $session . '/' . $entry;
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				} elseif ( is_dir( $path ) ) {
					$this->delete_session_dir( $path );
				}
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Session temp dir under uploads.
		@rmdir( $session ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Require WordPress admin includes needed for media upload functions.
	 *
	 * @return void
	 */
	private function require_admin_includes() {
		if ( defined( 'ABSPATH' ) && is_dir( ABSPATH . 'wp-admin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}
}
