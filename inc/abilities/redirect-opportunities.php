<?php
/**
 * Redirect Coverage / Opportunity Auditor
 *
 * Systematically finds high-traffic legacy/residual "live music in {city}"
 * search demand whose natural platform destination (an events location archive,
 * optionally time-scoped) NOW EXISTS and returns HTTP 200, but which has no
 * redirect rule routing the templated legacy URL there.
 *
 * This is the data-first, templated generalization of the one hand-built
 * Charleston rule (redirect rule #250):
 *   /live-music-in-charleston-sc-this-week
 *     -> https://events.extrachill.com/location/usa/south-carolina/charleston/this-week
 *
 * Method:
 *   1. Pull "live music in ..." query demand from Google Search Console
 *      (datamachine/google-search-console, action=query_stats) — the real,
 *      ranked signal of which cities/scopes people search for.
 *   2. Parse each query into {city} + {scope} (tonight / this-weekend /
 *      this-week / none).
 *   3. Resolve the matching events location term and build the canonical
 *      destination URL (term link + scope segment).
 *   4. VERIFY the destination returns HTTP 200 before ever proposing it —
 *      never suggest a redirect to a non-existent archive.
 *   5. Build the templated legacy source URL (/live-music-in-{city}-{scope})
 *      and skip it if a redirect rule already exists.
 *   6. Rank surviving opportunities by real search traffic (impressions, then
 *      clicks).
 *
 * @package ExtraChill\SEO\Abilities
 * @since 0.14.0
 */

namespace ExtraChill\SEO\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Time-scope segments appended to a location archive URL, in match-priority
 * order (longest phrases first so "this weekend" wins over a bare match).
 *
 * Maps the human search phrase => the URL scope slug used by the events
 * discovery rewrite (extrachill-events EXTRACHILL_EVENTS_DISCOVERY_SCOPES).
 *
 * @return array<string, string>
 */
function ec_seo_opportunity_scopes() {
	return array(
		'this weekend' => 'this-weekend',
		'this week'    => 'this-week',
		'tonight'      => 'tonight',
		'today'        => 'today',
	);
}

/**
 * Register the redirect coverage/opportunity auditor ability.
 *
 * Called from SEO_Abilities::register_abilities().
 */
