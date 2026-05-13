<?php
$content = file_get_contents('includes/class-image-optimisation.php');

// Fix parse_srcset_data call inside get_srcset_preload_items where $exclude_sizes is undefined
$search_fix = <<<EOT
		private function get_srcset_preload_items( \$srcset, \$default_image, \$image_optimisation ): array {
			if ( ! \$srcset ) {
				return array( \$this->prepare_preload_item( \$default_image ) );
			}

			\$parsed_sources = \$this->parse_srcset_data( \$srcset, \$image_optimisation, \$exclude_sizes );
EOT;

$replace_fix = <<<EOT
		private function get_srcset_preload_items( \$srcset, \$default_image, \$image_optimisation ): array {
			if ( ! \$srcset ) {
				return array( \$this->prepare_preload_item( \$default_image ) );
			}

			\$parsed_sources = \$this->parse_srcset_data( \$srcset, \$image_optimisation );
EOT;

$content = str_replace($search_fix, $replace_fix, $content);

file_put_contents('includes/class-image-optimisation.php', $content);
