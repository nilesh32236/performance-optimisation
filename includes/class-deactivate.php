<?php

namespace PerformanceOptimise\Inc;

class Deactivate {
	public static function init() {
		require_once QTPO_PLUGIN_PATH . 'includes/class-htaccess.php';
		require_once QTPO_PLUGIN_PATH . 'includes/class-static-file-handler.php';

		Htaccess::remove_htaccess();
		Static_File_Handler::remove();
	}
}
