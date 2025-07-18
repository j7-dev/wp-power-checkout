<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core;

use J7\WpUtils\Classes\ApiBase;

/** Api */
final class Api extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string Namespace */
	protected $namespace = 'power-checkout/slp-redirect';

	/**
	 * APIs
	 *
	 * @var array<array{
	 *  endpoint: string,
	 *  method: 'get' | 'post' | 'patch' | 'delete',
	 *  permission_callback?: callable,
	 *  callback?: callable,
	 *  schema?: array|null
	 * }> $apis API 列表
	 */
	protected $apis = [
		[
			'endpoint' => 'create_session',
			'method'   => 'get',
		],
	];
}
