<?php

/** Unit tests for the Micropub class.
 *
 * TODO:
 * token validation
 * categories/tags
 */

class Recorder extends Micropub {
	public static $status;
	public static $response;
	public static $input;
	public static $response_headers = array();

	public static function init() {
		remove_filter( 'query_vars', array( 'Micropub', 'query_var' ) );
		remove_action( 'parse_query', array( 'Micropub', 'parse_query' ) );
		remove_all_filters('before_micropub');
		remove_all_filters('after_micropub');
		parent::init();
	}

	public static function respond( $status, $response ) {
		self::$status = $status;
		self::$response = $response;
		throw new WPDieException('from respond');
	}

	public static function header( $header, $value ) {
		self::$response_headers[ $header ] = $value;
	}

	protected static function read_input() {
		return static::$input;
	}
}
Recorder::init();


class MicropubTest extends WP_UnitTestCase {

	/**
	 * HTTP status code returned for the last request
	 * @var string
	 */
	protected static $status = 0;

	public function setUp() {
		parent::setUp();
		self::$status = 0;
		$_POST = array();
		$_GET = array();
		$_FILES = array();
		Recorder::$request_headers = array();
		Recorder::$input = NULL;
		unset( $GLOBALS['post'] );

		global $wp_query;
		$wp_query->query_vars['micropub'] = 'endpoint';

		$this->userid = self::factory()->user->create( array( 'role' => 'editor' ));
		wp_set_current_user( $this->userid );
	}

	/**
	 * Helper that runs Micropub::parse_query. Based on
	 * WP_Ajax_UnitTestCase::_handleAjax.
	 */
	function parse_query( $method = 'POST' ) {
		global $wp_query;
		$_SERVER['REQUEST_METHOD'] = $method;
		try {
			do_action( 'parse_query', $wp_query );
		}
		catch ( WPDieException $e ) {
			return;
		}
		$this->fail( 'WPDieException not thrown!' );
	}

	/**
	 * Run parse_query and check the result.
	 *
	 * If $response is an array, it's compared to the JSON response verbatim. If
	 * it's a string, it's checked against the 'error_description' response
	 * field as a substring.
	 */
	function check( $status, $expected = NULL ) {
		$this->parse_query( $_GET ? 'GET' : 'POST' );
		$encoded = json_encode( Recorder::$response, true );

		$this->assertEquals( $status, Recorder::$status, $encoded );
		if ( is_array( $expected )) {
			$this->assertEquals( $expected, Recorder::$response, $encoded );
		} elseif ( is_string( $expected ) ) {
			$this->assertContains( $expected, Recorder::$response['error_description'], $encoded );
		} else {
			$this->assertSame( NULL, $expected );
		}
	}

	function check_create() {
		$this->check( 201 );
		$posts = wp_get_recent_posts( NULL, OBJECT );
		$this->assertEquals( 1, count( $posts ));
		$post = $posts[0];
		$this->assertEquals( get_permalink( $post ),
							 Recorder::$response_headers['Location'] );
		return $post;
	}

	// POST args
	protected static $post = array(
		'h' => 'entry',
		'content' => 'my<br>content',
		'slug' => 'my_slug',
		'name' => 'my name',
		'summary' => 'my summary',
		'category' => array( 'tag1', 'tag4' ),
		'published' => '2016-01-01T12:01:23Z',
		'location' => 'geo:42.361,-71.092;u=25000',
	);

	// JSON mf2 input
	protected static $mf2 = array(
		'type' => array( 'h-entry' ),
		'properties' => array(
			'content' => array( 'my<br>content' ),
			'slug' => array( 'my_slug' ),
			'name' => array( 'my name' ),
			'summary' => array( 'my summary' ),
			'category' => array( 'tag1', 'tag4' ),
			'published' => array( '2016-01-01T12:01:23Z' ),
			'location' => array( 'geo:42.361,-71.092;u=25000' ),
		),
	);

