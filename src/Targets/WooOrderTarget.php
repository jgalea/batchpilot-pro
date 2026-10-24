<?php
namespace BatchPilotPro\Targets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BatchPilot\Contracts\FilterDefinition;
use BatchPilot\Contracts\QueryArgs;
use BatchPilot\Contracts\TargetInterface;

/**
 * WooCommerce orders. HPOS-aware: queries via wc_get_orders() which transparently
 * hits either the legacy posts table (post_type=shop_order) or the new
 * wc_orders / wc_order_addresses / wc_order_operational_data tables depending
 * on the store's HPOS setting.
 *
 * Only registered when WooCommerce is active.
 */
final class WooOrderTarget implements TargetInterface {

	private const SUPPORTED = [ 'woo-order-delete' ];

	public function slug(): string {
		return 'woo-order';
	}

	public function label(): string {
		return __( 'WooCommerce orders', 'batchpilot-pro' );
	}

	public function get_filters(): array {
		return [
			new FilterDefinition(
				'ids',
				__( 'Specific order IDs', 'batchpilot-pro' ),
				'ids',
				[ 'multiple' => true ]
			),
			new FilterDefinition(
				'status',
				__( 'Status', 'batchpilot-pro' ),
				'enum',
				[
					'multiple' => true,
					'options'  => $this->status_options(),
				]
			),
			new FilterDefinition( 'customer_id', __( 'Customer (user ID)', 'batchpilot-pro' ), 'user' ),
			new FilterDefinition( 'date_before', __( 'Created before', 'batchpilot-pro' ), 'date' ),
			new FilterDefinition( 'date_after', __( 'Created after', 'batchpilot-pro' ), 'date' ),
		];
	}

	public function query( QueryArgs $args, int $limit = 0, int $offset = 0 ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$query = $this->build_wc_args( $args );

		$query['limit']   = $limit > 0 ? $limit : -1;
		$query['offset']  = $offset;
		$query['return']  = 'ids';
		$query['orderby'] = 'id';
		$query['order']   = 'DESC';

		$ids = (array) \wc_get_orders( $query );
		return array_map( 'intval', $ids );
	}

	public function count( QueryArgs $args ): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		// wc_get_orders supports `paginate=true` which returns a stdClass with
		// total + max_num_pages, avoiding a full hydration just to count.
		$query             = $this->build_wc_args( $args );
		$query['limit']    = 1;
		$query['return']   = 'ids';
		$query['paginate'] = true;

		$result = \wc_get_orders( $query );
		return isset( $result->total ) ? (int) $result->total : 0;
	}

	public function get_display( int $id ): array {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return [
				'id'            => $id,
				'title'         => '',
				'status'        => 'missing',
				'date'          => '',
				'edit_url'      => '',
				'thumbnail_url' => null,
			];
		}

		$order = \wc_get_order( $id );
		if ( false === $order ) {
			return [
				'id'            => $id,
				'title'         => '',
				'status'        => 'missing',
				'date'          => '',
				'edit_url'      => '',
				'thumbnail_url' => null,
			];
		}

		$total    = (string) $order->get_formatted_order_total();
		$customer = $order->get_billing_email();
		if ( '' === $customer ) {
			$customer = sprintf( '#%d', (int) $order->get_id() );
		}

		return [
			'id'            => (int) $order->get_id(),
			'title'         => sprintf( '%s — %s', $customer, wp_strip_all_tags( $total ) ),
			'status'        => (string) $order->get_status(),
			'date'          => (string) $order->get_date_created()->date( 'Y-m-d H:i:s' ),
			'edit_url'      => (string) $order->get_edit_order_url(),
			'thumbnail_url' => null,
		];
	}

	public function supports_operation( string $operation_slug ): bool {
		return in_array( $operation_slug, self::SUPPORTED, true );
	}

	/**
	 * @return array<int, array{label: string, value: string}>
	 */
	private function status_options(): array {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return [];
		}
		$out = [];
		foreach ( \wc_get_order_statuses() as $slug => $label ) {
			// wc_get_order_statuses returns 'wc-pending' => 'Pending payment'. The
			// stored status on an order is without the 'wc-' prefix.
			$value = 0 === strpos( (string) $slug, 'wc-' ) ? substr( (string) $slug, 3 ) : (string) $slug;
			$out[] = [
				'label' => (string) $label,
				'value' => $value,
			];
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_wc_args( QueryArgs $args ): array {
		$query = [
			'type' => 'shop_order',
		];

		if ( $args->has( 'ids' ) ) {
			$ids = array_values(
				array_filter(
					array_map( 'intval', (array) $args->get( 'ids' ) ),
					static fn ( int $id ): bool => $id > 0
				)
			);
			if ( ! empty( $ids ) ) {
				$query['post__in'] = $ids;
			}
		}

		if ( $args->has( 'status' ) ) {
			$statuses = (array) $args->get( 'status' );
			$statuses = array_values( array_filter( array_map( 'strval', $statuses ) ) );
			if ( ! empty( $statuses ) ) {
				$query['status'] = $statuses;
			}
		}

		if ( $args->has( 'customer_id' ) ) {
			$query['customer_id'] = (int) $args->get( 'customer_id' );
		}

		if ( $args->has( 'date_before' ) ) {
			$query['date_created'] = '<=' . (string) $args->get( 'date_before' );
		}
		if ( $args->has( 'date_after' ) ) {
			$existing             = $query['date_created'] ?? '';
			$query['date_created'] = '' === $existing
				? '>=' . (string) $args->get( 'date_after' )
				: (string) $args->get( 'date_after' ) . '...' . substr( $existing, 2 );
		}

		return $query;
	}
}
