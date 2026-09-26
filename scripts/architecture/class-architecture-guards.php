<?php
/**
 * Deterministic architecture guardrails for the generated inventory and graph.
 *
 * Development tooling only; WordPress never loads this file.
 *
 * @package PerformanceOptimise\Architecture
 * @since   NEXT
 */

namespace PerformanceOptimise\Architecture;

/**
 * Check generated architecture evidence for new unexplained ownership gaps.
 *
 * Existing boundary violations and static-state owners are deliberately
 * classified rather than hidden. A new owner, schedule API caller, or boundary
 * edge must be added with an explicit classification before the guard passes.
 *
 * @since NEXT
 */
final class Architecture_Guards {

	/**
	 * Executable scheduling APIs that must have an explicit owner.
	 *
	 * @since NEXT
	 * @var array<string,bool>
	 */
	private const SCHEDULE_FUNCTIONS = array(
		'as_enqueue_async_action'      => true,
		'as_schedule_recurring_action' => true,
		'as_schedule_single_action'    => true,
		'wp_schedule_event'            => true,
		'wp_schedule_single_event'     => true,
	);

	/**
	 * Layer vocabulary for direct schedule owners.
	 *
	 * @since NEXT
	 * @var array<string,bool>
	 */
	private const SCHEDULE_OWNER_LAYERS = array(
		'Domain'         => true,
		'Infrastructure' => true,
		'Lifecycle'      => true,
	);

	/**
	 * State vocabulary copied from the P3-021 lifecycle audit.
	 *
	 * @since NEXT
	 * @var array<string,bool>
	 */
	private const STATIC_STATE_CLASSIFICATIONS = array(
		'site_sensitive_request_memo' => true,
		'request_state'               => true,
		'request_residue'             => true,
		'cross_request_persisted'     => true,
		'protected_compatibility'     => true,
	);

	/**
	 * Bridge classification vocabulary copied from BOUNDARIES.md.
	 *
	 * @since NEXT
	 * @var array<string,bool>
	 */
	private const BOUNDARY_CLASSIFICATIONS = array(
		'required_compatibility' => true,
		'required_lifecycle'     => true,
		'temporary_migration'    => true,
		'protected'              => true,
		'cycle_producing'        => true,
	);

	/**
	 * Known direct schedule callers, keyed by node ID.
	 *
	 * The function list is exact by owner. This catches a new scheduling API in
	 * an already classified owner as well as a new owner itself.
	 *
	 * @since NEXT
	 * @var array<string,array{layer:string,functions:array<int,string>}>
	 */
	private const SCHEDULE_OWNERS = array(
		'PerformanceOptimise\\Inc\\Activate'              => array(
			'layer'     => 'Lifecycle',
			'functions' => array( 'wp_schedule_single_event' ),
		),
		'PerformanceOptimise\\Inc\\Ai_Anomaly'            => array(
			'layer'     => 'Domain',
			'functions' => array( 'as_enqueue_async_action' ),
		),
		'PerformanceOptimise\\Inc\\Builder_Purge_Watcher' => array(
			'layer'     => 'Domain',
			'functions' => array( 'wp_schedule_single_event' ),
		),
		'PerformanceOptimise\\Inc\\Cache_Invalidator'     => array(
			'layer'     => 'Domain',
			'functions' => array( 'wp_schedule_single_event' ),
		),
		'PerformanceOptimise\\Inc\\Ccss_Generator'        => array(
			'layer'     => 'Domain',
			'functions' => array( 'as_enqueue_async_action', 'as_schedule_single_action', 'wp_schedule_single_event' ),
		),
		'PerformanceOptimise\\Inc\\Critical_CSS'          => array(
			'layer'     => 'Domain',
			'functions' => array( 'wp_schedule_single_event' ),
		),
		'PerformanceOptimise\\Inc\\Google_Fonts'          => array(
			'layer'     => 'Domain',
			'functions' => array( 'wp_schedule_single_event' ),
		),
		'PerformanceOptimise\\Inc\\LiteSpeed_Crawler'     => array(
			'layer'     => 'Domain',
			'functions' => array( 'wp_schedule_single_event' ),
		),
		'PerformanceOptimise\\Inc\\Object_Cache'          => array(
			'layer'     => 'Domain',
			'functions' => array( 'wp_schedule_event' ),
		),
		'PerformanceOptimise\\Inc\\RUM'                   => array(
			'layer'     => 'Domain',
			'functions' => array( 'wp_schedule_single_event' ),
		),
		'PerformanceOptimise\\Inc\\Scheduler'             => array(
			'layer'     => 'Infrastructure',
			'functions' => array( 'as_enqueue_async_action', 'as_schedule_single_action', 'wp_schedule_event', 'wp_schedule_single_event' ),
		),
	);

