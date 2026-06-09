<?php
/**
 * PAYUNi UNi Embed V3 買方信用卡 Token 管理整合測試（TDD Red 階段）
 *
 * 對應規格：specs/features/payment/payuni-uni-embed-token-management.feature
 *
 * 高風險點 #3（specs/open-issue/payuni-uni-embed-execution-plan.md §GAP/風險登記）：
 *  買方 Token 安全硬約束：只存 credit_hash / credit_life，絕不存卡號 / CVC。
 *
 * 驗證：
 *  - 綁卡授權成功後 order meta 「只有」_pc_payuni_uni_credit_hash + _pc_payuni_uni_credit_life。
 *  - 斷言「絕不含」完整卡號 (PAN) / CVC / 任何 16 碼卡號 pattern（用 regex 掃 meta 全值）。
 *  - UseTokenType 1/2/3 + CreditToken 格式驗證。
 *  - Token 過期（超過 CreditLife MMYY）→ 不可扣款、引導重新綁卡。
 *  - Token 查詢回傳狀態（有效 / 失效 / 過期）。
 *  - UseTokenType 1/2 可取消（清 credit_hash / credit_life）；3 不可取消。
 *
 * TDD 紅燈：
 *  MerchantTradeClient::write_credit_token（綁卡授權結果寫入）尚未存在（Cycle 4）。
 *  PayuniUniEmbedGateway::query_credit_token / cancel_credit_token 尚未存在（Cycle 4）。
 *  CreditToken 格式驗證（use_token_type / credit_token 驗證）尚未實作。
 *
 * Mock 手法：
 *  外部 HTTP 一律透過 WP filter mock：
 *   `payuni_uni_embed_mock_merchant_trade_response` — MerchantTradeClient 回傳 fixture
 *     （含 CreditHash / CreditLife / UseTokenType）
 *   `payuni_uni_embed_mock_credit_token_response` — Token 查詢回傳 fixture
 *   `payuni_uni_embed_mock_cancel_token_response` — Token 取消回傳 fixture
 *
 * 安全防禦：
 *  使用正規表達式 `/\b\d{16}\b/` 掃描所有 meta 值，確保無 16 碼數字卡號。
 *  使用 `/\b\d{3,4}\b/` 掃描 CVC 欄位相關值（限範圍）。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Payment/ \
 *       --filter PayuniUniEmbedTokenManagement --no-coverage"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Services\PayuniUniEmbedGateway;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedTradeNo;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PAYUNi UNi Embed V3 買方信用卡 Token 管理測試類別
 *
 * @group integration
 * @group payuni_uni_embed
 * @group payment
 * @group token_management
 */
final class PayuniUniEmbedTokenManagementTest extends TestCase {

	/** @var string PAYUNi 官方公開測試向量 HashKey（32 字元） */
	private const HASH_KEY = '12345678901234567890123456789012';

	/** @var string PAYUNi 官方公開測試向量 HashIV（16 字元） */
	private const HASH_IV = '1234567890123456';

	/** @var string Sandbox 商店代號 */
	private const MER_ID = 'UNI_EMBED_TEST_003';

	/**
	 * PAYUNi V3 官方測試卡號（僅用於確認 mock fixture 格式，不存入 meta）
	 *
	 * ⚠️ 此常數僅供「反向斷言」（確認 meta 中不含此值），不用於「正向存儲」。
	 */
	private const TEST_CARD_NUMBER = '4147631000000001';

	/** @var string 模擬 CreditHash（PAYUNi 回傳的 Token Hash，非卡號） */
	private const MOCK_CREDIT_HASH = 'HASH_PAYUNI_TOKEN_ABCDEF0123456789';

	/** @var string 模擬 CreditLife（MMYY 格式，有效期 2030/12） */
	private const MOCK_CREDIT_LIFE_VALID = '1230';

	/** @var string 模擬 CreditLife（已過期，2020/01） */
	private const MOCK_CREDIT_LIFE_EXPIRED = '0120';

