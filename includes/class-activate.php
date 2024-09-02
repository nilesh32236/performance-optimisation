<?php

namespace PerformanceOptimise\Inc;

class Activate {
	public static function init() {
		require_once QTPO_PLUGIN_PATH . 'includes/class-htaccess.php';
		require_once QTPO_PLUGIN_PATH . 'includes/class-static-file-handler.php';

		Htaccess::modify_htaccess();
		Static_File_Handler::create();
	}
}