	/**
	 * Classified static-state owners from the current generated inventory.
	 *
	 * @since NEXT
	 * @var array<string,string>
	 */
	private const STATIC_STATE_OWNERS = array(
		'PerformanceOptimise\\Inc\\AI_Adaptive'           => 'site_sensitive_request_memo',
		'PerformanceOptimise\\Inc\\Admin_Notices'         => 'request_residue',
		'PerformanceOptimise\\Inc\\Ai_Anomaly'            => 'site_sensitive_request_memo',
		'PerformanceOptimise\\Inc\\Asset_Manager'         => 'protected_compatibility',
		'PerformanceOptimise\\Inc\\Bfcache'               => 'request_state',
		'PerformanceOptimise\\Inc\\Builder_Purge_Watcher' => 'request_residue',
		'PerformanceOptimise\\Inc\\CDN'                   => 'cross_request_persisted',
		'PerformanceOptimise\\Inc\\Cache'                 => 'cross_request_persisted',
		'PerformanceOptimise\\Inc\\Cache_Capacity'        => 'cross_request_persisted',
		'PerformanceOptimise\\Inc\\Ccss_Generator'        => 'request_residue',
		'PerformanceOptimise\\Inc\\Ccss_Store'            => 'cross_request_persisted',
		'PerformanceOptimise\\Inc\\Critical_CSS'          => 'request_residue',
		'PerformanceOptimise\\Inc\\Database_Cleanup'      => 'site_sensitive_request_memo',
		'PerformanceOptimise\\Inc\\Filesystem'            => 'cross_request_persisted',
		'PerformanceOptimise\\Inc\\Image_Optimisation'    => 'request_residue',
		'PerformanceOptimise\\Inc\\Img_Converter'         => 'cross_request_persisted',
		'PerformanceOptimise\\Inc\\Lcp_Preload'           => 'request_residue',
		'PerformanceOptimise\\Inc\\LiteSpeed_ESI'         => 'protected_compatibility',
		'PerformanceOptimise\\Inc\\LiteSpeed_Integration' => 'site_sensitive_request_memo',
		'PerformanceOptimise\\Inc\\Log'                   => 'cross_request_persisted',
		'PerformanceOptimise\\Inc\\Main'                  => 'request_residue',
		'PerformanceOptimise\\Inc\\OD_Bridge'             => 'request_residue',
		'PerformanceOptimise\\Inc\\Object_Cache'          => 'site_sensitive_request_memo',
		'PerformanceOptimise\\Inc\\RUM'                   => 'site_sensitive_request_memo',
		'PerformanceOptimise\\Inc\\Sandbox_Preview'       => 'request_residue',
		'PerformanceOptimise\\Inc\\Scheduler'             => 'cross_request_persisted',
		'PerformanceOptimise\\Inc\\Script_Strategy'       => 'request_residue',
		'PerformanceOptimise\\Inc\\Settings_Store'        => 'cross_request_persisted',
		'PerformanceOptimise\\Inc\\System_Info'           => 'site_sensitive_request_memo',
		'PerformanceOptimise\\Inc\\Url'                   => 'cross_request_persisted',
		'PerformanceOptimise\\Inc\\Used_CSS'              => 'cross_request_persisted',
		'PerformanceOptimise\\Inc\\Util'                  => 'request_residue',
		'PerformanceOptimise\\Inc\\Wp_Version'            => 'request_state',
	);

