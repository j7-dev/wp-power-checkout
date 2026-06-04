<?php
/**
 * SettingApiService GET /settings 的 data.logistics 整合測試（階段四 — Red Gate）
 *
 * 驗證 SettingApiService line ~72 已從 'logistics' => [] 接上
 * Logistics\ProviderRegister::get_registered_provider_dtos()：
 *   GET /settings 的 data.logistics 應含 ecpay_logistics 摘要。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter SettingsGetAllLogisticsTest" 2>&1; echo "EXIT=$?"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\EcpayLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Settings\Services\SettingApiService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * GET /settings data.logistics 測試類別
 *
 * @group integration
 * @group logistics
 * @group settings
 */
final class SettingsGetAllLogisticsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		EcpayLogisticsSettingsDTO::reset();
		\putenv( 'API_MODE=mock' );
	}

	public function tear_down(): void {
		\putenv( 'API_MODE' );
		EcpayLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( EcpayLogisticsProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_GET_settings的data含logistics鍵(): void {
		// Given
		$request = new \WP_REST_Request( 'GET', '/power-checkout/v1/settings' );

		// When
		$response = SettingApiService::get_settings_callback( $request );

		// Then
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'logistics', $data['data'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_GET_settings的data_logistics含ecpay_logistics摘要(): void {
		// Given: 啟用 ecpay_logistics
		$this->enable_provider( EcpayLogisticsProvider::ID, [ 'mode' => 'test' ] );
		EcpayLogisticsSettingsDTO::reset();

		$request = new \WP_REST_Request( 'GET', '/power-checkout/v1/settings' );

		// When
		$response = SettingApiService::get_settings_callback( $request );

		// Then: data.logistics 應含 ecpay_logistics
		$logistics = $response->get_data()['data']['logistics'];
		$this->assertArrayHasKey( EcpayLogisticsProvider::ID, $logistics );

		$dto = $logistics[ EcpayLogisticsProvider::ID ];
		// 摘要含 id（DTO 物件或陣列皆可接受）
		$id = is_object( $dto ) ? $dto->id : ( $dto['id'] ?? '' );
		$this->assertSame( EcpayLogisticsProvider::ID, $id );
	}
}
