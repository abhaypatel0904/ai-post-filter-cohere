<?php
/**
 * Plugin Name: AI Post Filter with Cohere
 * Description: Use natural language prompts to filter posts of any post type via AI.
 * Version: 1.0.0
 * Author: Abhay Patel
 * Author URI: https://github.com/abhaypatel0904
 * Text Domain: ai-post-filter-cohere
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.0
 * Developer: abhaypatel01
 * Developer URI: https://profiles.wordpress.org/abhaypatel01/
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package Ask_AI_assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'restrict_manage_posts', 'aipf_add_ai_prompt_search_field' );

if ( ! function_exists( 'aipf_add_ai_prompt_search_field' ) ) {
	/**
	 * Adds the AI prompt input field to the admin product list screen.
	 */
	function aipf_add_ai_prompt_search_field() {
		global $typenow;
		$ai_prompt = isset( $_GET['ai_prompt'] ) ? sanitize_text_field( wp_unslash( $_GET['ai_prompt'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$label     = sprintf(// translators: %s: The current post type name (e.g., Product).
			_x( 'Ask AI to filter %ss...', 'admin placeholder text', 'ai-post-filter-cohere' ),
			ucwords( str_replace( '-', ' ', strtolower( $typenow ) ) )
		);
		echo '<input type="text" name="ai_prompt" value="' . esc_attr( $ai_prompt ) . '" placeholder="' . esc_attr( $label ) . '" style="width:300px; margin-right: 10px;" />';
		wp_nonce_field( 'aipf_prompt_filter_action', 'aipf_prompt_filter_nonce', false );
	}
}
add_action( 'pre_get_posts', 'aipf_intercept_ai_prompt_request' );

if ( ! function_exists( 'aipf_intercept_ai_prompt_request' ) ) {
	/**
	 * Modify the admin product query based on AI prompt
	 *
	 * @param WP_Query $query The current query object.
	 */
	function aipf_intercept_ai_prompt_request( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || ( empty( $_GET['aipf_prompt_filter_nonce'] ) ) || empty( $_GET['ai_prompt'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['aipf_prompt_filter_nonce'] ) ), 'aipf_prompt_filter_action' ) ) {
			return;
		}

		$prompt   = sanitize_text_field( wp_unslash( $_GET['ai_prompt'] ) );
		$response = aipf_call_cohere_api( $prompt );

		if ( empty( $response[0]['text'] ) ) {
			return;
		}

		$args = json_decode( $response[0]['text'], true );

		if ( empty( $args ) || ! is_array( $args ) ) {
			return;
		}

		$allowed_keys = array(
			'post_status',
			'post_type',
			'posts_per_page',
			'orderby',
			'order',
			's',
			'author',
			'post__in',
			'post__not_in',
			'meta_query',
			'tax_query',
			'meta_key',
			'meta_value',
			'product_cat',
		);

		foreach ( $args as $key => $value ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				continue;
			}

			// Normalize meta_query.
			if ( 'meta_query' === $key ) {
				$value = aipf_normalize_meta_query( $value );
				if ( empty( $value ) ) {
					continue;
				}
			}

			// Normalize tax_query.
			if ( 'tax_query' === $key ) {
				$value = aipf_normalize_tax_query( $value );
				if ( empty( $value ) ) {
					continue;
				}
			}

			$query->set( $key, $value );
		}
	}
}

if ( ! function_exists( 'aipf_call_cohere_api' ) ) {
	/**
	 * Call the Cohere Chat API and return the decoded response.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prompt Optional. Natural‑language prompt. Default empty string.
	 * @return array Associative array containing the API response or an `error` key.
	 */
	function aipf_call_cohere_api( $prompt = '' ) {
		global $typenow;
		if ( empty( $prompt ) ) {
			return array( 'error' => 'Prompt is empty' );
		}

		$api_key = get_option( 'aipf_cohere_api_key', '' );
		if ( empty( $api_key ) ) {
			add_action( 'admin_notices', 'aipf_show_missing_api_key_notice' );
			return;
		}

		$body = array(
			'model'           => 'command-a-03-2025',
			'temperature'     => 0.3,
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => 'You are an assistant that always returns wp query params using post type as  ' . $typenow . ' and its meta data, or texonomy query(if asked in prompt for category tags etc.) or post table fields( if asked about only the post titile,slug, guid, name, date, then make the post table query )',
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'response_format' => array( 'type' => 'json_object' ),
		);

		$response = wp_remote_post(
			'https://api.cohere.com/v2/chat',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$body_string = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body_string, true );

		if ( ! empty( $data['message']['content'] ) && is_array( $data['message']['content'] ) ) {
			return $data['message']['content'];
		}

		if ( ! empty( $data['message']['content'] ) && is_string( $data['message']['content'] ) ) {
			return json_decode( $data['message']['content'], true );
		}

		return array();
	}
}