	/**
	 * Current boundary findings and their explicit architecture explanation.
	 *
	 * New findings fail until a queue item and one of these classifications are
	 * added. Existing findings remain visible in the generated graph.
	 *
	 * @since NEXT
	 * @var array<string,string>
	 */
	private const BOUNDARY_EXCEPTIONS = array(
		'PerformanceOptimise\\Inc\\Abilities|PerformanceOptimise\\Inc\\Main|presentation_depends_on_orchestrator' => 'temporary_migration',
		'PerformanceOptimise\\Inc\\Asset_Manager|PerformanceOptimise\\Inc\\Main|domain_depends_on_presentation_or_orchestrator' => 'temporary_migration',
		'PerformanceOptimise\\Inc\\Builder_Purge_Watcher|PerformanceOptimise\\Inc\\Main|domain_depends_on_presentation_or_orchestrator' => 'required_lifecycle',
		'PerformanceOptimise\\Inc\\Cache|PerformanceOptimise\\Inc\\Main|domain_depends_on_presentation_or_orchestrator' => 'temporary_migration',
		'PerformanceOptimise\\Inc\\Critical_CSS|PerformanceOptimise\\Inc\\Main|domain_depends_on_presentation_or_orchestrator' => 'temporary_migration',
		'PerformanceOptimise\\Inc\\Css_Combine|PerformanceOptimise\\Inc\\Main|domain_depends_on_presentation_or_orchestrator' => 'temporary_migration',
		'PerformanceOptimise\\Inc\\LiteSpeed_Integration|PerformanceOptimise\\Inc\\Main|domain_depends_on_presentation_or_orchestrator' => 'required_compatibility',
		'PerformanceOptimise\\Inc\\Metabox|PerformanceOptimise\\Inc\\Main|presentation_depends_on_orchestrator' => 'temporary_migration',
		'PerformanceOptimise\\Inc\\Minify\\CSS|PerformanceOptimise\\Inc\\Img_Converter|protected_adapter_depends_on_feature' => 'protected',
		'PerformanceOptimise\\Inc\\Minify\\HTML|PerformanceOptimise\\Inc\\Main|protected_adapter_depends_on_feature' => 'protected',
		'PerformanceOptimise\\Inc\\Minify\\HTML|PerformanceOptimise\\Inc\\Sandbox_Preview|protected_adapter_depends_on_feature' => 'protected',
		'PerformanceOptimise\\Inc\\Minify\\Minify_Policy|PerformanceOptimise\\Inc\\Cache|protected_adapter_depends_on_feature' => 'protected',
		'PerformanceOptimise\\Inc\\Minify\\Minify_Policy|PerformanceOptimise\\Inc\\LiteSpeed_Integration|protected_adapter_depends_on_feature' => 'protected',
		'PerformanceOptimise\\Inc\\Minify\\Minify_Policy|PerformanceOptimise\\Inc\\Main|protected_adapter_depends_on_feature' => 'protected',
		'PerformanceOptimise\\Inc\\Rest|PerformanceOptimise\\Inc\\Main|presentation_depends_on_orchestrator' => 'temporary_migration',
		'PerformanceOptimise\\Inc\\Script_Strategy|PerformanceOptimise\\Inc\\Main|domain_depends_on_presentation_or_orchestrator' => 'temporary_migration',
		'PerformanceOptimise\\Inc\\Script_Strategy|PerformanceOptimise\\Inc\\Preload_Buffer_Coordinator|domain_depends_on_presentation_or_orchestrator' => 'required_lifecycle',
		'PerformanceOptimise\\Inc\\Used_CSS|PerformanceOptimise\\Inc\\Main|domain_depends_on_presentation_or_orchestrator' => 'temporary_migration',
		'PerformanceOptimise\\Inc\\Util|PerformanceOptimise\\Inc\\CDN|shared_compatibility_depends_on_feature' => 'required_compatibility',
		'PerformanceOptimise\\Inc\\Util|PerformanceOptimise\\Inc\\Woo_Detect|shared_compatibility_depends_on_feature' => 'required_compatibility',
	);

