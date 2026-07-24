<?php
/**
 * Tests for the Media_Manager class.
 *
 * Drives the three upload modes (multipart / URL sideload / base64) against
 * real WordPress: real wp_handle_upload, real media_handle_sideload, real
 * download_url. The URL path is intercepted with the pre_http_request filter
 * so no network traffic leaves CI.
 *
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\Media_Manager;

class MediaManagerTest extends WP_UnitTestCase {

	/** @var Media_Manager */
	private $mm;

	public function set_up(): void {
		parent::set_up();
		$this->mm = new Media_Manager();
		$_FILES   = array();
	}

	public function tear_down(): void {
		$_FILES = array();
		// WP_UnitTestCase backs up + restores $wp_filter around each test, so
		// per-test add_filter() calls don't normally leak. The explicit
		// remove_all_filters here is belt-and-braces against the specific
		// hooks this file attaches — useful if a future base-class change
		// drops the hook-backup behavior.
		remove_all_filters( 'upload_size_limit' );
		remove_all_filters( 'gk/block-mcp/media/upload-overrides' );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_requires_one_input_mode() {
		$result = $this->mm->upload( array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_file', $result->get_error_code() );
	}

	public function test_rejects_multiple_input_modes() {
		$result = $this->mm->upload( array(
			'url'         => 'https://example.com/x.png',
			'data_base64' => 'aGVsbG8=',
			'filename'    => 'x.png',
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'multiple_inputs', $result->get_error_code() );
	}

	// ── base64 path ──

	public function test_base64_requires_filename() {
		$result = $this->mm->upload( array( 'data_base64' => base64_encode( 'png' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_filename', $result->get_error_code() );
	}

	public function test_base64_rejects_invalid_data() {
		$result = $this->mm->upload( array(
			'data_base64' => '!!!not-base64!!!',
			'filename'    => 'x.png',
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_base64', $result->get_error_code() );
	}

	public function test_base64_accepts_data_uri_and_whitespace() {
		$png    = file_get_contents( __DIR__ . '/../fixtures/sample.png' );
		$b64    = chunk_split( base64_encode( $png ), 76, "\n" );
		$result = $this->mm->upload( array(
			'data_base64' => 'data:image/png;base64,' . $b64,
			'filename'    => 'sample.png',
		) );
		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'image/png', $result['mime_type'] );
	}

	public function test_base64_rejects_disallowed_mime() {
		$result = $this->mm->upload( array(
			'data_base64' => base64_encode( '<?php echo "x"; ?>' ),
			'filename'    => 'shell.php',
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'disallowed_mime', $result->get_error_code() );
	}

	public function test_base64_happy_path() {
		$png    = file_get_contents( __DIR__ . '/../fixtures/sample.png' );
		$result = $this->mm->upload( array(
			'data_base64' => base64_encode( $png ),
			'filename'    => 'sample.png',
			'alt_text'    => 'sample',
			'title'       => 'My Sample',
			'caption'     => 'cap',
			'description' => 'desc',
			'post_id'     => self::factory()->post->create(),
		) );
		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['success'] );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertSame( 'sample', $result['alt_text'] );
		$this->assertSame( 'My Sample', $result['title'] );
		$this->assertSame( 'cap', $result['caption'] );
		$this->assertSame( 'desc', $result['description'] );
		$this->assertGreaterThan( 0, $result['post_parent'] );
	}

	public function test_base64_enforces_max_upload_size() {
		// Cap upload size to 8 bytes via the documented filter.
		add_filter( 'upload_size_limit', static fn() => 8 );
		$png = file_get_contents( __DIR__ . '/../fixtures/sample.png' );
		$result = $this->mm->upload( array(
			'data_base64' => base64_encode( $png ),
			'filename'    => 'sample.png',
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'file_too_large', $result->get_error_code() );
	}

	// ── multipart path ──

	public function test_multipart_happy_path() {
		$src = __DIR__ . '/../fixtures/sample.png';
		$tmp = tempnam( sys_get_temp_dir(), 'multipart' );
		copy( $src, $tmp );
		$_FILES['file'] = array(
			'name'     => 'sample.png',
			'type'     => 'image/png',
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => filesize( $tmp ),
		);
		// _wp_handle_upload() short-circuits to `is_readable()` instead of
		// `is_uploaded_file()` whenever the action is anything other than
		// the literal 'wp_handle_upload'. The plugin's
		// gk/block-mcp/media/upload-overrides filter lets us swap to the
		// sideload action so PHPUnit-staged temp files reach the rest of
		// the pipeline.
		add_filter( 'gk/block-mcp/media/upload-overrides', static function ( $overrides ) {
			$overrides['action'] = 'wp_handle_sideload';
			return $overrides;
		} );

		$result = $this->mm->upload( array( 'file_field' => 'file', 'alt_text' => 'alt' ) );
		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'alt', $result['alt_text'] );
		$this->assertSame( 'image/png', $result['mime_type'] );
	}

	public function test_multipart_rejects_disallowed_mime() {
		$tmp = tempnam( sys_get_temp_dir(), 'shell' );
		file_put_contents( $tmp, '<?php' );
		$_FILES['file'] = array(
			'name'     => 'shell.php',
			'type'     => 'application/x-php',
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => filesize( $tmp ),
		);
		$result = $this->mm->upload( array( 'file_field' => 'file' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'disallowed_mime', $result->get_error_code() );
	}

	// ── url path ──

	public function test_url_rejects_invalid_scheme() {
		$result = $this->mm->upload( array( 'url' => 'ftp://example.com/x.png' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	public function test_url_happy_path() {
		// RFC5737 documentation IP — passes the SSRF guard's blocklist.
		// Intercept the HTTP fetch with pre_http_request so no real network
		// traffic leaves the runner.
		$src = __DIR__ . '/../fixtures/sample.png';
		$url = 'https://203.0.113.1/test.png';
		$bytes = file_get_contents( $src );

		add_filter( 'pre_http_request', static function ( $preempt, $args, $request_url ) use ( $url, $bytes ) {
			if ( $request_url !== $url ) {
				return $preempt;
			}
			// download_url() opens a tempfile and asks WP to stream the body
			// into it. If `filename` is set in $args we satisfy the stream
			// contract by writing the bytes ourselves and returning a 200.
			if ( ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $bytes );
			}
			return array(
				'headers'  => array( 'content-type' => 'image/png' ),
				'body'     => '',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => $args['filename'] ?? null,
			);
		}, 10, 3 );

		$result = $this->mm->upload( array(
			'url'      => $url,
			'alt_text' => 'remote',
			'filename' => 'remote.png',
		) );
		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'image/png', $result['mime_type'] );
	}

	public function test_url_propagates_fetch_failure() {
		// No pre_http_request filter set — real HTTP fetch will fail because
		// 203.0.113.99 is unreachable. Wrapped as url_fetch_failed (502).
		add_filter( 'pre_http_request', static function () {
			return new \WP_Error( 'http_request_failed', 'connection refused' );
		}, 10, 0 );
		$result = $this->mm->upload( array( 'url' => 'https://203.0.113.99/x.png' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'url_fetch_failed', $result->get_error_code() );
	}

	public function test_url_blocks_link_local_metadata() {
		// AWS/GCP/Azure cloud metadata endpoint — must be rejected.
		$result = $this->mm->upload( array( 'url' => 'http://169.254.169.254/latest/meta-data/' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	public function test_url_blocks_rfc1918_private() {
		$result = $this->mm->upload( array( 'url' => 'http://10.0.0.5/admin.png' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	public function test_url_blocks_loopback() {
		$result = $this->mm->upload( array( 'url' => 'http://127.0.0.1/x.png' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	// ── chunked base64 path ──

	public function test_chunked_happy_path() {
		$png  = file_get_contents( __DIR__ . '/../fixtures/sample.png' );
		$md5  = md5( $png );
		$size = strlen( $png );

		$begin = $this->mm->begin_chunked_upload( array(
			'filename'    => 'chunked.png',
			'content_md5' => $md5,
			'byte_size'   => $size,
			'alt_text'    => 'chunked-alt',
			'title'       => 'Chunked',
		) );
		$this->assertIsArray( $begin, is_object( $begin ) ? $begin->get_error_message() : '' );
		$upload_id = $begin['upload_id'];

		// Split into ~40-byte raw chunks so we exercise multiple indexes.
		$chunk_size = 40;
		$index      = 0;
		for ( $offset = 0; $offset < $size; $offset += $chunk_size ) {
			$piece = substr( $png, $offset, $chunk_size );
			$res   = $this->mm->append_chunk( $upload_id, array(
				'index'       => $index,
				'data_base64' => base64_encode( $piece ),
			) );
			$this->assertIsArray( $res, is_object( $res ) ? $res->get_error_message() : '' );
			++$index;
		}

		$result = $this->mm->finish_chunked_upload( $upload_id );
		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertSame( 'chunked-alt', $result['alt_text'] );
		$this->assertSame( 'Chunked', $result['title'] );

		// Session dir must be gone after finish.
		$uploads = wp_upload_dir();
		$session = trailingslashit( $uploads['basedir'] ) . Media_Manager::CHUNKED_UPLOAD_DIR . '/' . $upload_id;
		$this->assertFalse( is_dir( $session ) );
	}

	public function test_chunked_checksum_mismatch() {
		$png = file_get_contents( __DIR__ . '/../fixtures/sample.png' );
		$begin = $this->mm->begin_chunked_upload( array(
			'filename'    => 'bad.png',
			'content_md5' => str_repeat( 'a', 32 ),
		) );
		$this->assertIsArray( $begin );
		$upload_id = $begin['upload_id'];

		$this->mm->append_chunk( $upload_id, array(
			'index'       => 0,
			'data_base64' => base64_encode( $png ),
		) );
		$result = $this->mm->finish_chunked_upload( $upload_id );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'checksum_mismatch', $result->get_error_code() );

		$uploads = wp_upload_dir();
		$session = trailingslashit( $uploads['basedir'] ) . Media_Manager::CHUNKED_UPLOAD_DIR . '/' . $upload_id;
		$this->assertFalse( is_dir( $session ) );
	}

	public function test_chunked_incomplete_upload() {
		$png = file_get_contents( __DIR__ . '/../fixtures/sample.png' );
		$begin = $this->mm->begin_chunked_upload( array(
			'filename'    => 'gap.png',
			'content_md5' => md5( $png ),
		) );
		$upload_id = $begin['upload_id'];

		// Only send index 1 — missing 0.
		$this->mm->append_chunk( $upload_id, array(
			'index'       => 1,
			'data_base64' => base64_encode( substr( $png, 0, 10 ) ),
		) );
		$result = $this->mm->finish_chunked_upload( $upload_id );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'incomplete_upload', $result->get_error_code() );
	}

	public function test_chunked_abort_deletes_session() {
		$begin = $this->mm->begin_chunked_upload( array(
			'filename'    => 'abort.png',
			'content_md5' => md5( 'x' ),
		) );
		$upload_id = $begin['upload_id'];
		$uploads   = wp_upload_dir();
		$session   = trailingslashit( $uploads['basedir'] ) . Media_Manager::CHUNKED_UPLOAD_DIR . '/' . $upload_id;
		$this->assertTrue( is_dir( $session ) );

		$abort = $this->mm->abort_chunked_upload( $upload_id );
		$this->assertIsArray( $abort );
		$this->assertTrue( $abort['success'] );
		$this->assertFalse( is_dir( $session ) );
	}

	public function test_chunked_ttl_expires_on_access() {
		$begin = $this->mm->begin_chunked_upload( array(
			'filename'    => 'old.png',
			'content_md5' => md5( 'x' ),
		) );
		$upload_id = $begin['upload_id'];
		$uploads   = wp_upload_dir();
		$session   = trailingslashit( $uploads['basedir'] ) . Media_Manager::CHUNKED_UPLOAD_DIR . '/' . $upload_id;
		$meta_path = $session . '/meta.json';
		$meta      = json_decode( file_get_contents( $meta_path ), true );
		$meta['created_at'] = time() - Media_Manager::CHUNKED_UPLOAD_TTL - 10;
		file_put_contents( $meta_path, wp_json_encode( $meta ) );

		$result = $this->mm->append_chunk( $upload_id, array(
			'index'       => 0,
			'data_base64' => base64_encode( 'x' ),
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'upload_expired', $result->get_error_code() );
		$this->assertFalse( is_dir( $session ) );
	}
}