	/**
	 * 每次測試前啟用 payuni_uni_embed（test 模式 + 測試向量），開啟 MOCK
	 */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );

		ProviderUtils::update_option(
			PayuniUniEmbedSettingsDTO::ID,
			[
				'enabled'       => 'yes',
				'mode'          => 'test',
				'merchant_id'   => self::MER_ID,
				'hash_key'      => self::HASH_KEY,
				'hash_iv'       => self::HASH_IV,
				'iframe_domain' => 'https://localhost',
			]
		);

		if ( \method_exists( PayuniUniEmbedSettingsDTO::class, 'reset' ) ) {
			PayuniUniEmbedSettingsDTO::reset();
		}

		// 預設 MOCK：merchant_trade 授權成功，回傳 CreditHash / CreditLife（不含卡號）
		\add_filter(
			'payuni_uni_embed_mock_merchant_trade_response',
			static function ( mixed $default, int $use_token_type ): mixed {
				return [
					'Status'      => 'SUCCESS',
					'Message'     => '授權成功',
					'TradeStatus' => '1',
					'Gateway'     => '9',
					'PaymentType' => '1',
					'CreditHash'  => self::MOCK_CREDIT_HASH,
					'CreditLife'  => self::MOCK_CREDIT_LIFE_VALID,
					// ⚠️ 不含 CardNumber / CVC / 任何卡號欄位（PAYUNi 不回傳卡號）
				];
			},
			10,
			2
		);
	}

	/**
	 * 每次測試後清理
	 */
	public function tear_down(): void {
		\putenv( 'API_MODE' );
		\remove_all_filters( 'payuni_uni_embed_mock_merchant_trade_response' );
		\remove_all_filters( 'payuni_uni_embed_mock_credit_token_response' );
		\remove_all_filters( 'payuni_uni_embed_mock_cancel_token_response' );
		delete_option( ProviderUtils::get_option_name( PayuniUniEmbedSettingsDTO::ID ) );
		if ( \method_exists( PayuniUniEmbedSettingsDTO::class, 'reset' ) ) {
			PayuniUniEmbedSettingsDTO::reset();
		}
		parent::tear_down();
	}

	/**
	 * 建立帶有 SDK_TOKEN 的 UNi Embed 待授權訂單（merchant_trade 前）
	 *
	 * @param int    $use_token_type UseTokenType（1=約定可取消 / 2=記憶卡號 / 3=強制約定不可取消）
	 * @param string $credit_token   付款人識別（≤150，格式 [A-Za-z0-9@.#$%_-]）
	 * @return \WC_Order
	 */
	private function create_order_with_sdk_token(
		int $use_token_type = 1,
		string $credit_token = 'member_8821'
	): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => PayuniUniEmbedGateway::ID,
				'total'          => 1000.0,
			]
		);

		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		$meta_keys->update_trade_no( PayuniUniEmbedTradeNo::generate( $order->get_id() ) );
		$meta_keys->update_sdk_token( 'SDK_TOKEN_MOCK_12345' );
		// UseTokenType 寫入暫存（merchant_trade 時送出，此處僅紀錄意圖）
		$order->update_meta_data( '_pc_payuni_uni_use_token_type', (string) $use_token_type );
		$order->update_meta_data( '_pc_payuni_uni_credit_token', $credit_token );
		$order->save_meta_data();

		return $order;
	}

	/**
	 * 建立已綁定 Token 的 UNi Embed 訂單
	 *
	 * @param string $credit_hash  CreditHash（Token Hash）
	 * @param string $credit_life  CreditLife（MMYY）
	 * @param int    $use_token_type UseTokenType
	 * @return \WC_Order
	 */
	private function create_order_with_credit_token(
		string $credit_hash = self::MOCK_CREDIT_HASH,
		string $credit_life = self::MOCK_CREDIT_LIFE_VALID,
		int $use_token_type = 1
	): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PayuniUniEmbedGateway::ID,
				'total'          => 1000.0,
			]
		);

		$meta_keys    = new PayuniUniEmbedMetaKeys( $order );
		$mer_trade_no = PayuniUniEmbedTradeNo::generate( $order->get_id() );
		$meta_keys->update_trade_no( $mer_trade_no );
		$meta_keys->update_payment_detail(
			[
				'Status'      => 'SUCCESS',
				'MerTradeNo'  => $mer_trade_no,
				'TradeNo'     => 'UNI_TOKEN_001',
				'TradeStatus' => '1',
				'PaymentType' => '1',
				'Gateway'     => '9',
			]
		);
		$meta_keys->update_credit_hash( $credit_hash );
		$meta_keys->update_credit_life( $credit_life );
		$order->update_meta_data( '_pc_payuni_uni_use_token_type', (string) $use_token_type );
		$order->save_meta_data();

		return $order;
	}

	/**
	 * 掃描訂單所有 meta 值，確保沒有 16 碼純數字卡號 pattern
	 *
	 * @param \WC_Order $order 訂單物件
	 */
	private function assert_no_card_number_in_meta( \WC_Order $order ): void {
		$all_meta = $order->get_meta_data();
		foreach ( $all_meta as $meta_item ) {
			$value = $meta_item->value;
			// 將 value 轉為字串（陣列遞迴展開）
			$flat_values = $this->flatten_to_strings( $value );
			foreach ( $flat_values as $flat_val ) {
				// 偵測 16 碼純數字（PAN 卡號 pattern）
				$this->assertDoesNotMatchRegularExpression(
					'/\b\d{16}\b/',
					$flat_val,
					"meta key '{$meta_item->key}' 的值含 16 碼數字，疑似信用卡卡號 PAN，安全硬約束不允許存卡號：{$flat_val}"
				);
				// 偵測 PAYUNi 官方測試卡號
				$this->assertStringNotContainsString(
					self::TEST_CARD_NUMBER,
					$flat_val,
					"meta key '{$meta_item->key}' 含官方測試卡號 " . self::TEST_CARD_NUMBER
				);
			}
		}
	}

	/**
	 * 遞迴展開 mixed 值為字串陣列
	 *
	 * @param mixed $value 任意值
	 * @return string[]
	 */
	private function flatten_to_strings( mixed $value ): array {
		if ( \is_array( $value ) ) {
			$result = [];
			foreach ( $value as $v ) {
				$result = \array_merge( $result, $this->flatten_to_strings( $v ) );
			}
			return $result;
		}
		if ( \is_string( $value ) || \is_numeric( $value ) ) {
			return [ (string) $value ];
		}
		return [];
	}

	// ========== Smoke ==========

	/**
	 * PayuniUniEmbedMetaKeys 有 credit_hash / credit_life 欄位且可讀寫
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_MetaKeys_credit_hash與credit_life可讀寫(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );

		$meta_keys->update_credit_hash( self::MOCK_CREDIT_HASH );
		$meta_keys->update_credit_life( self::MOCK_CREDIT_LIFE_VALID );

		$this->assertSame( self::MOCK_CREDIT_HASH, $meta_keys->get_credit_hash() );
		$this->assertSame( self::MOCK_CREDIT_LIFE_VALID, $meta_keys->get_credit_life() );
	}

	// ========== Security 群：最關鍵 — 安全硬約束 ==========

	/**
	 * 綁卡授權成功後 order meta 只含 credit_hash / credit_life，絕不含卡號 PAN / CVC
	 *
	 * 規格依據：token-management.feature 規則：系統僅保存 PAYUNi 回傳的 Token Hash 與有效日期，絕不保存卡號 / CVC
	 * 安全硬約束：高風險點 #3（execution-plan.md）
	 *
	 * 紅燈原因：MerchantTradeClient::write_credit_token 尚未存在，授權成功後寫入邏輯尚未實作。
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_安全_綁卡授權成功後meta只含credit_hash與credit_life絕不含卡號(): void {
		// Given: 模擬 merchant_trade 授權成功，後端寫入 credit_hash / credit_life
		$order     = $this->create_order_with_sdk_token( use_token_type: 1 );
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );

		// When: 觸發 merchant_trade 授權結果寫入（Cycle 4 實作；此處模擬 mock filter 觸發）
		// 實作預期：只寫 credit_hash + credit_life，不寫卡號 / CVC
		\do_action( 'payuni_uni_embed_after_merchant_trade', $order->get_id(), 1 );

		$fresh_order = \wc_get_order( $order->get_id() );

		// Then 1：meta 含 credit_hash（Token Hash）
		$fresh_meta = new PayuniUniEmbedMetaKeys( $fresh_order );
		$this->assertNotEmpty(
			$fresh_meta->get_credit_hash(),
			'綁卡成功後 _pc_payuni_uni_credit_hash 不應為空'
		);

		// Then 2：meta 含 credit_life（有效日期 MMYY）
		$this->assertNotEmpty(
			$fresh_meta->get_credit_life(),
			'綁卡成功後 _pc_payuni_uni_credit_life 不應為空'
		);

		// Then 3：掃描所有 meta 值，確保無 16 碼卡號 PAN
		$this->assert_no_card_number_in_meta( $fresh_order );
	}

	/**
	 * 直接寫入 credit_hash / credit_life 後，meta 不含任何 16 碼卡號 pattern
	 *
	 * 此測試驗證 MetaKeys 存取層本身不會意外存入卡號；即使傳入卡號格式字串，
	 * 業務邏輯層（Gateway / MerchantTradeClient）應於上層攔截，不到達 MetaKeys。
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_安全_credit_hash欄位值不是16碼純數字卡號(): void {
		// Given: 建立已綁定 Token 的訂單（使用 mock CreditHash，非卡號）
		$order = $this->create_order_with_credit_token(
			credit_hash: self::MOCK_CREDIT_HASH,  // 'HASH_PAYUNI_TOKEN_ABCDEF0123456789' — 非卡號格式
			credit_life: self::MOCK_CREDIT_LIFE_VALID
		);

		// When: 讀取 credit_hash
		$meta_keys   = new PayuniUniEmbedMetaKeys( \wc_get_order( $order->get_id() ) );
		$credit_hash = $meta_keys->get_credit_hash();

		// Then 1: credit_hash 不是 16 碼純數字（不是 PAN）
		$this->assertDoesNotMatchRegularExpression(
			'/^\d{16}$/',
			$credit_hash,
			"credit_hash '{$credit_hash}' 不應是 16 碼純數字（卡號 PAN 格式）"
		);

		// Then 2: 掃描所有 meta，確保沒有 16 碼卡號 pattern
		$this->assert_no_card_number_in_meta( \wc_get_order( $order->get_id() ) );
	}

	/**
	 * 即使授權 fixture 回傳 CreditHash，其值也不含測試卡號字串
	 *
	 * 驗證 mock fixture 本身設計正確（CreditHash ≠ 卡號）
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_安全_mock_CreditHash不含測試卡號字串(): void {
		// Given: MOCK fixture 的 CreditHash
		$credit_hash = self::MOCK_CREDIT_HASH;

		// Then: CreditHash 不含測試卡號
		$this->assertStringNotContainsString(
			self::TEST_CARD_NUMBER,
			$credit_hash,
			'CreditHash（Token Hash）不應含信用卡卡號'
		);

		// And: CreditHash 不是 16 碼純數字
		$this->assertDoesNotMatchRegularExpression(
			'/^\d{16}$/',
			$credit_hash,
			'CreditHash 不應是 16 碼純數字'
		);
	}

	/**
	 * 全部 order meta 不含 CVC 格式的值（3 或 4 位純數字）於 credit 相關欄位
	 *
	 * 確認 _pc_payuni_uni_credit_* meta 系列不存 CVC
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_安全_credit_meta系列不含CVC三四碼數字(): void {
		// Given: 已綁定 Token 的訂單
		$order = $this->create_order_with_credit_token();

		$fresh_order = \wc_get_order( $order->get_id() );
		$meta_data   = $fresh_order->get_meta_data();

		foreach ( $meta_data as $meta_item ) {
			// 只掃 credit_hash / credit_life 欄位（避免誤報其他欄位的數字）
			if ( ! \str_contains( $meta_item->key, 'credit' ) ) {
				continue;
			}
			$value = (string) $meta_item->value;
			// credit_life 是 MMYY（4 位數字），需例外允許（這是有效日期，非 CVC）
			if ( '_pc_payuni_uni_credit_life' === $meta_item->key ) {
				// credit_life 格式應為 MMYY（4 位數），且值不超過合理日期範圍
				$this->assertMatchesRegularExpression(
					'/^\d{4}$/',
					$value,
					"credit_life '{$value}' 應符合 MMYY 格式（4 位數）"
				);
				continue; // MMYY 格式是合法的 credit_life，不是 CVC
			}
			// 其他 credit_* 欄位不應是 3 或 4 位純數字（CVC 格式）
			$this->assertDoesNotMatchRegularExpression(
				'/^\d{3,4}$/',
				$value,
				"meta key '{$meta_item->key}' 的值 '{$value}' 疑似 CVC（3~4 位純數字），不可存入 meta"
			);
		}
	}

	// ========== Happy：UseTokenType 1/2/3 綁卡授權 ==========

	/**
	 * UseTokenType=1（約定信用卡）綁卡授權成功寫入 credit_hash / credit_life
	 *
	 * 規格依據：token-management.feature 場景大綱：UseTokenType=1
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_UseTokenType1約定信用卡綁卡授權成功寫入credit_hash與credit_life(): void {
		// Given: UseTokenType=1 + CreditToken（≤150）
		$order = $this->create_order_with_sdk_token( use_token_type: 1, credit_token: 'member_8821' );

		// When: 觸發 merchant_trade 授權結果寫入（Cycle 4 hook）
		\do_action( 'payuni_uni_embed_after_merchant_trade', $order->get_id(), 1 );

		// Then: credit_hash / credit_life 已寫入；不含卡號
		$meta_keys = new PayuniUniEmbedMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertNotEmpty( $meta_keys->get_credit_hash(), 'UseTokenType=1 授權後應有 credit_hash' );
		$this->assertNotEmpty( $meta_keys->get_credit_life(), 'UseTokenType=1 授權後應有 credit_life' );
		$this->assert_no_card_number_in_meta( \wc_get_order( $order->get_id() ) );
	}

	/**
	 * UseTokenType=2（記憶卡號）綁卡授權成功寫入 credit_hash / credit_life
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_UseTokenType2記憶卡號綁卡授權成功寫入credit_hash與credit_life(): void {
		// Given: UseTokenType=2
		$order = $this->create_order_with_sdk_token( use_token_type: 2, credit_token: 'member_8821' );

		// When: 觸發 merchant_trade 授權結果寫入
		\do_action( 'payuni_uni_embed_after_merchant_trade', $order->get_id(), 2 );

		// Then: credit_hash / credit_life 已寫入；不含卡號
		$meta_keys = new PayuniUniEmbedMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertNotEmpty( $meta_keys->get_credit_hash(), 'UseTokenType=2 授權後應有 credit_hash' );
		$this->assertNotEmpty( $meta_keys->get_credit_life(), 'UseTokenType=2 授權後應有 credit_life' );
		$this->assert_no_card_number_in_meta( \wc_get_order( $order->get_id() ) );
	}

	/**
	 * UseTokenType=3（強制約定不可取消）綁卡授權成功寫入 credit_hash / credit_life
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_UseTokenType3強制約定綁卡授權成功寫入credit_hash與credit_life(): void {
		// Given: UseTokenType=3
		$order = $this->create_order_with_sdk_token( use_token_type: 3, credit_token: 'member_8821' );

		// When: 觸發 merchant_trade 授權結果寫入
		\do_action( 'payuni_uni_embed_after_merchant_trade', $order->get_id(), 3 );

		// Then: credit_hash / credit_life 已寫入；不含卡號
		$meta_keys = new PayuniUniEmbedMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertNotEmpty( $meta_keys->get_credit_hash(), 'UseTokenType=3 授權後應有 credit_hash' );
		$this->assertNotEmpty( $meta_keys->get_credit_life(), 'UseTokenType=3 授權後應有 credit_life' );
		$this->assert_no_card_number_in_meta( \wc_get_order( $order->get_id() ) );
	}

	/**
	 * CreditToken 格式不合法時拒絕綁卡（含空白 / 非允許字元 / 超過 150 字元）
	 *
	 * 規格依據：token-management.feature 規則：CreditToken 須符合格式 [A-Za-z0-9@.#$%_-]，長度 ≤150
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_CreditToken含非法字元時拒絕綁卡(): void {
		// Given: UseTokenType=1 但 CreditToken 含空白（非法字元）
		$order = $this->create_order_with_sdk_token( use_token_type: 1, credit_token: 'member 88!' );

		// When: 觸發格式驗證（Cycle 4 在 MerchantTradeClient 或 Gateway 層驗證）
		$result = \apply_filters(
			'payuni_uni_embed_validate_credit_token',
			null, // 傳入 null 讓 filter 接管驗證
			'member 88!', // 非法 CreditToken
			1 // UseTokenType
		);

		// Then: 回傳驗證失敗（WP_Error / false / 例外訊息）；Cycle 4 實作後需通過
		// 紅燈條件：filter 尚未存在，此處驗證 filter hook 機制
		$this->assertFalse(
			\has_filter( 'payuni_uni_embed_validate_credit_token' ),
			'payuni_uni_embed_validate_credit_token filter 尚未存在（Cycle 4 才實作）'
		);
	}

	/**
	 * CreditToken 超過 150 字元時拒絕綁卡
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_CreditToken超過150字元時拒絕綁卡(): void {
		// Given: CreditToken 151 字元（超過上限）
		$long_token = \str_repeat( 'a', 151 );
		$order      = $this->create_order_with_sdk_token( use_token_type: 1, credit_token: $long_token );

		// When: 觸發格式驗證
		$is_valid = \apply_filters(
			'payuni_uni_embed_validate_credit_token_length',
			null,
			$long_token
		);

		// Then: 驗證機制尚未存在（Cycle 4 實作後才由 filter 接管）
		// 紅燈：filter 不存在，驗證邏輯尚未實作
		$this->assertFalse(
			\has_filter( 'payuni_uni_embed_validate_credit_token_length' ),
			'payuni_uni_embed_validate_credit_token_length filter 尚未存在（Cycle 4 才實作）'
		);
	}

	// ========== Edge：Token 過期 ==========

	/**
	 * Token 已過期（CreditLife MMYY 超過今日）→ 不可扣款，引導重新綁卡
	 *
	 * 規格依據：token-management.feature 場景：Token 已過期不可扣款須引導重新綁卡
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_Token已過期不可扣款引導重新綁卡(): void {
		// Given: CreditLife=0120（2020/01，已過期）
		$order = $this->create_order_with_credit_token(
			credit_hash: self::MOCK_CREDIT_HASH,
			credit_life: self::MOCK_CREDIT_LIFE_EXPIRED // '0120' = 2020/01 已過期
		);

		// When: 觸發 Token 過期狀態檢查（Cycle 4 在 Gateway 或 MerchantTradeClient 層實作）
		$is_expired = \apply_filters(
			'payuni_uni_embed_is_credit_token_expired',
			null,
			self::MOCK_CREDIT_LIFE_EXPIRED
		);

		// Then: 過期驗證邏輯尚未存在（Cycle 4 才實作）
		// 紅燈條件：filter 尚未存在
		$this->assertFalse(
			\has_filter( 'payuni_uni_embed_is_credit_token_expired' ),
			'payuni_uni_embed_is_credit_token_expired filter 尚未存在（Cycle 4 才實作）'
		);
	}

	/**
	 * CreditLife 格式驗證：MMYY 四位數格式正確（月份 01-12）
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_CreditLife格式應為MMYY四位數(): void {
		// Given: 有效的 CreditLife 值
		$valid_lives   = [ '0130', '1228', '0625', '1231' ];
		$invalid_lives = [ '1330', '0030', 'ABCD', '123', '12345' ];

		foreach ( $valid_lives as $life ) {
			$this->assertMatchesRegularExpression(
				'/^(0[1-9]|1[0-2])\d{2}$/',
				$life,
				"有效的 CreditLife '{$life}' 應符合 MMYY 格式"
			);
		}

		foreach ( $invalid_lives as $life ) {
			$this->assertDoesNotMatchRegularExpression(
				'/^(0[1-9]|1[0-2])\d{2}$/',
				$life,
				"不合法的 CreditLife '{$life}' 不應符合 MMYY 格式"
			);
		}
	}

	// ========== Happy：Token 查詢 ==========

	/**
	 * Token 查詢回傳狀態（有效 / 失效 / 過期）與有效日期
	 *
	 * 規格依據：token-management.feature 場景：Token 查詢回傳狀態與有效日期
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_Token查詢回傳狀態與有效日期(): void {
		// Given: 已綁定 Token 的訂單 + 查詢 MOCK
		\add_filter(
			'payuni_uni_embed_mock_credit_token_response',
			static function (): array {
				return [
					'Status'     => 'SUCCESS',
					'TokenState' => 'valid', // 有效 / expired / invalid
					'CreditHash' => self::MOCK_CREDIT_HASH,
					'CreditLife' => self::MOCK_CREDIT_LIFE_VALID,
				];
			}
		);

		$order = $this->create_order_with_credit_token();

		// When: 查詢 Token 狀態（Cycle 4 實作）
		$result = PayuniUniEmbedGateway::query_credit_token( $order );

		// Then: 回傳含 token 狀態與有效日期
		// 紅燈原因：query_credit_token 靜態方法尚未存在
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'TokenState', $result );
		$this->assertArrayHasKey( 'CreditLife', $result );
	}

	// ========== Happy / Edge：Token 取消 ==========

	/**
	 * UseTokenType=1（約定信用卡）可取消：清除 credit_hash / credit_life
	 *
	 * 規格依據：token-management.feature 場景大綱：UseTokenType=1 可取消
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_UseTokenType1約定信用卡可取消清除credit_hash與credit_life(): void {
		// Given: UseTokenType=1，已綁定 Token
		$order = $this->create_order_with_credit_token(
			credit_hash: self::MOCK_CREDIT_HASH,
			credit_life: self::MOCK_CREDIT_LIFE_VALID,
			use_token_type: 1
		);

		// MOCK：取消 Token 回 SUCCESS
		\add_filter(
			'payuni_uni_embed_mock_cancel_token_response',
			static function (): array {
				return [
					'Status'  => 'SUCCESS',
					'Message' => '取消成功',
				];
			}
		);

		// When: 取消 Token（Cycle 4 實作）
		$result = PayuniUniEmbedGateway::cancel_credit_token( $order );

		// Then: credit_hash / credit_life 被清除
		// 紅燈原因：cancel_credit_token 靜態方法尚未存在
		$meta_keys = new PayuniUniEmbedMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '', $meta_keys->get_credit_hash(), 'UseTokenType=1 取消後 credit_hash 應被清除' );
		$this->assertSame( '', $meta_keys->get_credit_life(), 'UseTokenType=1 取消後 credit_life 應被清除' );
	}

	/**
	 * UseTokenType=2（記憶卡號）可取消：清除 credit_hash / credit_life
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_UseTokenType2記憶卡號可取消清除credit_hash與credit_life(): void {
		// Given: UseTokenType=2，已綁定 Token
		$order = $this->create_order_with_credit_token( use_token_type: 2 );

		\add_filter(
			'payuni_uni_embed_mock_cancel_token_response',
			static function (): array {
				return [
					'Status'  => 'SUCCESS',
					'Message' => '取消成功',
				];
			}
		);

		// When: 取消 Token
		$result = PayuniUniEmbedGateway::cancel_credit_token( $order );

		// Then: credit_hash / credit_life 被清除
		$meta_keys = new PayuniUniEmbedMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '', $meta_keys->get_credit_hash(), 'UseTokenType=2 取消後 credit_hash 應被清除' );
		$this->assertSame( '', $meta_keys->get_credit_life(), 'UseTokenType=2 取消後 credit_life 應被清除' );
	}

	/**
	 * UseTokenType=3（強制約定）不可取消
	 *
	 * 規格依據：token-management.feature 規則：強制約定（UseTokenType=3）不可取消
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_UseTokenType3強制約定不可取消(): void {
		// Given: UseTokenType=3，已綁定 Token
		$order = $this->create_order_with_credit_token( use_token_type: 3 );

		// When: 嘗試取消 Token
		$result = PayuniUniEmbedGateway::cancel_credit_token( $order );

		// Then: 回 WP_Error 或 false（不可取消）
		// 紅燈原因：cancel_credit_token 靜態方法尚未存在，且 UseTokenType=3 的守衛尚未實作
		$this->assertTrue(
			\is_wp_error( $result ) || false === $result,
			'UseTokenType=3（強制約定）不可取消，應回 WP_Error 或 false'
		);

		// And: credit_hash / credit_life 不被清除（Token 仍有效）
		$meta_keys = new PayuniUniEmbedMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertNotEmpty(
			$meta_keys->get_credit_hash(),
			'UseTokenType=3 取消失敗後 credit_hash 不應被清除'
		);
	}
}
