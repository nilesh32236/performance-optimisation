<?php
/**
 * Test the Database class.
 *
 * @package PerformanceOptimise
 * @since 2.0.0
 */

use PerformanceOptimise\Inc\Lib\Database;

/**
 * Class Test_Database
 *
 * @package PerformanceOptimise
 */
class Test_Database extends WP_UnitTestCase {

	/**
	 * Test that post revisions are deleted.
	 */
	public function test_delete_revisions() {
		$post_id = $this->factory->post->create();
		wp_update_post( array( 'post_ID' => $post_id, 'post_content' => 'new content' ) );
		$revisions = wp_get_post_revisions( $post_id );
		$this->assertCount( 1, $revisions );

		$database = new Database( array( 'database' => array( 'revisions' => true ) ) );
		$database->run_cleanup();

		$revisions = wp_get_post_revisions( $post_id );
		$this->assertCount( 0, $revisions );
	}

	/**
	 * Test that spam comments are deleted.
	 */
	public function test_delete_spam_comments() {
		$post_id = $this->factory->post->create();
		$this->factory->comment->create( array( 'comment_post_ID' => $post_id, 'comment_approved' => 'spam' ) );
		$comments = get_comments( array( 'status' => 'spam' ) );
		$this->assertCount( 1, $comments );

		$database = new Database( array( 'database' => array( 'spam_comments' => true ) ) );
		$database->run_cleanup();

		$comments = get_comments( array( 'status' => 'spam' ) );
		$this->assertCount( 0, $comments );
	}

	/**
	 * Test that transients are deleted.
	 */
	public function test_delete_transients() {
		set_transient( 'my_transient', 'my_value', 60 );
		$transient = get_transient( 'my_transient' );
		$this->assertEquals( 'my_value', $transient );

		$database = new Database( array( 'database' => array( 'transients' => true ) ) );
		$database->run_cleanup();

		$transient = get_transient( 'my_transient' );
		$this->assertFalse( $transient );
	}
}