	// Creates a WordPress post with data that matches $properties above
	protected static function insert_post() {
		return wp_insert_post( array(
			'post_name' => 'my_slug',
			'post_title' => 'my name',
			'post_content' => 'my<br>content',
			'tags_input' => array( 'tag1', 'tag4' ),
			'post_date' => '2016-01-01 12:01:23',
			'location' => 'geo:42.361,-71.092;u=25000',
		));
	}

	function test_bad_query() {
		$_GET['q'] = 'not_real';
		$this->check( 400, array( 'error' => 'invalid_request',
								  'error_description' => 'unknown query not_real' ));
	}

	function test_query_syndicate_to_empty() {
		$_GET['q'] = 'syndicate-to';
		$this->check( 200, array( 'syndicate-to' => array() ));
	}

	function test_query_syndicate_to() {
		function syndicate_to() {
			return array( 'abc', 'xyz' );
		}
		add_filter( 'micropub_syndicate-to', 'syndicate_to' );

		$_GET['q'] = 'syndicate-to';
		$this->check( 200, array( 'syndicate-to' => array( 'abc', 'xyz' )));
	}

	function test_query_post() {
		$_POST = self::$post;
		$post = $this->check_create();

		$_GET = array(
			'q' => 'source',
			'url' => 'http://example.org/?p=' . $post->ID,
		);
		$this->check( 200, self::$mf2 );
	}

	function test_query_post_not_found() {
		$_GET = array(
			'q' => 'source',
			'url' => 'http:/localhost/doesnt/exist',
		);

		$this->check( 400, array(
			'error' => 'invalid_request',
			'error_description' => 'not found: http:/localhost/doesnt/exist',
		));
	}

	function test_create() {
		$_POST = self::$post;
		$post = $this->check_create();

		$this->assertEquals( 'publish', $post->post_status );
		$this->assertEquals( $this->userid, $post->post_author );
		// check that HTML in content is sanitized
		$this->assertEquals( "<div class=\"e-content\">\nmy&lt;br&gt;content\n</div>", $post->post_content );
		$this->assertEquals( 'my_slug', $post->post_name );
		$this->assertEquals( 'my name', $post->post_title );
		$this->assertEquals( 'my summary', $post->post_excerpt );
		$this->assertEquals( '2016-01-01 12:01:23', $post->post_date );

		$this->assertEquals( '42.361', get_post_meta( $post->ID, 'geo_latitude', true ) );
		$this->assertEquals( '-71.092', get_post_meta( $post->ID, 'geo_longitude', true ) );

		$mf2 = Micropub::get_mf2( $post->ID );
		$this->assertEquals( array( 'my summary' ), $mf2['properties']['summary'] );
	}

	function test_create_content_html()
	{
		$_POST = array(
			'h' => 'entry',
			'content' => array( array( 'html' => '<h1>HTML content!</h1><p>coolio.</p>' ) ),
			'name' => 'HTML content test',
		);
		$post = $this->check_create();

		$this->assertEquals( 'HTML content test', $post->post_title );
		// check that HTML in content isn't sanitized
		$this->assertEquals( "<div class=\"e-content\">\n<h1>HTML content!</h1><p>coolio.</p>\n</div>", $post->post_content );
	}

	function test_create_with_photo() {
		$this->_test_create_with_upload('photo', 'image', 'jpg');
	}

	function test_create_with_video() {
		$this->_test_create_with_upload('video', 'video', 'mp4');
	}

	function test_create_with_audio() {
		$this->_test_create_with_upload('audio', 'audio', 'mp3');
	}

	function _test_create_with_upload( $mf2_type, $wp_type, $extension )
	{
		$filename = tempnam( sys_get_temp_dir(), 'micropub_test' );
		$file = fopen( $filename, 'w' );
		fwrite( $file, 'fake file contents' );
		fclose( $file );

		$_FILES = array( $mf2_type => array(
			'name' => 'micropub_test.' . $extension,
			'tmp_name' => $filename,
			'size' => 19,
		));
		$_POST['action'] = 'allow_file_outside_uploads_dir';
		$post = $this->check_create();

		$this->assertEquals( get_permalink( $post ),
							 Recorder::$response_headers['Location'] );
		$this->assertEquals( "\n[gallery size=full columns=1]", $post->post_content );

		$media = get_attached_media( $wp_type, $post->ID );
		$this->assertEquals( 1, count( $media ));
		$this->assertEquals( 'attachment', current( $media )->post_type);
	}

