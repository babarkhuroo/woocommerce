<?php

namespace Automattic\WooCommerce\Blocks\BlockTypes\OrderConfirmation;

use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;

/**
 * AdditionalFields class.
 */
class AdditionalFields extends AbstractOrderConfirmationBlock {

	/**
	 * Block name.
	 *
	 * @var string
	 */
	protected $block_name = 'order-confirmation-additional-fields';

	/**
	 * This renders the content of the block within the wrapper.
	 *
	 * @param \WC_Order    $order Order object.
	 * @param string|false $permission If the current user can view the order details or not.
	 * @param array        $attributes Block attributes.
	 * @param string       $content Original block content.
	 * @return string
	 */
	protected function render_content( $order, $permission = false, $attributes = [], $content = '' ) {
		if ( ! $permission ) {
			return $content;
		}

		$controller = Package::container()->get( CheckoutFields::class );
		$content   .= $this->render_additional_fields(
			array_merge(
				$controller->get_order_additional_fields_with_values( $order, 'contact' ),
				$controller->get_order_additional_fields_with_values( $order, 'additional' )
			)
		);

		return $content;
	}
}
