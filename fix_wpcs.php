<?php
$content = file_get_contents('includes/class-image-optimisation.php');

// Fix doc blocks
$search_doc = <<<EOT
	private array \$options;

	private array \$exclude_convert_imgs = array();
	private array \$preload_front_page_urls = array();
	private array \$exclude_post_type_imgs = array();
	private array \$exclude_sizes = array();
	private array \$exclude_lazy_imgs = array();
	private array \$exclude_lazy_videos = array();
EOT;

$replace_doc = <<<EOT
	private array \$options;

	/**
	 * List of images to exclude from conversion.
	 *
	 * @var array
	 * @since 1.6.0
	 */
	private array \$exclude_convert_imgs = array();

	/**
	 * Preload front page image URLs.
	 *
	 * @var array
	 * @since 1.6.0
	 */
	private array \$preload_front_page_urls = array();

	/**
	 * Exclude post type images.
	 *
	 * @var array
	 * @since 1.6.0
	 */
	private array \$exclude_post_type_imgs = array();

	/**
	 * Exclude image sizes.
	 *
	 * @var array
	 * @since 1.6.0
	 */
	private array \$exclude_sizes = array();

	/**
	 * List of images to exclude from lazy loading.
	 *
	 * @var array
	 * @since 1.6.0
	 */
	private array \$exclude_lazy_imgs = array();

	/**
	 * List of videos to exclude from lazy loading.
	 *
	 * @var array
	 * @since 1.6.0
	 */
	private array \$exclude_lazy_videos = array();
EOT;

$content = str_replace($search_doc, $replace_doc, $content);


// Fix missing param doc comments
$search_param1 = <<<EOT
		 * @param string \$srcset             The srcset string from the image tag.
		 * @param array  \$image_optimisation Image optimization configuration array.
		 * @return array Array of parsed sources: array( 'url' => string, 'width' => int ).
		 */
		private function parse_srcset_data( \$srcset, \$image_optimisation, \$exclude_sizes = null ): array {
EOT;

$replace_param1 = <<<EOT
		 * @param string \$srcset             The srcset string from the image tag.
		 * @param array  \$image_optimisation Image optimization configuration array.
		 * @param array  \$exclude_sizes      Array of excluded sizes.
		 * @return array Array of parsed sources: array( 'url' => string, 'width' => int ).
		 */
		private function parse_srcset_data( \$srcset, \$image_optimisation, \$exclude_sizes = null ): array {
EOT;

$content = str_replace($search_param1, $replace_param1, $content);

$search_param2 = <<<EOT
		 * @param string \$img_tag       The original <img> tag to process.
		 * @param string \$original_src  The original src attribute value of the image.
		 * @param array  \$exclude_imgs  List of URL substrings; if any is present in the image URL, source descriptors are not added.
		 * @return string The processed <picture> or <img> HTML fragment (or the original fragment if unchanged).
		 */
		public function process_picture_tag( \$matches, \$img_tag, \$original_src, \$exclude_imgs, \$exclude_sizes = null ) {
EOT;

$replace_param2 = <<<EOT
		 * @param string \$img_tag       The original <img> tag to process.
		 * @param string \$original_src  The original src attribute value of the image.
		 * @param array  \$exclude_imgs  List of URL substrings; if any is present in the image URL, source descriptors are not added.
		 * @param array  \$exclude_sizes Array of excluded sizes.
		 * @return string The processed <picture> or <img> HTML fragment (or the original fragment if unchanged).
		 */
		public function process_picture_tag( \$matches, \$img_tag, \$original_src, \$exclude_imgs, \$exclude_sizes = null ) {
EOT;

$content = str_replace($search_param2, $replace_param2, $content);


// Inline comment missing full stop
$search_comment = '// Pre-compute exclude sizes here so we don\'t do it per-image inside process_picture_tag';
$replace_comment = '// Pre-compute exclude sizes here so we don\'t do it per-image inside process_picture_tag.';
$content = str_replace($search_comment, $replace_comment, $content);

// The method parameter $exclude_sizes is never used in process_picture_tag
// Need to pass it to parse_srcset_data
$search_usage = '$parsed_sources = $this->parse_srcset_data( $srcset, $this->options[\'image_optimisation\'] );'; // Wait, let's grep to see how process_picture_tag calls parse_srcset_data
file_put_contents('includes/class-image-optimisation.php', $content);