function register_redirect_opportunity_ability() {
	wp_register_ability(
		'extrachill-seo/redirect-opportunities',
		array(
			'label'               => __( 'Redirect Opportunities', 'extrachill-seo' ),
			'description'         => __( 'Find high-traffic "live music in {city}" search demand whose events location archive now exists (verified HTTP 200) but has no redirect, ranked by traffic. The templated generalization of the hand-built Charleston rule.', 'extrachill-seo' ),
			'category'            => 'extrachill-seo',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'            => array(
						'type'        => 'integer',
						'description' => __( 'Days of Google Search Console query data to analyze.', 'extrachill-seo' ),
						'default'     => 90,
					),
					'min_impressions' => array(
						'type'        => 'integer',
						'description' => __( 'Minimum GSC impressions for a city/scope to be considered.', 'extrachill-seo' ),
						'default'     => 1,
					),
					'limit'           => array(
						'type'        => 'integer',
						'description' => __( 'Maximum number of ranked opportunities to return.', 'extrachill-seo' ),
						'default'     => 100,
					),
					'verify'          => array(
						'type'        => 'boolean',
						'description' => __( 'Verify each suggested destination returns HTTP 200 before proposing it. Disable only for fast previews.', 'extrachill-seo' ),
						'default'     => true,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'analyzed'      => array( 'type' => 'integer' ),
					'opportunities' => array( 'type' => 'array' ),
					'skipped'       => array( 'type' => 'object' ),
					'message'       => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\\execute_redirect_opportunities',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Execute the redirect opportunity auditor.
 *
 * @param array $input Ability input.
 * @return array Ranked opportunities with skip diagnostics.
 */
function execute_redirect_opportunities( $input ) {
	$days            = max( 1, (int) ( $input['days'] ?? 90 ) );
	$min_impressions = max( 0, (int) ( $input['min_impressions'] ?? 1 ) );
	$limit           = max( 1, (int) ( $input['limit'] ?? 100 ) );
	$verify          = (bool) ( $input['verify'] ?? true );

	if ( ! function_exists( 'wp_get_ability' ) ) {
		return array(
			'success'       => false,
			'analyzed'      => 0,
			'opportunities' => array(),
			'skipped'       => array(),
			'message'       => 'Abilities API unavailable.',
		);
	}

	// 1. Pull "live music in ..." query demand from Google Search Console.
	$candidates = ec_seo_fetch_live_music_query_demand( $days );

	if ( empty( $candidates ) ) {
		return array(
			'success'       => false,
			'analyzed'      => 0,
			'opportunities' => array(),
			'skipped'       => array(),
			'message'       => 'No "live music in {city}" search demand found (GSC unavailable or no matching queries).',
		);
	}

	$skipped = array(
		'below_min_impressions' => 0,
		'no_city_parsed'        => 0,
		'no_location_term'      => 0,
		'destination_not_200'   => 0,
		'rule_exists'           => 0,
	);

	// 2-6. Aggregate by city+scope, resolve destination, verify, dedupe, rank.
	$by_target = array();

	foreach ( $candidates as $candidate ) {
		$parsed = ec_seo_parse_live_music_query( $candidate['query'] );
		if ( empty( $parsed['city'] ) ) {
			++$skipped['no_city_parsed'];
			continue;
		}

		// Aggregate demand per distinct city+scope target (many raw queries
		// collapse to the same destination, e.g. "...akron"/"...akron ohio").
		// Key on the sanitized city slug so phrasing variants merge.
		$city_slug = sanitize_title( $parsed['city'] );
		$key       = $city_slug . '|' . $parsed['scope'];

		if ( ! isset( $by_target[ $key ] ) ) {
			$by_target[ $key ] = array(
				'city'        => $parsed['city'],
				'scope'       => $parsed['scope'],
				'state'       => $parsed['state'],
				'impressions' => 0,
				'clicks'      => 0,
				'queries'     => array(),
			);
		}

		// Prefer a state hint when any contributing query carried one.
		if ( '' === $by_target[ $key ]['state'] && '' !== $parsed['state'] ) {
			$by_target[ $key ]['state'] = $parsed['state'];
		}

		$by_target[ $key ]['impressions'] += (int) $candidate['impressions'];
		$by_target[ $key ]['clicks']      += (int) $candidate['clicks'];
		$by_target[ $key ]['queries'][]    = $candidate['query'];
	}

	$opportunities = array();

	foreach ( $by_target as $target ) {
		if ( $target['impressions'] < $min_impressions ) {
			++$skipped['below_min_impressions'];
			continue;
		}

		// 3. Resolve the events location archive destination for this city.
		$destination = ec_seo_resolve_location_destination( $target['city'], $target['scope'], $target['state'] );

		if ( empty( $destination['url'] ) ) {
			++$skipped['no_location_term'];
			continue;
		}

		// 4. Verify the destination returns HTTP 200 before proposing it.
		if ( $verify && ! ec_seo_destination_returns_200( $destination['url'] ) ) {
			++$skipped['destination_not_200'];
			continue;
		}

		// 5. Build the templated legacy source URL and skip if a rule exists.
		$legacy_url = ec_seo_build_legacy_url(
			$destination['city_slug'],
			$destination['state_abbr'],
			$target['scope']
		);

		$existing = \ExtraChill\SEO\Core\extrachill_seo_get_redirect_by_url( $legacy_url );
		if ( $existing ) {
			++$skipped['rule_exists'];
			continue;
		}

		$opportunities[] = array(
			'legacy_url'  => $legacy_url,
			'destination' => $destination['url'],
			'verified'    => $verify,
			'impressions' => (int) $target['impressions'],
			'clicks'      => (int) $target['clicks'],
			'scope'       => $target['scope'],
			'city'        => $destination['city_name'],
			'queries'     => array_values( array_unique( $target['queries'] ) ),
		);
	}

	// 6. Rank by real search traffic (impressions, then clicks).
	usort(
		$opportunities,
		function ( $a, $b ) {
			if ( $a['impressions'] === $b['impressions'] ) {
				return $b['clicks'] <=> $a['clicks'];
			}
			return $b['impressions'] <=> $a['impressions'];
		}
	);

	$opportunities = array_slice( $opportunities, 0, $limit );

	return array(
		'success'       => true,
		'analyzed'      => count( $candidates ),
		'opportunities' => $opportunities,
		'skipped'       => $skipped,
		'message'       => sprintf(
			'%d opportunit%s found from %d analyzed queries.',
			count( $opportunities ),
			1 === count( $opportunities ) ? 'y' : 'ies',
			count( $candidates )
		),
	);
}

/**
 * Fetch "live music in ..." query demand from Google Search Console.
 *
 * Uses the datamachine/google-search-console ability (query_stats action,
 * query_filter="live music in") to pull the real, ranked search demand for
 * city-scoped live-music queries on the main site.
 *
 * @param int $days Number of days of GSC data to pull.
 * @return array<int, array{query:string, impressions:int, clicks:int}>
 */
function ec_seo_fetch_live_music_query_demand( $days ) {
	$gsc = wp_get_ability( 'datamachine/google-search-console' );
	if ( ! $gsc ) {
		return array();
	}

	$result = $gsc->execute(
		array(
			'action'       => 'query_stats',
			'query_filter' => 'live music in',
			'start_date'   => gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ),
			'end_date'     => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
			'limit'        => 1000,
		)
	);

	if ( empty( $result['success'] ) || empty( $result['results'] ) ) {
		return array();
	}

	$rows = array();
	foreach ( $result['results'] as $row ) {
		$query = is_array( $row['keys'] ?? null ) ? ( $row['keys'][0] ?? '' ) : ( $row['keys'] ?? '' );
		$query = strtolower( trim( (string) $query ) );
		if ( '' === $query ) {
			continue;
		}
		$rows[] = array(
			'query'       => $query,
			'impressions' => (int) ( $row['impressions'] ?? 0 ),
			'clicks'      => (int) ( $row['clicks'] ?? 0 ),
		);
	}

	return $rows;
}

/**
 * Parse a "live music in {city} {scope}" search query into city + scope.
 *
 * Strips leading qualifiers ("free", "best"), the "live music in" prefix,
 * a trailing time scope phrase, and trailing state words/abbreviations so the
 * remainder is a clean city phrase for term lookup.
 *
 * @param string $query Lowercased search query.
 * @return array{city:string, scope:string, state:string} City phrase (e.g.
 *         "little rock"), scope slug ('' when unscoped), and a state-abbr hint
 *         ('' when the query carried no state).
 */
function ec_seo_parse_live_music_query( $query ) {
	$empty = array(
		'city'  => '',
		'scope' => '',
		'state' => '',
	);

	$query = trim( preg_replace( '/\s+/', ' ', strtolower( $query ) ) );

	// Must contain the "live music in" intent anchor.
	$pos = strpos( $query, 'live music in ' );
	if ( false === $pos ) {
		return $empty;
	}

	$remainder = trim( substr( $query, $pos + strlen( 'live music in ' ) ) );
	if ( '' === $remainder ) {
		return $empty;
	}

	// Extract a trailing time scope, if present.
	$scope = '';
	foreach ( ec_seo_opportunity_scopes() as $phrase => $slug ) {
		if ( preg_match( '/\b' . preg_quote( $phrase, '/' ) . '\b\s*$/', $remainder ) ) {
			$scope     = $slug;
			$remainder = trim( preg_replace( '/\b' . preg_quote( $phrase, '/' ) . '\b\s*$/', '', $remainder ) );
			break;
		}
	}

	// Drop trailing filler words ("free"/"shows"/"near me"/"events" etc.) that
	// sometimes sit between city and scope, and any leftover punctuation.
	$remainder = trim( $remainder, " \t\n\r\0\x0B\"'." );
	$remainder = preg_replace( '/\b(free|shows?|events?|concerts?|near me|today)\b\s*$/', '', $remainder );
	$remainder = trim( $remainder );

	if ( '' === $remainder ) {
		return $empty;
	}

	// Strip a trailing state name or two-letter abbreviation ("akron ohio",
	// "raleigh nc") so the remainder is a clean city phrase; keep the state as
	// a hint for the correct destination + legacy-URL abbreviation.
	$state = '';
	$abbrs = ec_seo_state_abbreviations();

	// Trailing full state name (e.g. "north carolina"). Longest names first so
	// multi-word states match before a bare last word.
	$state_names = array_keys( $abbrs );
	usort(
		$state_names,
		function ( $a, $b ) {
			return strlen( $b ) <=> strlen( $a );
		}
	);
	foreach ( $state_names as $state_slug ) {
		$name = str_replace( '-', ' ', $state_slug );
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '$/', $remainder ) ) {
			$state     = $abbrs[ $state_slug ];
			$remainder = trim( preg_replace( '/\s' . preg_quote( $name, '/' ) . '$/', '', $remainder ) );
			break;
		}
	}

	// Trailing two-letter abbreviation (e.g. "nc"), only when it is a real
	// state code and something precedes it as the city.
	if ( '' === $state && preg_match( '/^(.+?)\s([a-z]{2})$/', $remainder, $m ) ) {
		if ( in_array( $m[2], $abbrs, true ) ) {
			$state     = $m[2];
			$remainder = trim( $m[1] );
		}
	}

	if ( '' === $remainder ) {
		return $empty;
	}

	return array(
		'city'  => $remainder,
		'scope' => $scope,
		'state' => $state,
	);
}

