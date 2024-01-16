<?php

namespace Automattic\WooCommerce\Internal\ReceiptRendering;

use Automattic\WooCommerce\Internal\TransientFiles\TransientFilesEngine;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use \WC_Order;

/**
 * This class generates printable order receipts as transient files (see src/Internal/TransientFiles).
 * The template for the receipt is Templates/order-receipt.php, it uses the variables returned as array keys
 * 'get_order_data'.
 *
 * When a receipt is generated for an order with 'generate_receipt' the receipt file name is stored as order meta
 * (see RECEIPT_FILE_NAME_META_KEY) for later retrieval with 'get_existing_receipt'. Beware! The files pointed
 * by such meta keys could have expired and thus no longer exist. 'get_existing_receipt' will appropriately return null
 * if the meta entry exists but the file doesn't.
 */
class ReceiptRenderingEngine {

	private const FONT_SIZE = 12;

	private const LINE_HEIGHT = self::FONT_SIZE*1.5;

	private const ICON_HEIGHT = self::LINE_HEIGHT;

	private const ICON_WIDTH = self::ICON_HEIGHT*(4/3);


	/**
	 * Order meta key that stores the file name of the last generated receipt.
	 */
	public const RECEIPT_FILE_NAME_META_KEY = '_receipt_file_name';

	/**
	 * The instance of TransientFilesEngine to use.
	 *
	 * @var TransientFilesEngine
	 */
	private TransientFilesEngine $transient_files_engine;

	/**
	 * The instance of LegacyProxy to use.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Initializes the class.
	 *
	 * @param TransientFilesEngine $transient_files_engine The instance of TransientFilesEngine to use.
	 * @param LegacyProxy          $legacy_proxy The instance of LegacyProxy to use.
	 * @internal
	 */
	final public function init( TransientFilesEngine $transient_files_engine, LegacyProxy $legacy_proxy ) {
		$this->transient_files_engine = $transient_files_engine;
		$this->legacy_proxy           = $legacy_proxy;
	}

	/**
	 * Get the (transient) file name of the receipt for an order, creating a new file if necessary.
	 *
	 * If $force_new is false, and a receipt file for the order already exists (as pointed by order meta key
	 * RECEIPT_FILE_NAME_META_KEY), then the name of the already existing receipt file is returned.
	 *
	 * If $force_new is true, OR if it's false but no receipt file for the order exists (no order meta with key
	 * RECEIPT_FILE_NAME_META_KEY exists, OR it exists but the file it points to doesn't), then a new receipt
	 * transient file is created with the supplied expiration date (defaulting to "tomorrow"), and the new file name
	 * is stored as order meta with the key RECEIPT_FILE_NAME_META_KEY.
	 *
	 * @param int|WC_Order    $order The order object or order id to get the receipt for.
	 * @param string|int|null $expiration_date GMT expiration date formatted as yyyy-mm-dd, or as a timestamp, or null for "tomorrow".
	 * @param bool            $force_new If true, creates a new receipt file even if one already exists for the order.
	 * @return string|null The file name of the new or already existing receipt file, null if an order id is passed and the order doesn't exist.
	 * @throws \InvalidArgumentException Invalid expiration date (wrongly formatted, or it's a date in the past).
	 * @throws \Exception The directory to store the file doesn't exist and can't be created.
	 */
	public function generate_receipt( $order, $expiration_date = null, bool $force_new = false ) : ?string {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
			if ( false === $order ) {
				return null;
			}
		}

		if ( ! $force_new ) {
			$existing_receipt_filename = $this->get_existing_receipt( $order );
			if ( ! is_null( $existing_receipt_filename ) ) {
				return $existing_receipt_filename;
			}
		}

