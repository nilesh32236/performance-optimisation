```php
<<<<<<< SEARCH
		/**
		 * Clear the per-request LCP/preload static caches.
		 *
		 * Invoked from `Image_Optimisation::clear_runtime_caches()` (wired to
		 * `switch_blog` and `wppo_after_cache_clear`); never call directly.
		 *
		 * @since 2.4.0
		 * @return void
		 */
		public static function clear_lcp_preload_caches(): void {
			self::$preload_emitted                = array();
			self::$preload_emitted_urls           = array();
			self::$heuristic_lcp_memo             = array();
			self::$responsive_lcp_preload_emitted = false;
		}
=======
		/**
		 * Normalized + size-preserving LCP URL memo, keyed by candidate URL.
		 *
		 * @var array<string, array{valid:bool, normalized?:string, exact?:string, lcp_path?:string}>
		 * @since NEXT
		 */
		private static array $fetchpriority_url_memos = array();

		/**
		 * Clear the per-request LCP/preload static caches.
		 *
		 * Invoked from `Image_Optimisation::clear_runtime_caches()` (wired to
		 * `switch_blog` and `wppo_after_cache_clear`); never call directly.
		 *
		 * @since 2.4.0
		 * @return void
		 */
		public static function clear_lcp_preload_caches(): void {
			self::$preload_emitted                = array();
			self::$preload_emitted_urls           = array();
			self::$heuristic_lcp_memo             = array();
			self::$responsive_lcp_preload_emitted = false;
			self::$fetchpriority_url_memos        = array();
		}
>>>>>>> REPLACE
<<<<<<< SEARCH
			// Defense in depth: resolve_fetchpriority_lcp_url() guards on memo miss only.
			try {
				if ( ! $this->is_image_lcp_url( $lcp_url ) || ! $this->is_allowed_hero_preload_url( $lcp_url ) ) {
					return $attr;
				}
			} catch ( \Throwable $e ) {
				return $this->fail_open_attr( $e, $attr );
			}

			static $url_memos = array();
			$memo_key         = $this->get_lcp_memo_key();

			if ( isset( $url_memos[ $memo_key ] ) && $url_memos[ $memo_key ]['url'] === $lcp_url ) {
				$normalized_lcp = $url_memos[ $memo_key ]['normalized'];
				$exact_lcp      = $url_memos[ $memo_key ]['exact'];
			} else {
				try {
					$normalized_lcp         = $this->normalize_image_url( $lcp_url );
					$exact_lcp              = '' === $normalized_lcp ? '' : $this->normalize_image_url( $lcp_url, false );
					$url_memos[ $memo_key ] = array(
						'url'        => $lcp_url,
						'normalized' => $normalized_lcp,
						'exact'      => $exact_lcp,
					);
				} catch ( \Throwable $e ) {
					return $this->fail_open_attr( $e, $attr );
				}
			}

			if ( '' === $normalized_lcp || '' === $exact_lcp ) {
				return $attr;
			}

			$size_is_full  = ( 'full' === $size );
			$is_lcp        = false;
			$attachment_id = 0;

			try {
				if ( is_object( $attachment ) && isset( $attachment->ID ) ) {
					$attachment_id = (int) $attachment->ID;
				} elseif ( is_numeric( $attachment ) ) {
					$attachment_id = (int) $attachment;
				} elseif ( is_array( $attachment ) && isset( $attachment['ID'] ) && is_numeric( $attachment['ID'] ) ) {
					$attachment_id = (int) $attachment['ID'];
				}
			} catch ( \Throwable $e ) {
				return $this->fail_open_attr( $e, $attr );
			}

			if ( $attachment_id > 0 && function_exists( 'wp_get_attachment_image_src' ) ) {
				try {
					$lookup_size = ( null === $size || '' === $size ) ? 'thumbnail' : $size;
					$src_data    = wp_get_attachment_image_src( $attachment_id, $lookup_size );
					$candidate   = '';

					if ( is_array( $src_data ) && isset( $src_data[0] ) && is_string( $src_data[0] ) ) {
						$candidate = $src_data[0];
					} elseif ( is_string( $src_data ) ) {
						$candidate = $src_data;
					}

					$is_lcp = '' !== $candidate && $this->fetchpriority_candidate_matches( $candidate, $normalized_lcp, $exact_lcp, $size_is_full );
				} catch ( \Throwable $e ) {
					return $this->fail_open_attr( $e, $attr );
				}
			}

			if ( ! $is_lcp ) {
				// Fallback when the attachment ID is unresolvable (bare
				// array context): compare the built src/srcset against
				// the candidate with normalized-URL equality only (no
				// substring fallback, mirroring tag_matches_lcp_url()).
				// The src/data-src entries use the same size-aware rule
				// as the ID path; srcset entries require an exact match.
				foreach ( array( 'src', 'data-src' ) as $key ) {
					if ( isset( $attr[ $key ] ) && is_string( $attr[ $key ] ) && '' !== $attr[ $key ] && $this->fetchpriority_candidate_matches( $attr[ $key ], $normalized_lcp, $exact_lcp, $size_is_full ) ) {
						$is_lcp = true;
						break;
					}
				}

				if ( ! $is_lcp && isset( $attr['srcset'] ) && is_string( $attr['srcset'] ) && '' !== $attr['srcset'] ) {
					$is_lcp = $this->has_exact_lcp_srcset_entry( $attr['srcset'], $exact_lcp );
				}
			}
=======
			if ( isset( self::$fetchpriority_url_memos[ $lcp_url ] ) ) {
				if ( ! self::$fetchpriority_url_memos[ $lcp_url ]['valid'] ) {
					return $attr;
				}
				$normalized_lcp = self::$fetchpriority_url_memos[ $lcp_url ]['normalized'];
				$exact_lcp      = self::$fetchpriority_url_memos[ $lcp_url ]['exact'];
				$lcp_path       = self::$fetchpriority_url_memos[ $lcp_url ]['lcp_path'];
			} else {
				try {
					if ( ! $this->is_image_lcp_url( $lcp_url ) || ! $this->is_allowed_hero_preload_url( $lcp_url ) ) {
						self::$fetchpriority_url_memos[ $lcp_url ] = array( 'valid' => false );
						return $attr;
					}
					$normalized_lcp = $this->normalize_image_url( $lcp_url );
					$exact_lcp      = '' === $normalized_lcp ? '' : $this->normalize_image_url( $lcp_url, false );

					$slash    = strpos( $exact_lcp, '/' );
					$lcp_path = false === $slash ? $exact_lcp : substr( $exact_lcp, $slash );

					self::$fetchpriority_url_memos[ $lcp_url ] = array(
						'valid'      => true,
						'normalized' => $normalized_lcp,
						'exact'      => $exact_lcp,
						'lcp_path'   => $lcp_path,
					);
				} catch ( \Throwable $e ) {
					return $this->fail_open_attr( $e, $attr );
				}
			}

			if ( '' === $normalized_lcp || '' === $exact_lcp ) {
				return $attr;
			}

			$size_is_full  = ( 'full' === $size );
			$is_lcp        = $this->attr_references_lcp( $attr, $normalized_lcp, $exact_lcp, $lcp_path, $size_is_full );

			if ( ! $is_lcp ) {
				$attachment_id = 0;
				try {
					if ( is_object( $attachment ) && isset( $attachment->ID ) ) {
						$attachment_id = (int) $attachment->ID;
					} elseif ( is_numeric( $attachment ) ) {
						$attachment_id = (int) $attachment;
					} elseif ( is_array( $attachment ) && isset( $attachment['ID'] ) && is_numeric( $attachment['ID'] ) ) {
						$attachment_id = (int) $attachment['ID'];
					}
				} catch ( \Throwable $e ) {
					return $this->fail_open_attr( $e, $attr );
				}

				if ( $attachment_id > 0 ) {
					$is_lcp = $this->attachment_src_is_lcp( $attachment_id, $size, $normalized_lcp, $exact_lcp, $size_is_full );
				}
			}
>>>>>>> REPLACE
<<<<<<< SEARCH
			// Stamp the hero triple: eager first so the core-parity
			// invariant (never lazy + high) always holds, then high,
			// then the decoding default when absent.
			$attr['loading']       = 'eager';
			$attr['fetchpriority'] = 'high';
			if ( ! isset( $attr['decoding'] ) || ! is_string( $attr['decoding'] ) || '' === $attr['decoding'] ) {
				$attr['decoding'] = 'async';
			}

			return $attr;
		}

		/**
		 * Swallows an exception and returns attributes unmodified (fail-open helper).
		 *
		 * @since NEXT
		 * @param \Throwable $e    The exception.
		 * @param mixed      $attr The original attributes.
		 * @return mixed The attributes unmodified.
		 */
		private function fail_open_attr( \Throwable $e, $attr ) {
			unset( $e );
			return $attr;
		}

		/**
		 * Check if a srcset contains a candidate matching the exact LCP URL.
		 *
		 * A srcset inherently lists sized variants, so a suffix-insensitive fallback
		 * would stamp every image whose srcset merely contains a thumbnail of the hero.
		 * Thrown exceptions on parsing a candidate are swallowed to skip the malformed
		 * entry and continue checking.
		 *
		 * @since NEXT
		 * @param string $srcset    The raw srcset string.
		 * @param string $exact_lcp The exact normalized LCP URL, size suffix preserved
		 *                          (see normalize_image_url( $url, false )).
		 * @return bool True if a match is found.
		 */
		private function has_exact_lcp_srcset_entry( string $srcset, string $exact_lcp ): bool {
			$slash    = strpos( $exact_lcp, '/' );
			$lcp_path = false === $slash ? $exact_lcp : substr( $exact_lcp, $slash );
			if ( '' === $lcp_path ) {
				return false;
			}
			if ( false === strpos( $srcset, $lcp_path ) && false === strpos( $srcset, ltrim( $lcp_path, '/' ) ) ) {
				return false; // Cheap reject: no split, no per-candidate normalize.
			}

			$candidates = preg_split( '/\s*,\s*/', trim( $srcset ) );
			if ( ! is_array( $candidates ) ) {
				return false;
			}
			foreach ( $candidates as $candidate ) {
				$parts         = preg_split( '/\s+/', trim( (string) $candidate ), 2 );
				$candidate_url = is_array( $parts ) && isset( $parts[0] ) ? $parts[0] : '';
				if ( '' === $candidate_url ) {
					continue;
				}
				try {
					if ( $this->normalize_image_url( $candidate_url, false ) === $exact_lcp ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			return false;
		}
=======
			// Stamp the hero triple: eager first so the core-parity
			// invariant (never lazy + high) always holds, then high,
			// then the decoding default when absent.
			$attr['loading'] = 'eager';
			if ( ! isset( $attr['fetchpriority'] ) || ! is_string( $attr['fetchpriority'] ) || '' === $attr['fetchpriority'] ) {
				$attr['fetchpriority'] = 'high';
			}
			if ( ! isset( $attr['decoding'] ) || ! is_string( $attr['decoding'] ) || '' === $attr['decoding'] ) {
				$attr['decoding'] = 'async';
			}

			return $attr;
		}

		/**
		 * Swallows an exception and returns attributes unmodified (fail-open helper).
		 *
		 * @since NEXT
		 * @param \Throwable $e    The exception.
		 * @param mixed      $attr The original attributes.
		 * @return mixed The attributes unmodified.
		 */
		private function fail_open_attr( \Throwable $e, $attr ): mixed {
			do_action( 'wppo_debug_log', 'WPPO LCP prioritization failed.', array( 'exception' => $e ) );
			return $attr;
		}

		/**
		 * Check if an attachment ID matches the LCP candidate.
		 *
		 * @since NEXT
		 * @param int    $attachment_id  The attachment ID.
		 * @param mixed  $size           The requested image size.
		 * @param string $normalized_lcp The normalized LCP URL.
		 * @param string $exact_lcp      The exact normalized LCP URL.
		 * @param bool   $size_is_full   Whether the requested size is 'full'.
		 * @return bool True if the attachment matches the LCP URL.
		 */
		private function attachment_src_is_lcp( int $attachment_id, $size, string $normalized_lcp, string $exact_lcp, bool $size_is_full ): bool {
			if ( ! function_exists( 'wp_get_attachment_image_src' ) ) {
				return false;
			}
			try {
				$lookup_size = ( null === $size || '' === $size ) ? 'thumbnail' : $size;
				$src_data    = wp_get_attachment_image_src( $attachment_id, $lookup_size );
				$candidate   = '';

				if ( is_array( $src_data ) && isset( $src_data[0] ) && is_string( $src_data[0] ) ) {
					$candidate = $src_data[0];
				} elseif ( is_string( $src_data ) ) {
					$candidate = $src_data;
				}

				return '' !== $candidate && $this->fetchpriority_candidate_matches( $candidate, $normalized_lcp, $exact_lcp, $size_is_full );
			} catch ( \Throwable $e ) {
				do_action( 'wppo_debug_log', 'WPPO LCP prioritization failed.', array( 'exception' => $e ) );
				return false;
			}
		}

		/**
		 * Check if image attributes reference the LCP candidate.
		 *
		 * @since NEXT
		 * @param array  $attr           The image attributes.
		 * @param string $normalized_lcp The normalized LCP URL.
		 * @param string $exact_lcp      The exact normalized LCP URL.
		 * @param string $lcp_path       The exact LCP URL path.
		 * @param bool   $size_is_full   Whether the requested size is 'full'.
		 * @return bool True if the attributes reference the LCP URL.
		 */
		private function attr_references_lcp( array $attr, string $normalized_lcp, string $exact_lcp, string $lcp_path, bool $size_is_full ): bool {
			foreach ( array( 'src', 'data-src' ) as $key ) {
				if ( isset( $attr[ $key ] ) && is_string( $attr[ $key ] ) && '' !== $attr[ $key ] && $this->fetchpriority_candidate_matches( $attr[ $key ], $normalized_lcp, $exact_lcp, $size_is_full ) ) {
					return true;
				}
			}

			if ( isset( $attr['srcset'] ) && is_string( $attr['srcset'] ) && '' !== $attr['srcset'] ) {
				return $this->has_exact_lcp_srcset_entry( $attr['srcset'], $exact_lcp, $lcp_path );
			}

			return false;
		}

		/**
		 * Check if a srcset contains a candidate matching the exact LCP URL.
		 *
		 * A srcset inherently lists sized variants, so a suffix-insensitive fallback
		 * would stamp every image whose srcset merely contains a thumbnail of the hero.
		 * Thrown exceptions on parsing a candidate are swallowed to skip the malformed
		 * entry and continue checking.
		 *
		 * @since NEXT
		 * @param string $srcset    The raw srcset string.
		 * @param string $exact_lcp The exact normalized LCP URL, size suffix preserved
		 *                          (see normalize_image_url( $url, false )).
		 * @param string $lcp_path  The path portion of the exact LCP URL.
		 * @return bool True if a match is found.
		 */
		private function has_exact_lcp_srcset_entry( string $srcset, string $exact_lcp, string $lcp_path ): bool {
			if ( '' === $lcp_path ) {
				return false;
			}
			if ( false === strpos( $srcset, $lcp_path ) ) {
				return false; // Cheap reject: no split, no per-candidate normalize.
			}

			try {
				foreach ( $this->owner->lcp_split_srcset_candidates( $srcset ) as $part ) {
					$url = $this->owner->lcp_split_srcset_item( $part )[0];
					if ( '' === $url ) {
						continue;
					}
					if ( $this->normalize_image_url( $url, false ) === $exact_lcp ) {
						return true;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return false;
		}
>>>>>>> REPLACE
```