/**
 * Resolve the events location archive destination for a parsed city phrase.
 *
 * Looks up the matching `location` term on the events site, builds its
 * canonical archive URL, optionally appends the time-scope segment, and
 * derives the state abbreviation from the term's parent (used to build the
 * legacy source URL, mirroring the Charleston "-sc-" pattern).
 *
 * @param string $city_phrase City phrase (e.g. "little rock").
 * @param string $scope       Scope slug ('' for the unscoped archive).
 * @param string $state_hint  Optional two-letter state abbr parsed from the
 *                            query, used when the term ancestry yields none.
 * @return array{url:string, city_slug:string, city_name:string, state_abbr:string}
 */
function ec_seo_resolve_location_destination( $city_phrase, $scope, $state_hint = '' ) {
	$empty = array(
		'url'        => '',
		'city_slug'  => '',
		'city_name'  => '',
		'state_abbr' => '',
	);

	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 7;
	if ( ! $events_blog_id ) {
		return $empty;
	}

	$city_slug = sanitize_title( $city_phrase );
	if ( '' === $city_slug ) {
		return $empty;
	}

	$switched = false;
	if ( function_exists( 'switch_to_blog' ) && get_current_blog_id() !== $events_blog_id ) {
		switch_to_blog( $events_blog_id );
		$switched = true;
	}

	$result = $empty;

	$term = get_term_by( 'slug', $city_slug, 'location' );

	if ( $term && ! is_wp_error( $term ) ) {
		$term_link = get_term_link( $term );

		if ( ! is_wp_error( $term_link ) ) {
			$url = $scope ? trailingslashit( $term_link ) . $scope : $term_link;

			// Prefer the state from the term's ancestor chain (authoritative);
			// fall back to the state parsed from the query.
			$state_abbr = ec_seo_state_abbr_for_term( $term );
			if ( '' === $state_abbr ) {
				$state_abbr = $state_hint;
			}

			$result = array(
				'url'        => $url,
				'city_slug'  => $term->slug,
				'city_name'  => $term->name,
				'state_abbr' => $state_abbr,
			);
		}
	}

	if ( $switched ) {
		restore_current_blog();
	}

	return $result;
}

