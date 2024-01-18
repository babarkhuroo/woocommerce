<?php

namespace Automattic\WooCommerce\Internal\DependencyManagement\ServiceProviders;

use Automattic\WooCommerce\Internal\DependencyManagement\AbstractServiceProvider;
use Automattic\WooCommerce\Internal\ProductImage\FilterClauses;
use Automattic\WooCommerce\Internal\ProductImage\FilterData;

/**
 * LoggingServiceProvider class.
 */
class ProductQueryFiltersServiceProvider extends AbstractServiceProvider {
	/**
	 * List services provided by this class.
	 *
	 * @var string[]
	 */
	protected $provides = array(
		FilterClauses::class,
		FilterData::class,
	);

	/**
	 * Registers services provided by this class.
	 *
	 * @return void
	 */
	public function register() {
		$this->share( FilterClauses::class );

		$this->share( FilterData::class )->addArgument( FilterClauses::class );
	}
}
