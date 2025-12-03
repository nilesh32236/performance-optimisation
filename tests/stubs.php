<?php
// Global namespace stubs

if (!class_exists('WP_Filesystem_Base')) {
	class WP_Filesystem_Base {
		public function exists($path) {}
		public function size($path) {}
		public function get_contents($path) {}
		public function put_contents($path, $contents, $mode = false) {}
		public function chmod($path, $mode = false, $recursive = false) {}
		public function delete($path, $recursive = false, $type = false) {}
		public function rmdir($path, $recursive = false) {}
		public function mtime($path) {}
		public function is_dir($path) {}
		public function is_writable($path) {}
		public function copy($source, $destination, $overwrite = false, $mode = false) {}
		public function move($source, $destination, $overwrite = false) {}
		public function is_readable($path) {}
	}
}
