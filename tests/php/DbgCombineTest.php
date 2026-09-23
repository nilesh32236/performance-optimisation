<?php
use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Css_Combine;
use Brain\Monkey\Functions;
class DbgCombineTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;
	public function test_dbg(): void {
		$GLOBALS['wp_version'] = '6.8.0';
		Functions\when( 'apply_filters' )->alias( static function ( $tag, $value = null ) { return $value; } );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_is_mobile' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'get_option' )->alias( static function ( $name, $default = false ) { return 'wppo_settings' === $name ? array() : $default; } );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'content_url' )->justReturn( 'http://example.com/wp-content' );
		Functions\when( 'get_transient' )->alias( static function ( $k ) { return is_string( $k ) && false !== strpos( $k, 'fallback' ) ? true : false; } );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_should_load_separate_core_block_assets' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		Functions\when( 'wp_style_add_data' )->justReturn( true );
		$deq = array(); $enq = array();
		Functions\when( 'wp_dequeue_style' )->alias( function ( string $h ) use ( &$deq ) { $deq[] = $h; } );
		Functions\when( 'wp_enqueue_style' )->alias( function ( ...$a ) use ( &$enq ) { $enq[] = $a; } );
		$root = WP_CONTENT_DIR . '/wppo-dbg-' . uniqid();
		mkdir( $root . '/cache/example.com', 0777, true );
		mkdir( $root . '/src', 0777, true );
		file_put_contents( $root . '/src/a.css', 'body{color:red}' );
		global $wp_styles, $wp_filesystem;
		$wp_styles = \Mockery::mock();
		$wp_styles->shouldReceive( 'get_data' )->andReturn( false );
		$wp_styles->queue = array( 'a' );
		$wp_styles->registered = array( 'a' => (object) array( 'src' => 'http://example.com/wp-content/' . basename( $root ) . '/src/a.css', 'args' => 'all' ) );
		$wp_filesystem = \Mockery::mock();
		$wp_filesystem->shouldReceive( 'is_dir' )->andReturn( true );
		$wp_filesystem->shouldReceive( 'mkdir' )->andReturn( true );
		$inst = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		foreach ( array(
			'cache_root_dir' => $root . '/cache', 'cache_root_url' => 'http://example.com/wp-content/cache/wppo',
			'domain' => 'example.com', 'options' => array( 'file_optimisation' => array(), 'cache_settings' => array() ),
			'fs_initialized' => true, 'request_uri' => '/', 'url_path' => '',
		) as $k => $v ) { $p = new \ReflectionProperty( Cache::class, $k ); $p->setValue( $inst, $v ); }
		$written = array();
		$fs = \Mockery::mock();
		$fs->shouldReceive( 'exists' )->andReturnUsing( function ( $p ) { return file_exists( $p ); } );
		$fs->shouldReceive( 'mtime' )->andReturnUsing( function ( $p ) { return file_exists( $p ) ? filemtime( $p ) : false; } );
		$fs->shouldReceive( 'size' )->andReturnUsing( function ( $p ) { return file_exists( $p ) ? filesize( $p ) : false; } );
		$fs->shouldReceive( 'get_contents' )->andReturnUsing( function ( $p ) { return file_exists( $p ) ? file_get_contents( $p ) : false; } );
		$tmpc = array();
		$fs->shouldReceive( 'put_contents' )->andReturnUsing( function ( $p, $c ) use ( &$tmpc ) { $tmpc[$p] = $c; file_put_contents( $p, $c ); return true; } );
		$fs->shouldReceive( 'move' )->andReturnUsing( function ( $f, $t ) use ( &$written, &$tmpc ) { if ( isset( $tmpc[$f] ) ) { $written[$t] = $tmpc[$f]; unset( $tmpc[$f] ); } rename( $f, $t ); return true; } );
		$fs->shouldReceive( 'delete' )->andReturn( true );
		$p = new \ReflectionProperty( Cache::class, 'filesystem' );
		$p->setValue( $inst, $fs );
		$svc = new Css_Combine( $inst );
		fwrite( STDERR, "\nfetch-err=" . var_export( $svc->fetch_and_minify_css( array( 'a' ) )['error'], true ) );
		fwrite( STDERR, "\nprepare=" . var_export( $inst->combine_prepare_cache_dir(), true ) );
		$wr = $svc->write_combined_file( 'body{color:red}', '' );
		fwrite( STDERR, "\nwrite=" . var_export( $wr, true ) );
		$inst->combine_css();
		fwrite( STDERR, "\ndeq=" . json_encode( $deq ) . ' enq=' . count( $enq ) . ' written=' . count( $written ) );
		$this->assertTrue( true );
	}
}
