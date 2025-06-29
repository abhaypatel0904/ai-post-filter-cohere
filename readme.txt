=== AI Post Filter with Cohere ===
Contributors: abhaypatel01
Tags: AI search, AI Assistant, WooCommerce product filter
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.0
Stable tag: 1.1.0
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html

A Cohere AI Assistant for your site

== Description ==

Use natural language prompts to filter posts of any post type via AI.

== Installation ==

1. Unzip and upload contents of the plugin to your /wp-content/plugins/ directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WordPress admin > Settings > General, and add the API key in the "Cohere API Key" field
4. Visit the any supported post type listing page in your admin panel. You'll now see a new “Ask AI to filter…” input field above the list – enter a natural language prompt to filter items using AI.

== Frequently Asked Questions ==

= Where to add Cohere AI API key =

Go to WordPress admin > Settings > General, and add the API key in the "Cohere API Key" field

== Use Cases ==

Users can enter natural language prompts across any post type — including products, posts, custom content types, and more.

- **Product Specific Filters**:  
  - Get products published in May and are in stock
  - Get New arrivals in the Accessories category
  - Get products on sale in the electronics category
  - Get products with a sale price less than 30

- **Media-Based Filters**
  - Get posts without featured images
  - Get only products with gallery images

- **Logical Conditions (AND/OR)**
  - Get Products in stock and published after Jan 1, 2024
  - Get products from last 7 days that are out of stock

== External Services ==

This plugin connects to an API provided by Cohere (https://cohere.com) to perform AI-powered filtering of WordPress posts and custom post types. It sends the prompt entered by the admin to Cohere’s `/v2/chat` endpoint. The API responds with a set of WP_Query arguments based on understanding of the prompt.

=== Details of the API integration ===

- **What the service is**:  
  The plugin integrates with the Cohere Chat API (https://cohere.com), a third-party AI service that specializes in natural language understanding and generation.

- **What it is used for**:  
  The API is used to analyze admin-entered prompts and return relevant WP_Query arguments. These are used by the plugin to filter posts or custom post types intelligently, enabling semantic searching in the WordPress admin area.

- **What Data is Sent**:  
  - The prompt entered by the WordPress admin is sent to the Cohere API. No personal user or customer data is collected or transmitted.

- **Why the data is sent**:  
  The data is sent so that the Cohere API can understand the context of the content and the user’s intent behind the prompt. This allows it to return accurate WP_Query arguments to filter posts, instead of relying on simple keyword-based filters.

- **When Data is Sent**:  
  Only when the admin enters a prompt in the plugin interface and initiates the filter action

- **How Data is Sent**:  
  Data is sent via a secure `wp_remote_post()` request to `https://api.cohere.com/v2/chat`

- **Terms and Privacy**:
  - [Cohere Terms of Use](https://cohere.com/terms-of-use)
  - [Cohere Privacy Policy](https://cohere.com/privacy)

This API connection is essential for the plugin's AI features to function. If you choose not to use the AI filtering functionality, the rest of the plugin remains functional.

== Screenshots ==

1. Voice Search feature.

2. Search with multiple (and/or) conditions

3. Filter posts by it's meta data like. price, stock, sku etc.

== Changelog ==

= 1.1.0 (28.06.2025) =
* New: WooCommerce 9.9.5 compatible
* New: Voice search support to capture prompts using speech for searching WooCommerce products or any post type
* Update: Improved accuracy of AI-generated reponse related to date filters, metadata, and taxonomy-based prompts
* Update: POT file

= 1.0.0 (15.06.2025) =
* Initial release
