<?php
/**
 * Plugin Name: AI Post Filter with Cohere
 * Description: Use natural language prompts to filter posts of any post type via AI.
 * Version: 1.1.0
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
	 * Adds the AI prompt input field to the admin post type list screen.
	 */
	function aipf_add_ai_prompt_search_field() {
		global $typenow;
		$ai_prompt = isset( $_GET['ai_prompt'] ) ? sanitize_text_field( wp_unslash( $_GET['ai_prompt'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$label     = sprintf(// translators: %s: The current post type name.
			_x( 'Ask AI to filter %ss...', 'admin placeholder text', 'ai-post-filter-cohere' ),
			ucwords( str_replace( '-', ' ', strtolower( $typenow ) ) )
		);
		// AI search field HTML.
		echo '<input type="text" id="ai_prompt" name="ai_prompt" value="' . esc_attr( $ai_prompt ) . '" placeholder="' . esc_attr( $label ) . '" style="width:300px; margin-right: 10px;" />';
		// Voice Button HTML.
		echo '<button style="margin-right: 10px;" type="button" id="ai_voice_btn" class="button" title="' . esc_attr__( 'Speak your prompt', 'ask-ai-assistant' ) . '">🎤</button>';
		wp_nonce_field( 'aipf_prompt_filter_action', 'aipf_prompt_filter_nonce', false );
	}
}
add_action( 'pre_get_posts', 'aipf_intercept_ai_prompt_request' );

if ( ! function_exists( 'aipf_intercept_ai_prompt_request' ) ) {
	/**
	 * Modify the admin post type filter query based on AI prompt
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
			'post_content',
			'product_cat',
			'date_query',
			'compare',
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
		$system_message = 'You are a helpful assistant that always generates valid WordPress WP_Query arguments as JSON objects, based on user instructions.

			Rules to follow strictly:
				- The response must be in valid JSON only, not PHP.
				- Use double quotes for all keys and string values.
				- Do not include explanations, just return the JSON object.

			1. Always include: 
				"post_type": "' . $typenow . '"

			2. For post metadata (e.g., _price, _stock_status, rating), use this format:
				"meta_query": [
					{
					"key": "_price",
					"value": "100",
					"compare": ">=",
					"type": "NUMERIC"
					}
				]
				Example Prompt: Show "' . $typenow . '" where price is not set(or other such type of prompts)
				{
					"post_type": ""' . $typenow . '"",
					"meta_query": [
						{
						"key": "_price",
						"compare": "NOT EXISTS"
						}
					]
				}


			3. For taxonomy filters (e.g., "' . $typenow . '" categories or tags):
				"tax_query": [
					{
					"taxonomy": "product_cat",
					"field": "slug",
					"terms": ["books"]
					}
				]

			4. For core post fields:
				- If user refers to "description" or "short description", do not use "meta_query". Instead, use:
				"post_content": "search terms"

				This searches across "post_title", "post_content", and "post_excerpt".

				- Other mappings:
				- "slug" or "post_name" → "name"
				- "post_status"
				- "post_content" -> for descriptions
				- "post_excerpt" -> for short descriptions
				- "author" → "post_author"
				- "orderby" / "order"
				- "IDs" → "post__in", "post__not_in"
				Examples For core post fields (learn format strictly):
					Prompt: Get all "' . $typenow . '" that do not have a description(or other such type of prompts)
					{
						"post_type": ""' . $typenow . '"",
						"s": ""
					}
					Prompt: Show "' . $typenow . '" with no short description(or other such type of prompts)
					{
						"post_type": ""' . $typenow . '"",
						"post_content": "",
						"compare": "="
					}
					Prompt: Get all "' . $typenow . '" with description equal to "eco-friendly"(or other such type of prompts)
					{
					"post_type": ""' . $typenow . '"",
					"s": "eco-friendly"
					}


			5. For date filters:
				Example 1: ' . $typenow . ' published in the last 7 days
				"date_query": [
					{
						"after": "1 week ago",
					}
				]
				Example 2: ' . $typenow . ' published between January 1 and May 31, 2024
				{
					"date_query": [
						{
							"after": "January 1st, 2024",
							"before": "May 31st, 2024",
							"inclusive": true
						}
					]
				}
				Example 2: To get ' . $typenow . ' after 1st January 2014, and before 1st March 2014 your date query would be:
				{
					"date_query": [
						{
						"year": 2014,
						"day": 1,
						"month": [1, 6],
						"compare": "BETWEEN"
						}
					]
				}

			Respond only with a valid JSON object. Example format:

			{
			"post_type": ""' . $typenow . '"",
			"meta_query": [
				{
					"key": "_price",
					"value": "100",
					"compare": ">="
				}
			],
			"tax_query": [
				{
					"taxonomy": "product_cat",
					"field": "slug",
					"terms": ["books"]
				}
			]
			}';

		$body = array(
			'model'           => 'command-a-03-2025',
			'temperature'     => 0.8,
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => $system_message,
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'response_format' => array( 'type' => 'json_object' ),
		);

		/**
		 * Filter the Cohere API request body before sending.
		 *
		 * @param array  $body   The request body array.
		 * @param string $prompt The user prompt.
		 * @param string $typenow The current post type.
		 */
		$body = apply_filters( 'aipf_cohere_api_body', $body, $prompt, $typenow );

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

add_action( 'admin_enqueue_scripts', 'aipf_enqueue_voice_script' );
if ( ! function_exists( 'aipf_enqueue_voice_script' ) ) {
	/**
	 * Enqueue the JS for the admin list screen.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	function aipf_enqueue_voice_script( $hook_suffix = '' ) {
		$screen = get_current_screen();
		if ( empty( $hook_suffix ) || empty( $screen ) || empty( $screen->post_type ) || ( 'edit' !== $screen->base ) ) {
			return;
		}
		wp_enqueue_script(
			'aipf-script',
			plugins_url( 'assets/js/script.js', __FILE__ ),
			array(),
			'1.1.0',
			true
		);
	}
}