/**
 * Derive a US state abbreviation for a location term from its ancestor chain.
 *
 * The events location hierarchy is usa > {state} > {city}; the immediate
 * parent (or nearest ancestor that maps to a known state) yields the abbr.
 *
 * @param \WP_Term $term Location term.
 * @return string Two-letter state abbreviation (lowercase) or empty string.
 */
function ec_seo_state_abbr_for_term( $term ) {
	if ( ! ( $term instanceof \WP_Term ) ) {
		return '';
	}

	$map = ec_seo_state_abbreviations();

	$ancestors = get_ancestors( $term->term_id, 'location', 'taxonomy' );
	foreach ( $ancestors as $ancestor_id ) {
		$ancestor = get_term( $ancestor_id, 'location' );
		if ( $ancestor && ! is_wp_error( $ancestor ) ) {
			$slug = strtolower( $ancestor->slug );
			if ( isset( $map[ $slug ] ) ) {
				return $map[ $slug ];
			}
		}
	}

	return '';
}

/**
 * Build the templated legacy source URL for a city/scope opportunity.
 *
 * Mirrors the hand-built Charleston rule format
 * (/live-music-in-charleston-sc-this-week): city slug, optional state abbr,
 * optional scope.
 *
 * @param string $city_slug  Sanitized city slug (e.g. "little-rock").
 * @param string $state_abbr Two-letter state abbr (lowercase), or empty.
 * @param string $scope      Scope slug ('' for unscoped).
 * @return string Leading-slash legacy URL (no trailing slash).
 */
