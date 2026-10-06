<?php
/**
 * WooCommerce product management.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates or reuses one hidden product per event.
 */
final class Product_Manager {
	/**
	 * Ensure the event has a reusable hidden WooCommerce product.
	 *
	 * @param int $event_id Event post ID.
	 * @return int Product ID, or 0 on failure.
	 */
	public function sync_for_event( $event_id ) {
		$event_id = absint( $event_id );
		$post     = get_post( $event_id );

		if ( ! $event_id || ! $post || 'auto-draft' === $post->post_status ) {
			return 0;
		}

		$product_id = $this->get_product_id_for_event( $event_id );
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( ! $product ) {
			$product = new \WC_Product_Simple();
		}

		$price = Meta::price( $event_id );
		$name  = get_the_title( $event_id ) ?: __( 'York Scouts Event Ticket', 'york-scouts-events' );

		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_downloadable( false );
		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->set_manage_stock( false );
		$product->set_sold_individually( false );

		$product_id = absint( $product->save() );

		if ( $product_id ) {
			update_post_meta( $event_id, Meta::PRODUCT_ID, $product_id );
			update_post_meta( $product_id, '_ys_event_id', $event_id );
			update_post_meta( $product_id, '_ys_event_ticket_product', 'yes' );
		}

		return $product_id;
	}

	/**
	 * Get an existing product, using stored meta first and meta lookup second.
	 *
	 * @param int $event_id Event post ID.
	 * @return int Product ID.
	 */
	public function get_product_id_for_event( $event_id ) {
		$stored_id = absint( get_post_meta( $event_id, Meta::PRODUCT_ID, true ) );

		if ( $stored_id && wc_get_product( $stored_id ) ) {
			return $stored_id;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => array( 'publish', 'draft', 'private', 'trash' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => '_ys_event_id',
						'value' => absint( $event_id ),
					),
				),
			)
		);

		$product_id = $query->posts ? absint( $query->posts[0] ) : 0;

		if ( $product_id ) {
			update_post_meta( $event_id, Meta::PRODUCT_ID, $product_id );
		}

		return $product_id;
	}
}