		$expiration_date ??=
			$this->legacy_proxy->call_function(
				'gmdate',
				'Y-m-d',
				$this->legacy_proxy->call_function(
					'strtotime',
					'+1 days'
				)
			);

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $this->get_order_data( $order ) );

		ob_start();
		include __dir__ . '/Templates/order-receipt.php';
		$rendered_template = ob_get_contents();
		ob_end_clean();

		$file_name = $this->transient_files_engine->create_transient_file( $rendered_template, $expiration_date );

		$order->update_meta_data( self::RECEIPT_FILE_NAME_META_KEY, $file_name );
		$order->save_meta_data();

		return $file_name;
	}

	/**
	 * Get the file name of an existing receipt file for an order.
	 *
	 * A receipt is considered to be available for the order if there's an order meta entry with key
	 * RECEIPT_FILE_NAME_META_KEY AND the transient file it points to exists.
	 *
	 * @param WC_Order $order The order object or order id to get the receipt for.
	 * @return string|null The receipt file name, or null if no receipt is currently available for the order.
	 */
	public function get_existing_receipt( $order ): ?string {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
			if ( false === $order ) {
				return null;
			}
		}

		$existing_receipt_filename = $order->get_meta( self::RECEIPT_FILE_NAME_META_KEY, true );
		return '' === $existing_receipt_filename || is_null( $this->transient_files_engine->get_transient_file_path( $existing_receipt_filename ) ) ? null : $existing_receipt_filename;
	}

	/**
	 * Get the order data that the receipt template will use.
	 *
	 * @param WC_Order $order The order to get the data from.
	 * @return array The order data as an associative array.
	 */
	private function get_order_data( WC_Order $order ): array {
		$store_name = get_bloginfo('name');
		if($store_name) {
			$receipt_title = sprintf(__('Receipt from %s', 'woocommerce'), $store_name);
		}
		else {
			$receipt_title = __('Receipt', 'wcoocommerce');
		}

		$order_id = $order->get_id();
		if($order_id) {
			$summary_title = sprintf( __('Summary: Order #%d', 'woocommerce'), $order->get_id());
		}
		else {
			$summary_title = __('Summary', 'woocommerce');
		}

		$get_price_args = ['currency' => $order->get_currency()];

		$line_items_info=[];
		$line_items = $order->get_items('line_item');
		foreach($line_items as $line_item) {
			$line_item_product = $line_item->get_product();
			$line_item_title =
				($line_item_product instanceof \WC_Product_Variation) ?
					(wc_get_product($line_item_product->get_parent_id())->get_name()) . '. ' . $line_item_product->get_attribute_summary() :
					$line_item_product->get_name();
			$line_items_info[] = [
				'title' => wp_kses( $line_item_title, [], []),
				'quantity' => $line_item->get_quantity(),
				'amount' => wc_price($line_item->get_total(), $get_price_args)
			];
		}

		$line_items_info[] = [
			'title' => __('Subtotal', 'woocommerce'),
			'amount' => wc_price($order->get_subtotal(), $get_price_args)
		];

		foreach($order->get_fees() as $fee) {
			$name = $fee->get_name();
			$line_items_info[] = [
				'title' => '' === $name ? __('Fee', 'woocommerce') : $name,
				'amount' => wc_price($fee->get_amount(), $get_price_args)
			];
		}

		foreach($order->get_coupons() as $coupon) {
			$line_items_info[] = [
				'title' => sprintf(__('Discount (%s)', 'woocommerce'), $coupon->get_name()),
				'amount' => wc_price(-$coupon->get_discount(), $get_price_args)
			];
		}

		$total_taxes = 0;
		foreach($order->get_taxes() as $tax) {
			$total_taxes += $tax->get_tax_total();
		}

		$line_items_info[] = [
			'title' => __('Shipping', 'woocommerce'),
			'amount' => wc_price($order->get_shipping_total(), $get_price_args)
		];
		$line_items_info[] = [
			'title' => __('Taxes', 'woocommerce'),
			'amount' => wc_price($total_taxes, $get_price_args)
		];
		$line_items_info[] = [
			'title' => __('Amount Paid', 'woocommerce'),
			'amount' => wc_price($order->get_total(), $get_price_args)
		];

		return array(
			'constants' => [
				'font_size' => self::FONT_SIZE,
				'margin' => 16,
				'title_font_size' => 24,
				'footer_font_size' => 10,
				'line_height' => self::LINE_HEIGHT,
				'icon_height' => self::ICON_HEIGHT,
				'icon_width' => self::ICON_WIDTH,
			],
			'texts' => [
				'receipt_title' => $receipt_title,
				'amount_paid_section_title' => __('Amount Paid', 'woocommerce'),
				'date_paid_section_title' => __('Date Paid', 'woocommerce'),
				'payment_method_section_title' => __('Payment method', 'woocommerce'),
				'summary_section_title' => $summary_title,
				'order_notes_section_title' => __('Notes', 'woocommerce')
			],
			'formatted_amount' => wc_price( $order->get_total(), $get_price_args),
			'formatted_date' => wc_format_datetime( $order->get_date_created() ), //!!! date paid
			'card_icon_name' => 'foo', //!!! TODO
			'card_last_digits' => '1234', //!!! TODO
			'line_items' => $line_items_info,
			'payment_method' => $order->get_payment_method_title(),
			'notes' => array_map('get_comment_text', $order->get_customer_order_notes())
		);
	}
}
