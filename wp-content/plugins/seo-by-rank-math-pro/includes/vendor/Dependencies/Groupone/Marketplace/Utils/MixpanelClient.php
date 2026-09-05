<?php
namespace RankMathPro\Dependencies\Groupone\Marketplace\Utils;

/**
 * Simple Mixpanel PHP client for tracking events via HTTP API.
 */
class MixpanelClient {

	/** @var string Mixpanel project token. */
	private $token;

	/** @var string Mixpanel API endpoint. */
	private $endpoint = 'https://api-eu.mixpanel.com/track';

	/**
	 * @param string $token Mixpanel project token.
	 */
	public function __construct( string $token ) {
		$this->token = $token;
	}

	/**
	 * Track an event.
	 *
	 * @param string $event      Event name.
	 * @param array  $properties Event properties.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function track( string $event, array $properties = [] ) {
		return $this->track_batch( [ [ 'event' => $event, 'properties' => $properties ] ] );
	}

	/**
	 * Track several events in a single request.
	 *
	 * Mixpanel's /track endpoint accepts an array, so a request that produced
	 * multiple events costs one round trip instead of one per event.
	 *
	 * @param array<int,array{event:string,properties:array}> $events Events to send.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function track_batch( array $events ) {
		if ( empty( $this->token ) ) {
			return new \WP_Error( 'missing_token', 'Mixpanel token is missing.' );
		}

		if ( empty( $events ) ) {
			return true;
		}

		$payload = [];

		foreach ( $events as $entry ) {
			if ( empty( $entry['event'] ) ) {
				continue;
			}

			$properties          = isset( $entry['properties'] ) && is_array( $entry['properties'] ) ? $entry['properties'] : [];
			$properties['token'] = $this->token;

			// Do NOT send a 'time' property — let Mixpanel use its own ingestion
			// time.  The server clock may be out-of-sync with wall-clock time,
			// causing events to land on a future date and become invisible in the
			// dashboard.  The frontend mixpanel-browser SDK also omits 'time'.
			unset( $properties['time'] );

			// Add $insert_id for deduplication - Mixpanel uses this to avoid
			// silently deduplicating events that look similar.
			if ( ! isset( $properties['$insert_id'] ) ) {
				$properties['$insert_id'] = wp_generate_uuid4();
			}

			$payload[] = [
				'event'      => (string) $entry['event'],
				'properties' => $properties,
			];
		}

		if ( empty( $payload ) ) {
			return true;
		}

		$json_payload = wp_json_encode( $payload );
		$encoded      = base64_encode( $json_payload );

		$response = wp_remote_post( $this->endpoint, [
			'headers'     => [
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept'       => 'text/plain',
			],
			'body'        => 'data=' . urlencode( $encoded ) . '&verbose=1&ip=0',
			'timeout'     => 10,
			'blocking'    => false,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}
}
