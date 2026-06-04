<?php
/**
 * 綠界全方位物流 v2 ApiClient 整合測試（階段二）
 *
 * 驗證：
 *  1. build_envelope：RqHeader 含 Revision="1.0.0"（R3）＋即時 time()（R1，落 ±幾秒內證明非快取/硬編）。
 *  2. parse_response 雙層整數檢查：外層 TransCode（int）===1 否則 throw「傳輸層」；
 *     解密後內層 RtnCode（int）===1 否則 throw「業務層」；型別陷阱（TransCode=0 整數、
 *     RtnCode='1' 字串）一律 (int) 比對（R4）。
 *  3. AES round-trip：以複用的 Ecpg AesCrypto 對 envelope Data 加解密還原（含中文 / 特殊字元）。
 *  4. MOCK 模式（API_MODE=mock）：選店 / 建單 / 查詢 / 取消 回固定 fixture，不打真 API。
 *
 * 對應計畫第二階段步驟 5 / 6，風險 R1 / R3 / R4。
 *
 * 真 API 呼叫以 API_MODE=mock 攔截；雙層檢查以 parse_response() 搭配真 AES 加密 Data 直接驗證，不需 HTTP。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/07-logistics-allinone.md
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\CreateShipmentParams;
use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\EcpayLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\StoreSelectionParams;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Http\LogisticsApiClient;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界全方位物流 v2 ApiClient 測試類別
 *
 * @group integration
 * @group logistics
 */
final class LogisticsApiClientTest extends TestCase {

	/** @var string B2C 全方位物流公開測試帳號 MerchantID */
	private const B2C_MERCHANT_ID = '2000132';

	/** @var string B2C 全方位物流公開測試帳號 HashKey */
	private const B2C_HASH_KEY = '5294y06JbISpM5x9';

	/** @var string B2C 全方位物流公開測試帳號 HashIV */
	private const B2C_HASH_IV = 'v77hoKGq4kWxNNIS';

	/** @var string C2C 店到店公開測試帳號 MerchantID */
	private const C2C_MERCHANT_ID = '2000933';

	/** 每次測試前：以 test 模式啟用 B2C 物流帳號並重置單例 */
	protected function configure_dependencies(): void {
		ProviderUtils::update_option(
			EcpayLogisticsProvider::ID,
			[
				'enabled'      => 'yes',
				'mode'         => 'test',
				'account_type' => 'b2c',
			]
		);
		EcpayLogisticsSettingsDTO::reset();
	}

	/** 每次測試後清理設定與環境變數 */
	public function tear_down(): void {
		\putenv( 'API_MODE' );
		\delete_option( ProviderUtils::get_option_name( EcpayLogisticsProvider::ID ) );
		EcpayLogisticsSettingsDTO::reset();
		parent::tear_down();
	}

	/**
	 * 建立 B2C 物流 ApiClient（account_type=b2c）
	 *
	 * @param \WC_Order|null $order 訂單（null 時自建）
	 * @return LogisticsApiClient
	 */
	private function b2c_client( ?\WC_Order $order = null ): LogisticsApiClient {
		$order ??= $this->create_wc_order( [ 'total' => 100 ] );
		return new LogisticsApiClient( $order );
	}

	/** @return AesCrypto B2C 帳號的加解密器（與 client 內部一致） */
	private function b2c_crypto(): AesCrypto {
		return new AesCrypto( self::B2C_HASH_KEY, self::B2C_HASH_IV );
	}

	// ========== build_envelope：RqHeader Revision + 即時 Timestamp（R1 / R3） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_build_envelope的RqHeader含Revision固定1_0_0(): void {
		// Given
		$client = $this->b2c_client();

		// When
		$envelope = $client->build_envelope( [ 'TempLogisticsID' => '0' ] );