function ec_seo_build_legacy_url( $city_slug, $state_abbr, $scope ) {
	$parts = array( 'live-music-in', $city_slug );

	if ( '' !== $state_abbr ) {
		$parts[] = $state_abbr;
	}

	if ( '' !== $scope ) {
		$parts[] = $scope;
	}

	return '/' . implode( '-', $parts );
}

/**
 * Verify that a suggested destination URL returns HTTP 200.
 *
 * Follows no redirects so a working archive is confirmed directly (never a
 * redirect chain that masks a real 404). A GET is used (not HEAD) because the
 * events location/discovery routes are rewrite-driven and some edge configs
 * answer HEAD differently than a crawler's GET.
 *
 * @param string $url Absolute destination URL.
 * @return bool True only on a direct 200.
 */
function ec_seo_destination_returns_200( $url ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 15,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array( 'Accept' => 'text/html' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return false;
	}

	return 200 === (int) wp_remote_retrieve_response_code( $response );
}

/**
 * US state-name slug => two-letter abbreviation map.
 *
 * Keys match the events location taxonomy state-term slugs.
 *
 * @return array<string, string>
 */
function ec_seo_state_abbreviations() {
	return array(
		'alabama'              => 'al',
		'alaska'               => 'ak',
		'arizona'              => 'az',
		'arkansas'             => 'ar',
		'california'           => 'ca',
		'colorado'             => 'co',
		'connecticut'          => 'ct',
		'delaware'             => 'de',
		'florida'              => 'fl',
		'georgia'              => 'ga',
		'hawaii'               => 'hi',
		'idaho'                => 'id',
		'illinois'             => 'il',
		'indiana'              => 'in',
		'iowa'                 => 'ia',
		'kansas'               => 'ks',
		'kentucky'             => 'ky',
		'louisiana'            => 'la',
		'maine'                => 'me',
		'maryland'             => 'md',
		'massachusetts'        => 'ma',
		'michigan'             => 'mi',
		'minnesota'            => 'mn',
		'mississippi'          => 'ms',
		'missouri'             => 'mo',
		'montana'              => 'mt',
		'nebraska'             => 'ne',
		'nevada'               => 'nv',
		'new-hampshire'        => 'nh',
		'new-jersey'           => 'nj',
		'new-mexico'           => 'nm',
		'new-york'             => 'ny',
		'north-carolina'       => 'nc',
		'north-dakota'         => 'nd',
		'ohio'                 => 'oh',
		'oklahoma'             => 'ok',
		'oregon'               => 'or',
		'pennsylvania'         => 'pa',
		'rhode-island'         => 'ri',
		'south-carolina'       => 'sc',
		'south-dakota'         => 'sd',
		'tennessee'            => 'tn',
		'texas'                => 'tx',
		'utah'                 => 'ut',
		'vermont'              => 'vt',
		'virginia'             => 'va',
		'washington'           => 'wa',
		'west-virginia'        => 'wv',
		'wisconsin'            => 'wi',
		'wyoming'              => 'wy',
		'district-of-columbia' => 'dc',
	);
}
