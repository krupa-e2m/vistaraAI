<?php
/**
 * Vistara — custom post type registrations.
 *
 * `speaker` — the homepage speakers grid. Promoted from the speakers layout's ACF repeater
 * per client feedback (2026-08-19): speakers are an independent repeatable entity, managed
 * once under their own admin menu instead of inline page rows. Name = post title, photo =
 * featured image, role = ACF `role` field (Speaker Details group, registered in the DB).
 * Not publicly queryable — this single-page site renders speakers only inside the
 * `speakers` FC section, ordered by menu_order (the Order box) then publish date.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	function () {
		register_post_type(
			'speaker',
			array(
				'labels'              => array(
					'name'                  => __( 'Speakers', 'vistara' ),
					'singular_name'         => __( 'Speaker', 'vistara' ),
					'add_new_item'          => __( 'Add New Speaker', 'vistara' ),
					'edit_item'             => __( 'Edit Speaker', 'vistara' ),
					'featured_image'        => __( 'Speaker Photo', 'vistara' ),
					'set_featured_image'    => __( 'Set speaker photo', 'vistara' ),
					'remove_featured_image' => __( 'Remove speaker photo', 'vistara' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-groups',
				'menu_position'       => 21,
				'supports'            => array( 'title', 'thumbnail', 'page-attributes' ),
				'has_archive'         => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
			)
		);
	}
);