		// Then: RqHeader.Revision 必為固定 "1.0.0"（R3，全方位物流 v2 必填）
		$this->assertArrayHasKey( 'RqHeader', $envelope );
		$this->assertIsArray( $envelope['RqHeader'] );
		$this->assertSame( '1.0.0', $envelope['RqHeader']['Revision'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_build_envelope的Timestamp為即時time非快取或硬編(): void {
		// Given
		$client = $this->b2c_client();

		// When: 取得當下 time() 前後界限，build_envelope 內部須即時呼叫 time()
		$before   = \time();
		$envelope = $client->build_envelope( [ 'TempLogisticsID' => '0' ] );
		$after    = \time();

		// Then: Timestamp 落在 [$before, $after]（±幾秒內），證明非預先計算 / 硬編
		$this->assertArrayHasKey( 'Timestamp', $envelope['RqHeader'] );
		$timestamp = $envelope['RqHeader']['Timestamp'];
		$this->assertIsInt( $timestamp );
		$this->assertGreaterThanOrEqual( $before, $timestamp );
		$this->assertLessThanOrEqual( $after, $timestamp );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_build_envelope外層MerchantID為啟用帳號的MerchantID(): void {
		// Given: account_type=b2c
		$client = $this->b2c_client();

		// When
		$envelope = $client->build_envelope( [ 'TempLogisticsID' => '0' ] );

		// Then: 外層 MerchantID = B2C 帳號（R5）
		$this->assertSame( self::B2C_MERCHANT_ID, $envelope['MerchantID'] );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_連續兩次build_envelope的Timestamp不快取_隨時間更新(): void {
		// Given
		$client = $this->b2c_client();

		// When：兩次呼叫之間以 mock 的方式無法等待真實秒數，改驗證「每次都重新呼叫 time()」
		// 透過分別取兩次 envelope，皆須落在各自呼叫前後界限內（不得共用同一快取值）。
		$t0 = \time();
		$e1 = $client->build_envelope( [ 'TempLogisticsID' => '0' ] );
		$e2 = $client->build_envelope( [ 'TempLogisticsID' => '0' ] );
		$t1 = \time();

		// Then: 兩次都落在 [$t0, $t1] 內（即時 time），證明非建構期固定一次
		$this->assertGreaterThanOrEqual( $t0, $e1['RqHeader']['Timestamp'] );
		$this->assertLessThanOrEqual( $t1, $e1['RqHeader']['Timestamp'] );
		$this->assertGreaterThanOrEqual( $t0, $e2['RqHeader']['Timestamp'] );
		$this->assertLessThanOrEqual( $t1, $e2['RqHeader']['Timestamp'] );
	}

	// ========== parse_response 雙層整數檢查（R4） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_雙層皆成功時parse_response回傳解密Data(): void {
		// Given: TransCode=1（int），Data 解密後 RtnCode=1（int）
		$order  = $this->create_wc_order( [ 'total' => 100 ] );
		$client = $this->b2c_client( $order );
		$body   = [
			'TransCode' => 1,
			'TransMsg'  => 'Success',
			'Data'      => $this->b2c_crypto()->encrypt(
				[
					'RtnCode'     => 1,
					'RtnMsg'      => 'OK',
					'LogisticsID' => '1234567890',
				]
			),
		];

		// When
		$decrypted = $client->parse_response( $body );

		// Then
		$this->assertSame( 1, $decrypted['RtnCode'] );
		$this->assertSame( '1234567890', $decrypted['LogisticsID'] );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_傳輸層TransCode整數0時拋例外並記錄order_note(): void {
		// Given: TransCode=0（整數，傳輸層失敗）
		$order  = $this->create_wc_order( [ 'total' => 100 ] );
		$client = $this->b2c_client( $order );
		$body   = [
			'TransCode' => 0,
			'TransMsg'  => 'AES decrypt error',
			'Data'      => '',
		];

		// When / Then
		try {
			$client->parse_response( $body );
			$this->fail( '預期傳輸層失敗應拋例外' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( '傳輸層', $e->getMessage() );
		}
		$this->assert_order_note_contains( $order, '傳輸層' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_傳輸層TransCode字串1時視為成功_int比對(): void {
		// Given: TransCode='1'（字串，型別陷阱），須 (int) 後比對為成功
		$order  = $this->create_wc_order( [ 'total' => 100 ] );
		$client = $this->b2c_client( $order );
		$body   = [
			'TransCode' => '1',
			'TransMsg'  => 'Success',
			'Data'      => $this->b2c_crypto()->encrypt(
				[
					'RtnCode'     => 1,
					'RtnMsg'      => 'OK',
					'LogisticsID' => '999',
				]
			),
		];

		// When: 不應因字串 '1' 而誤判傳輸層失敗
		$decrypted = $client->parse_response( $body );

		// Then
		$this->assertSame( '999', $decrypted['LogisticsID'] );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_業務層RtnCode整數非1時拋例外並記錄order_note(): void {
		// Given: TransCode=1 但解密後 RtnCode=10100050（整數，業務層失敗）
		$order  = $this->create_wc_order( [ 'total' => 100 ] );
		$client = $this->b2c_client( $order );
		$body   = [
			'TransCode' => 1,
			'TransMsg'  => 'Success',
			'Data'      => $this->b2c_crypto()->encrypt(
				[
					'RtnCode' => 10100050,
					'RtnMsg'  => '暫存訂單問題',
				]
			),
		];

		// When / Then
		try {
			$client->parse_response( $body );
			$this->fail( '預期業務層失敗應拋例外' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( '業務層', $e->getMessage() );
			$this->assertStringContainsString( '10100050', $e->getMessage() );
		}
		$this->assert_order_note_contains( $order, '10100050' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_業務層RtnCode字串1時視為成功_int比對(): void {
		// Given: 解密後 RtnCode='1'（字串，型別陷阱），須 (int) 後比對為成功（R4）
		$order  = $this->create_wc_order( [ 'total' => 100 ] );
		$client = $this->b2c_client( $order );
		$body   = [
			'TransCode' => 1,
			'TransMsg'  => 'Success',
			'Data'      => $this->b2c_crypto()->encrypt(
				[
					'RtnCode'     => '1',
					'RtnMsg'      => 'OK',
					'LogisticsID' => '5566',
				]
			),
		];

		// When: 不應因字串 '1' 而誤判業務層失敗
		$decrypted = $client->parse_response( $body );

		// Then
		$this->assertSame( '5566', $decrypted['LogisticsID'] );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_業務層RtnCode字串0時拋例外_int比對(): void {
		// Given: 解密後 RtnCode='0'（字串 0，型別陷阱），須 (int) 後判定為業務層失敗
		$order  = $this->create_wc_order( [ 'total' => 100 ] );
		$client = $this->b2c_client( $order );
		$body   = [
			'TransCode' => 1,
			'TransMsg'  => 'Success',
			'Data'      => $this->b2c_crypto()->encrypt(
				[
					'RtnCode' => '0',
					'RtnMsg'  => 'failed',
				]
			),
		];

		// When / Then
		$this->expectException( \Throwable::class );
		$client->parse_response( $body );
	}

	// ========== AES round-trip（複用 Ecpg AesCrypto，含中文 / 特殊字元） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_envelope的Data以B2C憑證可解密還原原始Data(): void {
		// Given: 含中文 / 空格 / 特殊字元的 Data
		$client = $this->b2c_client();
		$data   = [
			'TempLogisticsID' => '0',
			'GoodsName'       => '測試商品 A & B ~ test',
			'SenderName'      => '王 大明',
			'SenderAddress'   => '台北市大安區測試路 1 號',
		];

		// When: 組 envelope 後以同帳號 AesCrypto 解密 Data
		$envelope  = $client->build_envelope( $data );
		$decrypted = $this->b2c_crypto()->decrypt( (string) $envelope['Data'] );

		// Then: 完全還原（含中文 / 特殊字元）
		$this->assertSame( $data, $decrypted );
	}

	// ========== MOCK 模式（不打真 API，回固定 fixture） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_MOCK模式redirect_to_logistics_selection回HTML不打真API(): void {
		// Given: MOCK 模式
		\putenv( 'API_MODE=mock' );
		$order  = $this->create_wc_order( [ 'total' => 100 ] );
		$client = $this->b2c_client( $order );
		$params = new StoreSelectionParams(
			[
				'TempLogisticsID' => '0',
				'GoodsAmount'     => 100,
				'GoodsName'       => '測試商品',
				'SenderName'      => '寄件人',
				'SenderZipCode'   => '106',
				'SenderAddress'   => '台北市大安區測試路1號',
				'ServerReplyURL'  => 'https://example.com/server',
				'ClientReplyURL'  => 'https://example.com/client',
			]
		);

		// When
		$html = $client->redirect_to_logistics_selection( $params );

		// Then: 回 HTML body（含 form / ECPay 字樣），不打真 API
		$this->assertIsString( $html );
		$this->assertNotSame( '', $html );
		$this->assertStringContainsString( '<form', $html );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_MOCK模式create_by_temp_trade回固定fixture含LogisticsID(): void {
		// Given: MOCK 模式
		\putenv( 'API_MODE=mock' );
		$order  = $this->create_wc_order( [ 'total' => 100 ] );
		$client = $this->b2c_client( $order );
		$params = new CreateShipmentParams( [ 'TempLogisticsID' => '2264' ] );

		// When
		$result = $client->create_by_temp_trade( $params );

		// Then: 業務層成功 + 含 LogisticsID
		$this->assertSame( 1, $result['RtnCode'] );
		$this->assertArrayHasKey( 'LogisticsID', $result );
		$this->assertNotSame( '', (string) $result['LogisticsID'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_MOCK模式query回固定fixture含LogisticsID與LogisticsStatus(): void {
		// Given: MOCK 模式
		\putenv( 'API_MODE=mock' );
		$order  = $this->create_wc_order( [ 'total' => 100 ] );
		$client = $this->b2c_client( $order );

		// When
		$result = $client->query( '1234567890' );

		// Then
		$this->assertSame( 1, $result['RtnCode'] );
		$this->assertSame( '1234567890', (string) $result['LogisticsID'] );
		$this->assertArrayHasKey( 'LogisticsStatus', $result );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_MOCK模式print_trade_document回HTML不打真API(): void {
		// Given: MOCK 模式
		\putenv( 'API_MODE=mock' );
		$order  = $this->create_wc_order( [ 'total' => 100 ] );
		$client = $this->b2c_client( $order );

		// When
		$html = $client->print_trade_document( [ '1769543' ], 'FAMI' );

		// Then: 回 HTML body
		$this->assertIsString( $html );
		$this->assertStringContainsString( '<', $html );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_MOCK模式cancel_c2c回固定fixture含CVSPaymentNo(): void {
		// Given: MOCK 模式 + C2C 帳號
		\putenv( 'API_MODE=mock' );
		ProviderUtils::update_option(
			EcpayLogisticsProvider::ID,
			[
				'enabled'      => 'yes',
				'mode'         => 'test',
				'account_type' => 'c2c',
			]
		);
		EcpayLogisticsSettingsDTO::reset();

		$order  = $this->create_wc_order( [ 'total' => 100 ] );
		$client = new LogisticsApiClient( $order );

		// When
		$result = $client->cancel_c2c( '1234567890', 'CVS_PAY_NO', 'CVS_VALID_NO' );

		// Then: 業務層成功，且 fixture 帶回 CVSPaymentNo（C2C 取消相關）
		$this->assertSame( 1, $result['RtnCode'] );
		$this->assertArrayHasKey( 'CVSPaymentNo', $result );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_MOCK模式create_by_temp_trade的fixture可被視為已通過雙層檢查(): void {
		// Given: MOCK 模式
		\putenv( 'API_MODE=mock' );
		$order  = $this->create_wc_order( [ 'total' => 100 ] );
		$client = $this->b2c_client( $order );
		$params = new CreateShipmentParams( [ 'TempLogisticsID' => '0' ] );

		// When: MOCK fixture 應已是「解密後 Data」格式（RtnCode 整數 1）
		$result = $client->create_by_temp_trade( $params );

		// Then: RtnCode 為整數 1（與真實解密後型別一致），TempLogisticsID 回填
		$this->assertSame( 1, $result['RtnCode'] );
	}
}