	function test_create_reply()
	{
		$_POST = array('in-reply-to' => 'http://target');
		$post = $this->check_create();
		$this->assertEquals( '', $post->post_title );
		$this->assertEquals( '<p>In reply to <a class="u-in-reply-to" href="http://target">http://target</a>.</p>', $post->post_content );
	}

	function test_create_like()
	{
		$_POST = array('like-of' => 'http://target');
		$post = $this->check_create();
		$this->assertEquals( '', $post->post_title );
		$this->assertEquals( '<p>Likes <a class="u-like-of" href="http://target">http://target</a>.</p>', $post->post_content );
	}

	function test_create_repost()
	{
		$_POST = array('repost-of' => 'http://target');
		$post = $this->check_create();
		$this->assertEquals( '', $post->post_title );
		$this->assertEquals( '<p>Reposted <a class="u-repost-of" href="http://target">http://target</a>.</p>', $post->post_content );
	}

	function test_create_event()
	{
		$_POST = array(
			'h' => 'event',
			'name' => 'My Event',
			'start' => '2013-06-30 12:00:00',
			'end' => '2013-06-31 18:00:00',
			'location' => 'http://a/place',
			'description' => 'some stuff',
		);
		$post = $this->check_create();
		$this->assertEquals( 'My Event', $post->post_title );
		$this->assertEquals( <<<EOF
<div class="h-event">
<h1 class="p-name">My Event</h1>
<p>
<time class="dt-start" datetime="2013-06-30 12:00:00">2013-06-30 12:00:00</time>
to
<time class="dt-end" datetime="2013-06-31 18:00:00">2013-06-31 18:00:00</time>
at <a class="p-location" href="http://a/place">http://a/place</a>.
</p>
<p class="p-description">some stuff</p>
</div>
EOF
, $post->post_content);

		$mf2 = Micropub::get_mf2( $post->ID );
		$this->assertEquals( array( 'h-event' ), $mf2['type'] );
		$this->assertEquals( array( '2013-06-30 12:00:00' ), $mf2['properties']['start'] );
		$this->assertEquals( array( '2013-06-31 18:00:00' ), $mf2['properties']['end'] );
	}

	function test_create_rsvp()
	{
		$_POST = array(
			'rsvp' => 'maybe',
			'in-reply-to' => 'http://target'
		);
		$post = $this->check_create();

		$mf2 = Micropub::get_mf2( $post->ID );
		$this->assertEquals( array( 'maybe' ), $mf2['properties']['rsvp'] );
		$this->assertEquals( <<<EOF
<p>In reply to <a class="u-in-reply-to" href="http://target">http://target</a>.</p>
<p>RSVPs <data class="p-rsvp" value="maybe">maybe</data>.</p>
EOF
, $post->post_content);
	}

	function test_create_user_cannot_publish_posts() {
		get_user_by( 'ID', $this->userid )->remove_role( 'editor' );
		$_POST = array( 'h' => 'entry', 'content' => 'x' );
		$this->check( 403, 'cannot publish posts' );
	}

	function test_update() {
		$post_id = self::insert_post();
		$this->assertEquals( '2016-01-01 12:01:23', get_post( $post_id )->post_date );

		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$input = json_encode( array(
			'action' => 'update',
			'url' => 'http://example.org/?p=' . $post_id,
			'replace' => array( 'content' => array( 'new<br>content' ) ),
			'add' => array( 'category' => array( 'add tag' ) ),
			'delete' => array( 'location', array( 'summary' ) ),
		) );
		$this->check( 200 );

		$post = get_post( $post_id );

		// updated
		$this->assertEquals( <<<EOF
<div class="e-content">
new&lt;br&gt;content
</div>
EOF
, $post->post_content );

		// added
		$tags = wp_get_post_tags( $post->ID );
		$this->assertEquals( 3, count( $tags ) );
		$this->assertEquals( 'add tag', $tags[0]->name );
		$this->assertEquals( 'tag1', $tags[1]->name );
		$this->assertEquals( 'tag4', $tags[2]->name );

		// removed
		$this->assertEquals( '', $post->post_excerpt );
		$meta = get_post_meta( $post->ID );
		$this->assertNull( $meta['geo_latitude'] );
		$this->assertNull( $meta['geo_longitude'] );

		// check that published date is preserved
		// https://github.com/snarfed/wordpress-micropub/issues/16
		$this->assertEquals( '2016-01-01 12:01:23', $post->post_date );
	}

