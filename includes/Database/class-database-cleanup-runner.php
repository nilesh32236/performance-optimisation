<?php
/**
 * Database cleanup application runner.
 *
 * Centralizes the dispatch and activity orchestration shared by the REST,
 * Abilities, and WP-CLI adapters while leaving SQL, cleanup health, counts,
 * table storage, scheduling, authorization, and response formatting with
 * their existing owners.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Database_Cleanup_Runner' ) ) {
	/**
	 * Class Database_Cleanup_Runner
	 *
	 * Bounded application boundary for one database-cleanup request.
	 *
	 * @since NEXT
	 */
	final class Database_Cleanup_Runner {

		/**
		 * REST cleanup activity context.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const SOURCE_REST = 'rest';

		/**
		 * Abilities cleanup activity context.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const SOURCE_ABILITY = 'ability';

		/**
		 * WP-CLI cleanup activity context.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const SOURCE_CLI = 'cli';

		/**
		 * Legacy WP-CLI cleanup aliases mapped to canonical cleanup types.
		 *
		 * These aliases are intentionally CLI-only. REST and Abilities expose
		 * the canonical Database_Cleanup type allowlist.
		 *
		 * @since NEXT
		 * @var array<string, string>
		 */
		private const LEGACY_ALIASES = array(
			'drafts'     => 'auto_drafts',
			'trash'      => 'trashed_posts',
			'spam'       => 'spam_comments',
			'trashed'    => 'trashed_comments',
			'transients' => 'expired_transients',
			'orphans'    => 'orphan_postmeta',
			'unattached' => 'unattached_media',
			'oembed'     => 'oembed_cache',
		);

		/**
		 * Get the canonical cleanup types accepted by application adapters.
		 *
		 * @since NEXT
		 * @return string[] Canonical cleanup types, including Action Scheduler and all.
		 */
		public static function valid_types(): array {
			return Database_Cleanup::get_valid_cleanup_types();
		}

		/**
		 * Preview the rows a CLI cleanup request would remove.
		 *
		 * Unknown values and legacy aliases intentionally retain the existing
		 * CLI behavior: they preview the full counts payload because the counts
		 * map contains canonical keys only.
		 *
		 * @since NEXT
		 * @param string $type Requested cleanup type.
		 * @return array{would_delete:array<string,int>}
		 */
		public static function preview( string $type ): array {
			$counts = Database_Cleanup::get_counts();
			if ( 'all' === $type || ! isset( $counts[ $type ] ) ) {
				return array( 'would_delete' => $counts );
			}

			return array(
				'would_delete' => array( $type => $counts[ $type ] ),
			);
		}

		/**
		 * Run one bounded cleanup request through the domain owner.
		 *
		 * The source controls only the activity behavior historically owned by
		 * each adapter: REST logging/table optimization, Abilities' silent
		 * execution, and CLI logging/completion hooks/legacy aliases. It does
		 * not own authorization, CLI confirmation, or response/output shaping.
		 *
		 * @since NEXT
		 * @param string $type   Requested cleanup type.
		 * @param string $source One of the SOURCE_* constants.
		 * @return array{
		 *     valid:bool,
		 *     requested_type:string,
		 *     type:string,
		 *     canonical_type:string,
		 *     result:int|WP_Error|null,
		 *     results:array<string,int|WP_Error>,
		 *     deleted:int,
		 *     failures:array<string,WP_Error>,
		 *     action_scheduler_available:bool|null
		 * }
		 */
		public static function run( string $type, string $source = self::SOURCE_ABILITY ): array {
			$canonical_type = self::canonical_type( $type, $source );
			if ( '' === $canonical_type ) {
				return self::empty_result( $type );
			}

			if ( 'all' === $canonical_type ) {
				$results  = Database_Cleanup::clean_all();
				$total    = 0;
				$failures = array();
				foreach ( $results as $cleanup_type => $result ) {
					if ( $result instanceof WP_Error ) {
						$failures[ (string) $cleanup_type ] = $result;
						continue;
					}
					$total += (int) $result;
				}

				self::log_result( $type, $total, $source, true );
				return self::completed_result( $type, $canonical_type, $total, $results, $failures );
			}

			$action_scheduler_available = null;
			if ( Database_Cleanup::ACTION_SCHEDULER_TYPE === $canonical_type ) {
				$result                     = Database_Cleanup::clean_action_scheduler();
				$action_scheduler_available = Database_Cleanup::is_action_scheduler_available();
			} else {
				$method = Database_Cleanup::CLEANUP_METHOD_MAP[ $canonical_type ] ?? '';
				if ( '' === $method ) {
					return self::empty_result( $type );
				}
				if ( 'revisions' === $canonical_type ) {
					list( $max_age, $keep_latest ) = Database_Cleanup::get_revision_defaults();
					$result                        = Database_Cleanup::invoke_cleanup_method( $method, $max_age, $keep_latest );
				} else {
					$result = Database_Cleanup::invoke_cleanup_method( $method );
				}
			}

			if ( $result instanceof WP_Error ) {
				$completed                                = self::completed_result( $type, $canonical_type, 0 );
				$completed['result']                      = $result;
				$completed['failures'][ $canonical_type ] = $result;
				$completed['action_scheduler_available']  = $action_scheduler_available;
				return $completed;
			}

			$deleted = (int) $result;
			self::log_result( $type, $deleted, $source, false, $action_scheduler_available );
			if ( self::SOURCE_CLI === $source ) {
				/**
				 * Fires after a per-type database cleanup completes.
				 *
				 * @since NEXT
				 * @param string $type  Requested cleanup type, including a legacy CLI alias.
				 * @param int    $count Number of rows deleted.
				 */
				do_action( 'wppo_database_cleanup_completed', $type, $deleted );
			}
			if ( self::SOURCE_REST === $source && $deleted > 0 && isset( Database_Cleanup::TABLE_MAP[ $canonical_type ] ) ) {
				Database_Cleanup::maybe_optimize_tables( Database_Cleanup::TABLE_MAP[ $canonical_type ], true );
			}

			$completed                               = self::completed_result( $type, $canonical_type, $deleted );
			$completed['result']                     = $deleted;
			$completed['action_scheduler_available'] = $action_scheduler_available;
			return $completed;
		}

		/**
		 * Resolve a canonical cleanup type for the requesting adapter.
		 *
		 * @since NEXT
		 * @param string $type   Requested cleanup type.
		 * @param string $source One of the SOURCE_* constants.
		 * @return string Canonical type, or an empty string when invalid.
		 */
		private static function canonical_type( string $type, string $source ): string {
			if ( self::SOURCE_CLI === $source && isset( self::LEGACY_ALIASES[ $type ] ) ) {
				return self::LEGACY_ALIASES[ $type ];
			}
			return in_array( $type, self::valid_types(), true ) ? $type : '';
		}

		/**
		 * Build the stable invalid-type result.
		 *
		 * @since NEXT
		 * @param string $type Requested cleanup type.
		 * @return array<string,mixed>
		 */
		private static function empty_result( string $type ): array {
			return array(
				'valid'                      => false,
				'requested_type'             => $type,
				'type'                       => '',
				'canonical_type'             => '',
				'result'                     => null,
				'results'                    => array(),
				'deleted'                    => 0,
				'failures'                   => array(),
				'action_scheduler_available' => null,
			);
		}

		/**
		 * Build a stable successful or partial result.
		 *
		 * @since NEXT
		 * @param string                     $type          Requested cleanup type.
		 * @param string                     $canonical_type Canonical cleanup type.
		 * @param int                        $deleted      Total rows deleted.
		 * @param array<string,int|WP_Error> $results       Per-type results.
		 * @param array<string,WP_Error>     $failures      Per-type failures.
		 * @return array<string,mixed>
		 */
		private static function completed_result( string $type, string $canonical_type, int $deleted, array $results = array(), array $failures = array() ): array {
			return array(
				'valid'                      => true,
				'requested_type'             => $type,
				'type'                       => $type,
				'canonical_type'             => $canonical_type,
				'result'                     => null,
				'results'                    => $results,
				'deleted'                    => $deleted,
				'failures'                   => $failures,
				'action_scheduler_available' => null,
			);
		}

		/**
		 * Log the activity historically emitted by the requesting adapter.
		 *
		 * @since NEXT
		 * @param string    $type                    Requested cleanup type.
		 * @param int       $deleted                 Total rows deleted.
		 * @param string    $source                  One of the SOURCE_* constants.
		 * @param bool      $all                     Whether this is the all-types branch.
		 * @param bool|null $action_scheduler_available Action Scheduler availability.
		 * @return void
		 */
		private static function log_result( string $type, int $deleted, string $source, bool $all, ?bool $action_scheduler_available = null ): void {
			if ( self::SOURCE_ABILITY === $source ) {
				return;
			}
			if ( $all ) {
				if ( self::SOURCE_CLI === $source ) {
					Log::add(
						sprintf(
							/* translators: %d: Total items removed */
							__( 'Database cleanup (all via WP-CLI): %d items removed', 'performance-optimisation' ),
							$deleted
						)
					);
					return;
				}
				Log::add(
					sprintf(
						/* translators: %d: Number of items cleaned */
						__( 'Database cleanup (all): %d items removed', 'performance-optimisation' ),
						$deleted
					)
				);
				return;
			}

			if ( self::SOURCE_REST === $source
				&& Database_Cleanup::ACTION_SCHEDULER_TYPE === $type
				&& false === $action_scheduler_available ) {
				return;
			}
			if ( self::SOURCE_CLI === $source ) {
				Log::add(
					sprintf(
						/* translators: 1: Cleanup type, 2: Number of items removed */
						__( 'Database cleanup (%1$s via WP-CLI): %2$d items removed', 'performance-optimisation' ),
						$type,
						$deleted
					)
				);
				return;
			}
			Log::add(
				sprintf(
					/* translators: %1$s: Cleanup type, %2$d: Number of items */
					__( 'Database cleanup (%1$s): %2$d items removed', 'performance-optimisation' ),
					$type,
					$deleted
				)
			);
		}
	}
}