	/**
	 * Evaluate all architecture guards against generated evidence.
	 *
	 * @since NEXT
	 * @param array<int,array<string,mixed>> $inventory Generated class inventory.
	 * @param array<string,mixed>            $graph     Generated dependency graph.
	 * @return array{status:string,summary:array<string,array<string,int>>,violations:array<int,array<string,mixed>>}
	 */
	public function evaluate( array $inventory, array $graph ): array {
		$boundary   = $this->boundary_violations( $graph );
		$schedule   = $this->schedule_violations( $graph );
		$static     = $this->static_state_violations( $inventory, $graph );
		$violations = array_merge( $boundary, $schedule, $static );
		usort(
			$violations,
			static function ( array $left, array $right ): int {
				$by_guard = strcmp( $left['guard'], $right['guard'] );
				$by_code  = strcmp( $left['code'], $right['code'] );
				return 0 !== $by_guard ? $by_guard : ( 0 !== $by_code ? $by_code : strcmp( $left['subject'], $right['subject'] ) );
			}
		);

		return array(
			'status'     => empty( $violations ) ? 'pass' : 'fail',
			'summary'    => array(
				'boundary_edges'      => array(
					'checked'    => count( (array) ( $graph['metrics']['boundary_violations'] ?? array() ) ),
					'violations' => count( $boundary ),
				),
				'schedule_owners'     => array(
					'checked'    => count( $this->schedule_evidence( $graph ) ),
					'violations' => count( $schedule ),
				),
				'static_state_owners' => array(
					'checked'    => count( $this->static_state_evidence( $inventory, $graph ) ),
					'violations' => count( $static ),
				),
			),
			'violations' => $violations,
		);
	}

	/**
	 * Return violations for new boundary-direction findings.
	 *
	 * @since NEXT
	 * @param array<string,mixed> $graph Generated dependency graph.
	 * @return array<int,array<string,mixed>>
	 */
	public function boundary_violations( array $graph ): array {
		$violations = array();
		foreach ( (array) ( $graph['metrics']['boundary_violations'] ?? array() ) as $edge ) {
			$key            = $this->boundary_key( $edge );
			$classification = self::BOUNDARY_EXCEPTIONS[ $key ] ?? null;
			$subject        = (string) $edge['from'] . ' -> ' . (string) $edge['to'];
			if ( null === $classification ) {
				$violations[] = array(
					'guard'   => 'boundary_edges',
					'code'    => 'forbidden_boundary_edge',
					'subject' => $subject,
					'reason'  => (string) ( $edge['reason'] ?? 'unknown' ),
				);
				continue;
			}
			if ( ! isset( self::BOUNDARY_CLASSIFICATIONS[ $classification ] ) ) {
				$violations[] = array(
					'guard'   => 'boundary_edges',
					'code'    => 'invalid_boundary_classification',
					'subject' => $subject,
					'reason'  => $classification,
				);
			}
		}
		return $violations;
	}

	/**
	 * Return violations for schedule calls without a classified owner.
	 *
	 * @since NEXT
	 * @param array<string,mixed> $graph Generated dependency graph.
	 * @return array<int,array<string,mixed>>
	 */
	public function schedule_violations( array $graph ): array {
		$violations = array();
		foreach ( $this->schedule_evidence( $graph ) as $node_id => $functions ) {
			$owner = self::SCHEDULE_OWNERS[ $node_id ] ?? null;
			if ( null === $owner ) {
				$violations[] = array(
					'guard'   => 'schedule_owners',
					'code'    => 'unowned_schedule',
					'subject' => $node_id,
					'reason'  => implode( ',', $functions ),
				);
				continue;
			}
			if ( ! isset( self::SCHEDULE_OWNER_LAYERS[ $owner['layer'] ] ) ) {
				$violations[] = array(
					'guard'   => 'schedule_owners',
					'code'    => 'invalid_schedule_owner_classification',
					'subject' => $node_id,
					'reason'  => $owner['layer'],
				);
			}
			$expected = array_values( array_unique( array_map( 'strtolower', $owner['functions'] ) ) );
			sort( $expected, SORT_STRING );
			foreach ( array_diff( $functions, $expected ) as $function ) {
				$violations[] = array(
					'guard'   => 'schedule_owners',
					'code'    => 'unexplained_schedule_api',
					'subject' => $node_id . '::' . $function,
					'reason'  => 'Add the API to the classified owner only with a queue explanation.',
				);
			}
		}
		return $violations;
	}

