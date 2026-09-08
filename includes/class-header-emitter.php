<?php
/**
 * Header emitter — single consolidation point for dynamic header() emission.
 *
 * Centralises all LiteSpeed / ESI dynamic header emission behind CRLF-safe
 * helpers so header-injection hardening lives in one place. Thin delegation
 * keeps every existing call site working with zero behaviour change.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Header_Emitter' ) ) {
	/**
	 * Class Header_Emitter
	 *
	 * Consolidates dynamic header() emission (LiteSpeed purge/tag/TTL/vary
	 * plus ESI private/no-cache pairs) with CR/LF/NUL stripping.
	 *
	 * @since NEXT
	 */
	final class Header_Emitter {

		/**
		 * Strip CR/LF (and NUL) bytes from a value before it is passed to header().
		 *
		 * PHP itself rejects multi-line header() values, but this keeps every
		 * dynamic header emission safe against filter-injected control
		 * characters instead of relying on PHP's failure mode.
		 *
		 * @since NEXT
		 * @param string $value Header value to clean.
		 * @return string Value without header-breaking control characters.
		 */
		public static function strip_crlf( string $value ): string {
			return str_replace( array( "\r", "\n", "\0" ), '', $value );
		}

		/**
		 * Emit a single header line (CRLF-safe, headers_sent-guarded).
		 *
		 * No-op when headers were already sent; otherwise emits the
		 * CRLF-stripped header via header().
		 *
		 * @since NEXT
		 * @param string $header  Full header line (e.g. 'X-LiteSpeed-Tag: WPPO').
		 * @param bool   $replace Whether to replace a previous similar header.
		 * @return bool True when emitted, false when headers were already sent.
		 */
		public static function emit( string $header, bool $replace = true ): bool {
			$headers_sent = function_exists( 'headers_sent' ) ? headers_sent() : false;
			if ( $headers_sent ) {
				return false;
			}
			header( self::strip_crlf( $header ), $replace );
			return true;
		}

		/**
		 * Emit the ESI private pair (Cache-Control + X-LiteSpeed-Cache-Control).
		 *
		 * Repeated 5x across the ESI bridge (AJAX fragments + send_headers
		 * cart/checkout/account/punch-hole paths); one helper pins the exact
		 * pair so the strings cannot drift. Uses the default replace=true to
		 * preserve the pre-extraction header() semantics (issue #905 review).
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function emit_private_pair(): void {
			self::emit( 'Cache-Control: private,no-cache' );
			self::emit( 'X-LiteSpeed-Cache-Control: private,no-vary' );
		}

		/**
		 * Emit the ESI no-cache pair (Cache-Control + X-LiteSpeed-Cache-Control).
		 *
		 * Used for the admin / no-cache ESI path. Uses the default
		 * replace=true to preserve the pre-extraction header() semantics
		 * (issue #905 review).
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function emit_nocache_pair(): void {
			self::emit( 'Cache-Control: no-cache' );
			self::emit( 'X-LiteSpeed-Cache-Control: no-cache' );
		}

		/**
		 * Emit an X-LiteSpeed-Purge tag header (CRLF-safe).
		 *
		 * @since NEXT
		 * @param string $tag_str Comma-separated tag list (unsanitized).
		 * @return bool True when emitted, false when headers were already sent.
		 */
		public static function emit_purge_tag( string $tag_str ): bool {
			return self::emit( 'X-LiteSpeed-Purge: tag=' . $tag_str, false );
		}

		/**
		 * Emit an X-LiteSpeed-Tag header (CRLF-safe).
		 *
		 * @since NEXT
		 * @param string $tag Single tag value (unsanitized).
		 * @return bool True when emitted, false when headers were already sent.
		 */
		public static function emit_tag( string $tag ): bool {
			return self::emit( 'X-LiteSpeed-Tag: ' . $tag, false );
		}

		/**
		 * Emit an ESI tag header (X-LiteSpeed-Tag: ESI.{action}).
		 *
		 * @since NEXT
		 * @param string $action ESI action name (unsanitized).
		 * @return bool True when emitted, false when headers were already sent.
		 */
		public static function emit_esi_tag( string $action ): bool {
			return self::emit( 'X-LiteSpeed-Tag: ESI.' . $action, false );
		}

		/**
		 * Remove generic Cache-Control/Pragma when the LiteSpeed public header is sent.
		 *
		 * Prevents Cache-Control: no-cache conflicting with
		 * X-LiteSpeed-Cache-Control: public,max-age=N. Guarded on headers_sent().
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function remove_generic_cache_control(): void {
			if ( function_exists( 'headers_sent' ) && headers_sent() ) {
				return;
			}
			if ( function_exists( 'header_remove' ) ) {
				header_remove( 'Cache-Control' );
				header_remove( 'Pragma' );
			}
		}
	}
}