	function test_add_property_not_category() {
		$post_id = self::insert_post();
		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$input = json_encode( array(
			'action' => 'update',
			'url' => 'http://example.org/?p=' . $post_id,
			'add' => array( 'content' => array( 'foo' ) ),
		) );
		$this->check( 400, 'can only add to category; other properties not supported' );
	}

	function test_update_post_not_found() {
		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$input = json_encode( array(
			'action' => 'update',
			'url' => 'http://example.org/?p=999',
			'replace' => array( 'content' => array( 'unused' ) ),
		) );
		$this->check( 400, 'http://example.org/?p=999 not found' );
	}

	function test_update_user_cannot_edit_posts() {
		$post_id = self::insert_post();
		get_user_by( 'ID', $this->userid )->remove_role( 'editor' );

		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$input = json_encode( array(
			'action' => 'update',
			'url' => 'http://example.org/?p=' . $post_id,
			'replace' => array( 'content' => array( 'unused' ) ),
		) );
		$this->check( 403, 'cannot edit posts' );
	}

	function test_delete() {
		$post_id = self::insert_post();

		$_POST = array( 'action' => 'delete', 'url' => 'http://example.org/?p=' . $post_id );
		$this->check( 200 );

		$post = get_post( $post_id );
		$this->assertEquals( 'trash', $post->post_status );
	}

	function test_delete_post_not_found() {
		$_POST = array( 'action' => 'delete', 'url' => 'http://example.org/?p=999' );
		$this->check( 400, array(
			'error' => 'invalid_request',
			'error_description' => 'http://example.org/?p=999 not found',
		));
	}

	function test_delete_user_cannot_delete_posts() {
		$post_id = self::insert_post();
		get_user_by( 'ID', $this->userid )->remove_role( 'editor' );
		$_POST = array(
			'action' => 'delete',
			'url' => 'http://example.org/?p=' . $post_id,
		);
		$this->check( 403, 'cannot delete posts' );
	}

	function test_undelete() {
		$post_id = self::insert_post();
		wp_trash_post( $post_id );
		$this->assertEquals( 'trash', get_post( $post_id )->post_status );

		$_POST = array(
			'action' => 'undelete',
			'url' => 'http://example.org/?p=' . $post_id,
		);
		$this->check( 200 );
		$this->assertEquals( 'publish', get_post( $post_id )->post_status );
	}

	function test_undelete_post_not_found() {
		$_POST = array(
			'action' => 'undelete',
			'url' => 'http://example.org/?p=999',
		);
		$this->check( 400, array(
			'error' => 'invalid_request',
			'error_description' => 'deleted post http://example.org/?p=999 not found',
		));
	}

	function test_undelete_user_cannot_undelete_posts() {
		$post_id = self::insert_post();
		get_user_by( 'ID', $this->userid )->remove_role( 'editor' );
		$_POST = array(
			'action' => 'undelete',
			'url' => 'http://example.org/?p=' . $post_id,
		);
		$this->check( 403, 'cannot undelete posts' );
	}

	function test_unknown_action() {
		$post_id = self::insert_post();
		$_POST = array(
			'action' => 'foo',
			'url' => 'http://example.org/?p=' . $post_id,
		);
		$this->check( 400, 'unknown action' );
	}

	function test_bad_content_type() {
		Recorder::$request_headers = array( 'content-type' => 'not/supported' );
		$_POST = array( 'content' => 'foo' );
		$this->check( 400, 'unsupported content type not/supported' );
	}
}