	/**
	 * Return violations for static owners missing a lifecycle classification.
	 *
	 * @since NEXT
	 * @param array<int,array<string,mixed>> $inventory Generated class inventory.
	 * @param array<string,mixed>            $graph     Generated dependency graph.
	 * @return array<int,array<string,mixed>>
	 */
	public function static_state_violations( array $inventory, array $graph ): array {
		$violations = array();
		foreach ( $this->static_state_evidence( $inventory, $graph ) as $subject => $metadata ) {
			$classification = self::STATIC_STATE_OWNERS[ $subject ] ?? null;
			if ( null === $classification ) {
				$violations[] = array(
					'guard'   => 'static_state_owners',
					'code'    => 'unexplained_static_state_owner',
					'subject' => $subject,
					'reason'  => 'Static state requires an explicit lifecycle classification.',
				);
				continue;
			}
			if ( ! isset( self::STATIC_STATE_CLASSIFICATIONS[ $classification ] ) ) {
				$violations[] = array(
					'guard'   => 'static_state_owners',
					'code'    => 'invalid_static_state_classification',
					'subject' => $subject,
					'reason'  => $classification,
				);
			}
		}
		return $violations;
	}

	/**
	 * Collect executable schedule functions by graph node.
	 *
	 * @since NEXT
	 * @param array<string,mixed> $graph Generated dependency graph.
	 * @return array<string,array<int,string>>
	 */
	private function schedule_evidence( array $graph ): array {
		$evidence = array();
		foreach ( (array) ( $graph['nodes'] ?? array() ) as $node_id => $node ) {
			$functions = array();
			foreach ( array( 'external_functions', 'wordpress_functions' ) as $category ) {
				foreach ( array_keys( (array) ( $node['references'][ $category ] ?? array() ) ) as $function ) {
					$function = strtolower( ltrim( (string) $function, '\\' ) );
					if ( isset( self::SCHEDULE_FUNCTIONS[ $function ] ) ) {
						$functions[] = $function;
					}
				}
			}
			$functions = array_values( array_unique( $functions ) );
			sort( $functions, SORT_STRING );
			if ( ! empty( $functions ) ) {
				$evidence[ (string) $node_id ] = $functions;
			}
		}
		ksort( $evidence, SORT_STRING );
		return $evidence;
	}

	/**
	 * Collect static owners from both generated artifacts.
	 *
	 * @since NEXT
	 * @param array<int,array<string,mixed>> $inventory Generated class inventory.
	 * @param array<string,mixed>            $graph     Generated dependency graph.
	 * @return array<string,array{file:string,source:string}>
	 */
	private function static_state_evidence( array $inventory, array $graph ): array {
		$evidence = array();
		foreach ( $inventory as $entry ) {
			if ( ! empty( $entry['static_state'] ) ) {
				$subject              = (string) ( $entry['fqcn'] ?? ( $entry['file'] ?? 'unknown' ) );
				$evidence[ $subject ] = array(
					'file'   => (string) ( $entry['file'] ?? '' ),
					'source' => 'inventory',
				);
			}
		}
		foreach ( (array) ( $graph['nodes'] ?? array() ) as $node_id => $node ) {
			if ( ! empty( $node['static_state'] ) ) {
				$subject              = (string) ( $node['fqcn'] ?? $node_id );
				$evidence[ $subject ] = array(
					'file'   => (string) ( $node['file'] ?? '' ),
					'source' => 'graph',
				);
			}
		}
		ksort( $evidence, SORT_STRING );
		return $evidence;
	}

	/**
	 * Build a stable boundary finding key.
	 *
	 * @since NEXT
	 * @param array<string,mixed> $edge Boundary violation edge.
	 * @return string
	 */
	private function boundary_key( array $edge ): string {
		return implode(
			'|',
			array(
				(string) ( $edge['from'] ?? '' ),
				(string) ( $edge['to'] ?? '' ),
				(string) ( $edge['reason'] ?? '' ),
			)
		);
	}
}