if ( ! function_exists( 'aipf_normalize_meta_query' ) ) {
	/**
	 * Normalize meta_query to be a proper array of arrays
	 *
	 * @param mixed $meta_query Raw meta_query value.
	 * @return array Normalized meta_query.
	 */
	function aipf_normalize_meta_query( $meta_query = array() ) {
		if ( empty( $meta_query ) ) {
			return array();
		}
		// Wrap single array into an indexed array if needed.
		if ( isset( $meta_query['key'] ) ) {
			return array( $meta_query );
		}
		if ( is_array( $meta_query ) && isset( $meta_query[0] ) ) {
			return $meta_query;
		}
		return array();
	}
}

if ( ! function_exists( 'aipf_normalize_tax_query' ) ) {
	/**
	 * Normalize tax_query to be a proper array of arrays
	 *
	 * @param mixed $tax_query Raw tax_query value.
	 * @return array Normalized tax_query.
	 */
	function aipf_normalize_tax_query( $tax_query = array() ) {
		if ( empty( $tax_query ) ) {
			return array();
		}

		if ( isset( $tax_query['taxonomy'] ) ) {
			return array( $tax_query );
		}

		if ( is_array( $tax_query ) && isset( $tax_query[0] ) ) {
			return $tax_query;
		}

		return array();
	}
}

add_action( 'admin_init', 'aipf_register_cohere_api_setting' );

if ( ! function_exists( 'aipf_register_cohere_api_setting' ) ) {
	/**
	 * Register setting and add Cohere API key field to General Settings.
	 */
	function aipf_register_cohere_api_setting() {
		register_setting(
			'general',
			'aipf_cohere_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => false,
			)
		);

		add_settings_field(
			'aipf_cohere_api_key',
			__( 'Cohere API Key', 'ai-post-filter-cohere' ),
			'aipf_render_cohere_api_key_field',
			'general'
		);
	}
}

if ( ! function_exists( 'aipf_render_cohere_api_key_field' ) ) {
	/**
	 * Output field HTML for Cohere API Key.
	 */
	function aipf_render_cohere_api_key_field() {
		$api_key = get_option( 'aipf_cohere_api_key', '' );

		echo '<input type="text" id="aipf_cohere_api_key" name="aipf_cohere_api_key" value="' . esc_attr( $api_key ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Enter your Cohere API key to enable AI-powered commands.', 'ai-post-filter-cohere' ) . '</p>';
		printf(
			'<p class="description">%s</p>',
			wp_kses_post(
				sprintf(// translators: %s is a link to Cohere API key documentation.
					__( 'Click<a href="%s" target="_blank"> here </a>to know how to create a Cohere API key', 'ai-post-filter-cohere' ),
					'https://docs.aicontentlabs.com/articles/cohere-api-key/'
				)
			)
		);
	}
}

if ( ! function_exists( 'aipf_show_missing_api_key_notice' ) ) {
	/**
	 * Show admin notice when Cohere API key is missing.
	 */
	function aipf_show_missing_api_key_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			wp_kses_post(
				sprintf(
					/* translators: %s: Link to general settings */
					__( 'Cohere API key is missing. Please add it from the <a href="%s">General Settings</a>.', 'ai-post-filter-cohere' ),
					esc_url( admin_url( 'options-general.php' ) )
				)
			)
		);
	}
}

if ( ! function_exists( 'aipf_add_settings_link' ) ) {
	/**
	 * Add a settings link to the plugin actions on the plugins list page.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified plugin action links.
	 */
	function aipf_add_settings_link( $links = array() ) {
		$settings_url  = admin_url( 'options-general.php' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'ai-post-filter-cohere' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'aipf_add_settings_link' );
