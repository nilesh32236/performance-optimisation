<?php
$content = file_get_contents('includes/class-image-optimisation.php');

// Fix missing param doc comments for process_picture_tag
$search_param2 = <<<EOT
		 * @param string \$img_tag       The original <img> tag to process.
		 * @param string \$original_src  The original src attribute value of the image.
		 * @param array  \$exclude_imgs  List of URL substrings; if any is present in the image URL, source descriptors are not added.
		 * @param array  \$exclude_sizes Array of excluded sizes.
		 * @return string The processed <picture> or <img> HTML fragment (or the original fragment if unchanged).
		 */
		public function process_picture_tag( \$matches, \$img_tag, \$original_src, \$exclude_imgs, \$exclude_sizes = null ) {
EOT;

$replace_param2 = <<<EOT
		 * @param array  \$matches       Regex match array containing the matched <img> or <picture> fragment.
		 * @param string \$img_tag       The original <img> tag to process.
		 * @param string \$original_src  The original src attribute value of the image.
		 * @param array  \$exclude_imgs  List of URL substrings; if any is present in the image URL, source descriptors are not added.
		 * @param array  \$exclude_sizes Array of excluded sizes.
		 * @return string The processed <picture> or <img> HTML fragment (or the original fragment if unchanged).
		 */
		public function process_picture_tag( \$matches, \$img_tag, \$original_src, \$exclude_imgs, \$exclude_sizes = null ) {
EOT;

$content = str_replace($search_param2, $replace_param2, $content);

// We need to pass $exclude_sizes into parse_srcset_data
$search_usage = '$parsed_sources = $this->parse_srcset_data( $srcset, $this->options[\'image_optimisation\'] );';
$replace_usage = '$parsed_sources = $this->parse_srcset_data( $srcset, $this->options[\'image_optimisation\'], $exclude_sizes );';
$content = str_replace($search_usage, $replace_usage, $content);

file_put_contents('includes/class-image-optimisation.php', $content);
