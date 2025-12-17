<?php
namespace PerformanceOptimisation\Tests\Unit;

use PerformanceOptimisation\Services\AssetOptimizationService;
use PerformanceOptimisation\Services\SettingsService;
use PerformanceOptimisation\Optimizers\CssOptimizer;
use PerformanceOptimisation\Optimizers\JsOptimizer;
use PerformanceOptimisation\Optimizers\HtmlOptimizer;
use PHPUnit\Framework\TestCase;
use Brain\Monkey\Functions;
use Mockery;

class AssetOptimizationServiceTest extends TestCase {

	protected $settings_service;
	protected $css_optimizer;
	protected $js_optimizer;
	protected $html_optimizer;

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		$this->settings_service = Mockery::mock(SettingsService::class);
		$this->css_optimizer = Mockery::mock(CssOptimizer::class);
		$this->js_optimizer = Mockery::mock(JsOptimizer::class);
		$this->html_optimizer = Mockery::mock(HtmlOptimizer::class);
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_init_registers_filters_when_enabled() {
		$this->settings_service->shouldReceive('get_setting')->with('minification', 'minify_css')->andReturn(true);
		$this->settings_service->shouldReceive('get_setting')->with('minification', 'minify_js')->andReturn(true);
		$this->settings_service->shouldReceive('get_setting')->with('minification', 'minify_html')->andReturn(true);

		Functions\expect('add_filter')->once()->with('style_loader_tag', Mockery::type('array'), 10, 4);
		Functions\expect('add_filter')->once()->with('script_loader_tag', Mockery::type('array'), 10, 3);
		Functions\expect('add_action')->once()->with('template_redirect', Mockery::type('array'), 0);

		$service = new AssetOptimizationService(
			$this->settings_service,
			$this->css_optimizer,
			$this->js_optimizer,
			$this->html_optimizer
		);

		$service->init();
	}

	public function test_optimize_css_tag_replaces_href_with_optimized_url() {
		// Create a real temp file so file_exists returns true without mocking
		$temp_file = tempnam(sys_get_temp_dir(), 'css');
		$temp_url = 'http://example.com/wp-content/' . basename($temp_file);
		
		$tag = '<link rel="stylesheet" href="' . $temp_url . '" />';
		$handle = 'main-style';
		$media = 'all';

		Functions\when('is_admin')->justReturn(false);
		Functions\when('is_user_logged_in')->justReturn(false);
		Functions\when('content_url')->justReturn('http://example.com/wp-content');
		Functions\when('wp_normalize_path')->returnArg();
		// Functions\when('file_exists')->justReturn(true); // Removed internal function mock

		// We need to ensure getLocalPath returns the $temp_file path
		// FileSystemUtil::getLocalPath does: str_replace(content_url(), WP_CONTENT_DIR, $url)
		// We need WP_CONTENT_DIR to be sys_get_temp_dir() effectively for this test setup?
		// Or we can just mock FileSystemUtil::getLocalPath? We can't easily.
		// Let's redefine WP_CONTENT_DIR? Constants cannot be redefined.
		// It's defined in bootstrap as /tmp/wordpress/wp-content.
		// So if we create a file at /tmp/wordpress/wp-content/style.css it works.

		// Let's rely on the file actually existing at the path calculated.
		// If WP_CONTENT_DIR is /tmp/wordpress/wp-content, we should mkdir -p it.
		@mkdir('/tmp/wordpress/wp-content', 0777, true);
		file_put_contents('/tmp/wordpress/wp-content/style.css', 'body{color:red}');
		
		$href = 'http://example.com/wp-content/style.css';
		$tag = '<link rel="stylesheet" href="' . $href . '" />';

		Functions\when('wp_upload_dir')->justReturn([
			'basedir' => '/tmp/wordpress/wp-content/uploads', 
			'baseurl' => 'http://example.com/wp-content/uploads'
		]);

		$this->settings_service->shouldReceive('get_setting')->with('minification', 'exclude_css', [])->andReturn([]);
		$this->settings_service->shouldReceive('get_setting')->with('file_optimisation', 'exclude_css_files', [])->andReturn([]);

		$this->css_optimizer->shouldReceive('optimizeFile')
			->once()
			->andReturn([
				'success' => true,
				'optimized_path' => '/tmp/wordpress/wp-content/uploads/min/style.min.css'
			]);

		$service = new AssetOptimizationService(
			$this->settings_service,
			$this->css_optimizer,
			$this->js_optimizer,
			$this->html_optimizer
		);

		$optimized_tag = $service->optimize_css_tag($tag, $handle, $href, $media);

		$this->assertStringContainsString('http://example.com/wp-content/uploads/min/style.min.css', $optimized_tag);
	}
}
