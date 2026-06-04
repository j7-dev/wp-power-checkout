<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Settings\Services;

use J7\PowerCheckout\Domains\Invoice\ProviderRegister;
use J7\PowerCheckout\Domains\Logistics\ProviderRegister as LogisticsProviderRegister;
use J7\PowerCheckout\Domains\Payment\Shared\Utils\GatewayUtils;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Classes\WP;
use J7\WpUtils\Traits\SingletonTrait;

/**
 * 設定相關的 REST API
 *
 * GET /wp-json/power-checkout/v1/settings 取得目前的設定
 */
final class SettingApiService extends ApiBase {
	use SingletonTrait;

	/** @var string REST API namespace */
	protected $namespace = 'power-checkout/v1';

	/** @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> 已註冊的 API 列表 */
	protected $apis = [
		[
			'endpoint' => 'settings',
			'method'   => 'get',
		],
		[
			'endpoint' => 'settings/(?P<provider_id>[a-zA-Z_-]+)/toggle',
			'method'   => 'post',
			'callback' => [ __CLASS__, 'toggle_providers_with_id_callback' ],
		],
		[
			'endpoint' => 'settings/(?P<provider_id>[a-zA-Z_-]+)',
			'method'   => 'get',
			'callback' => [ __CLASS__, 'get_providers_with_id_callback' ],
		],
		[
			'endpoint' => 'settings/(?P<provider_id>[a-zA-Z_-]+)',
			'method'   => 'post',
			'callback' => [ __CLASS__, 'post_providers_with_id_callback' ],
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	// region settings 相關

	/**
	 * 取得設定
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_settings_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$gateways = GatewayUtils::get_gateways(false, true);

		return new \WP_REST_Response(
			[
				'code'    => 'get_settings_success',
				'message' => '取得設定成功',
				'data'    => [
					'gateways'  => $gateways,
					'invoices'  => ProviderRegister::get_registered_provider_dtos(),
					'logistics' => LogisticsProviderRegister::get_registered_provider_dtos(),
				],
			],
			200
		);
	}


	/**
	 * 開關服務
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 * @throws \Exception 如果 gateway_key 無效或其他錯誤
	 */
	public static function toggle_providers_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$provider_id = (string) $request['provider_id'];
		ProviderUtils::toggle( $provider_id);
		$toggle_text = ProviderUtils::is_enabled( $provider_id) ? '啟用' : '禁用';

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => "{$provider_id} {$toggle_text}成功",
				'data'    => $provider_id,
			],
			200
		);
	}

	/**
	 * 取得設定
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_providers_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$provider_id = (string) $request['provider_id'];
		$provider    = ProviderUtils::get_provider( $provider_id);
		if (!$provider) {
			throw new \Exception("Can't find Provider with provider_id: {$provider_id}");
		}
		/** @var callable $get_settings */
		$get_settings = [ $provider, 'get_settings' ];
		/** @var array<string, mixed> $settings */
		$settings = \call_user_func($get_settings);

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => "取得 {$provider->method_title} 設定成功",
				'data'    => $settings,
			],
			200
		);
	}

	/**
	 * 更新設定
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public static function post_providers_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$provider_id = (string) $request['provider_id'];

		$params = $request->get_params();
		$params = WP::sanitize_text_field_deep($params, false );

		ProviderUtils::update_option( $provider_id, $params);

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => '儲存成功',
				'data'    => ProviderUtils::get_option( $provider_id),
			],
			200
		);
	}

	// endregion settings 相關
}
