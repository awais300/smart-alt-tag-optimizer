<?php
/**
 * AiConnectorFactory - Factory for creating AI connectors.
 *
 * @package SmartAlt
 */

namespace SmartAlt\Core;

/**
 * Factory for AI connectors.
 */
class AiConnectorFactory {

	/**
	 * Get the configured AI connector.
	 *
	 * @return AiConnectorInterface|null
	 */
	public static function get_connector() {
		$connector_type = get_option( 'smartalt_ai_connector_type', 'generic_http' );

		/**
		 * Filter to allow custom connector implementations.
		 *
		 * @param AiConnectorInterface|null $connector Connector instance.
		 * @param string $connector_type Type of connector.
		 */
		$connector = apply_filters( 'smartalt_ai_connector', null, $connector_type );

		if ( $connector instanceof AiConnectorInterface ) {
			return $connector;
		}

		// Default to GenericHttpAiConnector
		if ( 'generic_http' === $connector_type ) {
			$endpoint = get_option( 'smartalt_ai_endpoint' );
			if ( ! $endpoint ) {
				return null;
			}

			return new GenericHttpAiConnector();
		}

		return null;
	}
}