<?php
namespace PerformanceOptimisation\Tests\Unit;

use PerformanceOptimisation\Services\SettingsService;
use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Services\ConfigurationService;
use PerformanceOptimisation\Utils\ValidationUtil;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\PerformanceUtil;
use PHPUnit\Framework\TestCase;
use Brain\Monkey\Functions;
use Mockery;

class SettingsServiceTest extends TestCase {

	protected $container;
	protected $config_service;
	protected $validator;
	protected $logger;
	protected $performance;

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		$this->container = Mockery::mock(ServiceContainerInterface::class);
		$this->config_service = Mockery::mock(ConfigurationService::class);
		$this->validator = Mockery::mock(ValidationUtil::class);
		$this->logger = Mockery::mock(LoggingUtil::class);
		$this->performance = Mockery::mock(PerformanceUtil::class);
		
		// Setup container mocks
		$this->container->shouldReceive('get')->with('validator')->andReturn($this->validator);
		$this->container->shouldReceive('get')->with('logger')->andReturn($this->logger);
		$this->container->shouldReceive('get')->with('performance')->andReturn($this->performance);
		
		// Mock utility methods to prevent errors during init
		$this->logger->shouldReceive('debug');
		$this->logger->shouldReceive('info');
		$this->logger->shouldReceive('error');
		$this->performance->shouldReceive('startTimer');
		$this->performance->shouldReceive('endTimer');
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_get_settings_uses_config_service_if_available() {
		// Mock container to return config service
		$this->container->shouldReceive('get')
			->with('PerformanceOptimisation\\Services\\ConfigurationService')
			->andReturn($this->config_service);

		$expected_settings = ['caching' => ['page_cache_enabled' => true]];

		$this->config_service->shouldReceive('all')
			->once()
			->andReturn($expected_settings);

		// Use callback for get_option to be safe
		Functions\when('get_option')->alias(function ($key, $default = null) {
			if ($key === 'wppo_settings_version') {
				return '2.0.0';
			}
			return $default;
		});
		
		// initialize_default_settings check
		$this->config_service->shouldReceive('has')->with('caching.page_cache_enabled')->andReturn(true);

		$service = new SettingsService($this->container);
		$settings = $service->get_settings();

		$this->assertEquals($expected_settings, $settings);
	}

	public function test_get_settings_updates_option_if_config_service_fails() {
		// Mock container to throw exception for config service
		$this->container->shouldReceive('get')
			->with('PerformanceOptimisation\\Services\\ConfigurationService')
			->andThrow(new \Exception('Service not found'));

		$expected_settings = ['caching' => ['enabled' => true]];
		
		// Use a callback to return different values based on the key
		Functions\when('get_option')->alias(function ($key, $default = null) use ($expected_settings) {
			if ($key === 'wppo_settings') {
				return $expected_settings;
			}
			if ($key === 'wppo_settings_version') {
				return '2.0.0';
			}
			return $default;
		});

		$service = new SettingsService($this->container);
		$settings = $service->get_settings();

		$this->assertEquals($expected_settings, $settings);
	}

	public function test_update_settings_calls_config_update() {
		$this->container->shouldReceive('get')
			->with('PerformanceOptimisation\\Services\\ConfigurationService')
			->andReturn($this->config_service);

		// Init mocks
		Functions\expect('get_option')->andReturn('2.0.0');
		// Functions\expect('version_compare')->andReturn(false); // Removed internal function mock
		$this->config_service->shouldReceive('has')->andReturn(true);

		$new_settings = ['caching' => ['page_cache_enabled' => false]];
		
		$this->config_service->shouldReceive('update')
			->once()
			->with($new_settings)
			->andReturn(true);
			
		Functions\expect('do_action')->once()->with('wppo_settings_updated', $new_settings, Mockery::type(SettingsService::class));

		$service = new SettingsService($this->container);
		$plugin_result = $service->update_settings($new_settings);

		$this->assertTrue($plugin_result);
	}
}
