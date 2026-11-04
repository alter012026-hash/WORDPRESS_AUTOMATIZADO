<?php
/**
 * Plugin Name: Article Automation Bridge
 * Description: Gera artigos com IA e os salva como rascunhos para revisão humana.
 * Version: 1.2.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Passe Já Concursos
 * License: GPL-2.0-or-later
 * Text Domain: article-automation-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/admin.php';

register_activation_hook( __FILE__, 'article_automation_bridge_schedule_generation' );
register_deactivation_hook( __FILE__, 'article_automation_bridge_clear_generation_schedule' );

/**
 * Register the endpoint used by an external article generator.
 */
function article_automation_bridge_register_routes() {
	register_rest_route(
		'article-automation/v1',
		'/articles',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'article_automation_bridge_create_article',
			'permission_callback' => 'article_automation_bridge_check_permission',
			'args'                => array(
				'title'     => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'content'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'wp_kses_post',
				),
				'excerpt'   => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'slug'      => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_title',
				),
				'category_id' => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'source_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'article_automation_bridge_register_routes' );

/**
 * Require a shared secret configured outside the plugin.
 *
 * @param WP_REST_Request $request Incoming REST request.
 * @return true|WP_Error
 */
function article_automation_bridge_check_permission( $request ) {
	if ( ! defined( 'ARTICLE_AUTOMATION_BRIDGE_TOKEN' ) || '' === ARTICLE_AUTOMATION_BRIDGE_TOKEN ) {
		return new WP_Error(
			'article_automation_bridge_not_configured',
			'A integração ainda não foi configurada no wp-config.php.',
			array( 'status' => 503 )
		);
	}

	$authorization = $request->get_header( 'authorization' );
	if ( ! is_string( $authorization ) || ! preg_match( '/^Bearer\s+(.+)$/i', $authorization, $matches ) ) {
		return new WP_Error(
			'article_automation_bridge_unauthorized',
			'Token de integração ausente ou inválido.',
			array( 'status' => 401 )
		);
	}

	if ( ! hash_equals( ARTICLE_AUTOMATION_BRIDGE_TOKEN, trim( $matches[1] ) ) ) {
		return new WP_Error(
			'article_automation_bridge_unauthorized',
			'Token de integração ausente ou inválido.',
			array( 'status' => 401 )
		);
	}

	return true;
}

/**
 * Save an incoming article as a draft, avoiding duplicate source IDs.
 *
 * @param WP_REST_Request $request Incoming REST request.
 * @return WP_REST_Response|WP_Error
 */
function article_automation_bridge_create_article( $request ) {
	$title     = trim( (string) $request->get_param( 'title' ) );
	$content   = trim( (string) $request->get_param( 'content' ) );
	$source_id = trim( (string) $request->get_param( 'source_id' ) );

	if ( '' === $title || '' === $content || '' === $source_id ) {
		return new WP_Error(
			'article_automation_bridge_missing_fields',
			'Título, conteúdo e source_id não podem ficar vazios.',
			array( 'status' => 400 )
		);
	}

	$existing = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => array( 'draft', 'pending', 'publish', 'future', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_article_automation_source_id',
			'meta_value'     => $source_id,
		)
	);

	if ( ! empty( $existing ) ) {
		return new WP_Error(
			'article_automation_bridge_duplicate',
			'Já existe um artigo enviado com este source_id.',
			array(
				'status'  => 409,
				'post_id' => (int) $existing[0],
			)
		);
	}

	$post_data = array(
		'post_type'    => 'post',
		'post_status'  => 'draft',
		'post_title'   => $title,
		'post_content' => $content,
		'post_excerpt' => (string) $request->get_param( 'excerpt' ),
		'post_name'    => (string) $request->get_param( 'slug' ),
	);

	$category_id = absint( $request->get_param( 'category_id' ) );
	if ( $category_id ) {
		$category = get_term( $category_id, 'category' );
		if ( ! $category || is_wp_error( $category ) ) {
			return new WP_Error(
				'article_automation_bridge_invalid_category',
				'A categoria informada não existe neste WordPress.',
				array( 'status' => 400 )
			);
		}
		$post_data['post_category'] = array( $category_id );
	}

	if ( defined( 'ARTICLE_AUTOMATION_BRIDGE_AUTHOR_ID' ) ) {
		$author_id = absint( ARTICLE_AUTOMATION_BRIDGE_AUTHOR_ID );
		if ( $author_id && get_user_by( 'id', $author_id ) ) {
			$post_data['post_author'] = $author_id;
		}
	}

	$post_id = wp_insert_post( wp_slash( $post_data ), true );

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	add_post_meta( $post_id, '_article_automation_source_id', $source_id, true );

	return new WP_REST_Response(
		array(
			'id'     => (int) $post_id,
			'status' => 'draft',
			'link'   => get_edit_post_link( $post_id, 'raw' ),
		),
		201
	);
}
