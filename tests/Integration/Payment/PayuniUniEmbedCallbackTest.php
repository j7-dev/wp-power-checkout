<?php
/**
 * PAYUNi UNi Embed V3 NotifyURL 幕後通知回調整合測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\PayuniUniEmbedCallback
 *   - extends ApiBase；namespace = 'power-checkout/payuni'；endpoint = 'uni-embed/notify'
 *   - permission_callback: __return_true（驗章在 callback 內部完成）
 *   - 回應格式：HTTP 200（PAYUNi 只要求收到 200，不需特定 body 字串）
 *
 * 設計依據：
 *   - specs/features/payment/payuni-uni-embed-callback.feature
 *   - specs/open-issue/payuni-uni-embed-execution-plan.md §Phase 07 + 錯誤/失敗登記表 NotifyURL 列
 *   - payuni-uni-embed-v3 SKILL.md §NotifyURL 回打格式
 *   - 風格對齊：tests/Integration/Payment/PayuniCallbackTest.php（最重要藍本）
 *
 * 觸發機制（對齊 PayuniCallbackTest）：
 *   - 直接呼叫 PayuniUniEmbedCallback::instance() 取得單例
 *   - 以 WP_REST_Request::set_body_params() 模擬 Form POST body
 *   - 呼叫 post_uni_embed_notify_callback( $request ) 取得 WP_REST_Response
 *
 * 加解密工具（共用既有 PayuniCrypto，不 mock 驗章本身）：
 *   J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniCrypto
 *   - encrypt(array $params): string → EncryptInfo（hex）
 *   - hash_info(string $encrypt_info): string → HashInfo（SHA256 大寫）
 *   - verify_hash(string $encrypt_info, string $hash_info): bool（timing-safe）
 *   - decrypt(string $encrypt_info): array
 *   測試金鑰：HashKey=12345678901234567890123456789012 / HashIV=1234567890123456
 *
 * 外層 Form POST 欄位（payuni-uni-embed-v3 §NotifyURL 回打格式）：
 *   Status（SUCCESS / 其他）、MerID、Version、EncryptInfo（hex）、HashInfo（SHA256 大寫）
 *
 * 內層（EncryptInfo 解密後）欄位（UNi Embed 特有）：
 *   MerTradeNo、TradeAmt、TradeStatus（1=已付款/2=失敗/3=取消/8=待確認）
 *   Gateway（固定 9=IFrame，與 UPP 的 2 不同）、PaymentType（固定 1=信用卡）
 *
 * 回應規格：HTTP 200（PAYUNi 收到 200 不重送；無需特定 body 字串）
 *
 * ⚠️ UNi Embed 獨有差異：
 *   - 反查主鍵為 _pc_payuni_uni_trade_no（PCE 前綴），不與 UPP _pc_payuni_trade_no（PCU）混淆
 *   - 無 TradeStatus=0（取號），只有 1/2/3/8
 *   - Gateway 值=9（非 UPP 的 2）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Payment/ \
 *     --filter PayuniUniEmbed --no-coverage"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\PayuniUniEmbedCallback;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PAYUNi UNi Embed V3 NotifyURL 幕後通知回調測試類別
 *
 * @group integration
 * @group payuni_uni_embed
 * @group payment
 */
final class PayuniUniEmbedCallbackTest extends TestCase {

	// PAYUNi 官方公開測試向量金鑰（payuni-upp-v2 encryption.md §官方測試向量，UNi Embed 共用）
	private const HASH_KEY    = '12345678901234567890123456789012';
	private const HASH_IV     = '1234567890123456';
	private const MERCHANT_ID = 'UNI_TEST_MER';
	private const VERSION     = '1.2'; // UNi Embed merchant_trade 回傳版本固定 1.2

	/** @var PayuniCrypto */
	private PayuniCrypto $crypto;

	/**
	 * 每次測試前啟用 payuni_uni_embed（test 模式 + 官方測試向量金鑰）
	 * 使用 ProviderUtils::update_option 對齊 PayuniCallbackTest 的風格
	 */
	protected function configure_dependencies(): void {
		ProviderUtils::update_option(
			'payuni_uni_embed',
			[
				'enabled'     => 'yes',
				'mode'        => 'test',
				'merchant_id' => self::MERCHANT_ID,
				'hash_key'    => self::HASH_KEY,
				'hash_iv'     => self::HASH_IV,
			]
		);
		if ( \method_exists( PayuniUniEmbedSettingsDTO::class, 'reset' ) ) {
			PayuniUniEmbedSettingsDTO::reset();
		}
		// 使用共用 PayuniCrypto（AES-256-GCM，與正式驗章同源，不 mock）
		$this->crypto = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		\delete_option( ProviderUtils::get_option_name( 'payuni_uni_embed' ) );
		if ( \method_exists( PayuniUniEmbedSettingsDTO::class, 'reset' ) ) {
			PayuniUniEmbedSettingsDTO::reset();
		}
		parent::tear_down();
	}

	// =====================================================================
	// 測試輔助方法
	// =====================================================================

	/**
	 * 建立已綁 _pc_payuni_uni_trade_no 的 pending 訂單
	 *
	 * ⚠️ meta key 為 _pc_payuni_uni_trade_no（PCE 前綴），
	 * 絕不與 UPP 的 _pc_payuni_trade_no（PCU 前綴）相混。
	 *
	 * @param string $mer_trade_no 商店訂單編號（格式 PCE{order_id}）
	 * @param int    $total        訂單應收金額（元）
	 * @return \WC_Order
	 */
	private function create_uni_embed_order( string $mer_trade_no, int $total = 1000 ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => 'payuni_uni_embed',
				'total'          => $total,
			]
		);
		( new PayuniUniEmbedMetaKeys( $order ) )->update_trade_no( $mer_trade_no );
		return $order;
	}

	/**
	 * 建立有效的外層 Form POST body（含正確 HashInfo）
	 *
	 * 組裝流程（依 payuni-uni-embed-v3 §NotifyURL 驗章流程）：
	 *   1. 內層 payload → encrypt() → EncryptInfo（AES-256-GCM）
	 *   2. hash_info(EncryptInfo) → HashInfo（SHA256 大寫）
	 *   3. 外層 body：{ Status, MerID, Version, EncryptInfo, HashInfo }
	 *
	 * @param array<string, string> $inner_params 內層付款明細（解密後的 payload）
	 * @param string                $outer_status 外層 Status（預設 SUCCESS）
	 * @return array<string, string> 外層 Form POST body（可直接傳入 WP_REST_Request）
	 */
	private function build_notify_body(
		array $inner_params,
		string $outer_status = 'SUCCESS'
	): array {
		$encrypt_info = $this->crypto->encrypt( $inner_params );
		$hash_info    = $this->crypto->hash_info( $encrypt_info );

		return [
			'Status'      => $outer_status,
			'MerID'       => self::MERCHANT_ID,
			'Version'     => self::VERSION,
			'EncryptInfo' => $encrypt_info,
			'HashInfo'    => $hash_info,
		];
	}

	/**
	 * 建立已付款（TradeStatus=1）的標準內層 payload
	 *
	 * ⚠️ Gateway 固定為 9（IFrame），與 UPP 的 Gateway=2 不同
	 * ⚠️ PaymentType 固定為 1（信用卡），UNi Embed 無 ATM/CVS
	 *
	 * @param string $mer_trade_no 商店訂單編號（PCE 前綴）
	 * @param string $trade_amt    交易金額字串
	 * @return array<string, string>
	 */
	private function paid_inner( string $mer_trade_no, string $trade_amt = '1000' ): array {
		return [
			'Status'      => 'SUCCESS',
			'Message'     => '授權成功',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => $mer_trade_no,
			'TradeNo'     => 'UNIEMBED20260101001',
			'TradeAmt'    => $trade_amt,
			'TradeStatus' => '1',
			'PaymentType' => '1',   // 固定 1=信用卡（UNi Embed 僅支援信用卡）
			'Gateway'     => '9',   // 固定 9=IFrame（UPP 為 2）
			'CardBank'    => '013',
			'Card6No'     => '414763',
			'Card4No'     => '0001',
			'AuthCode'    => '123456',
			'AuthDay'     => '20260101',
			'AuthTime'    => '120000',
		];
	}

	/**
	 * 建立付款失敗（TradeStatus=2）的標準內層 payload
	 *
	 * @param string $mer_trade_no 商店訂單編號
	 * @return array<string, string>
	 */
	private function failed_inner( string $mer_trade_no ): array {
		return [
			'Status'      => 'E001',
			'Message'     => '付款失敗',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => $mer_trade_no,
			'TradeNo'     => 'UNIEMBED20260101FAIL',
			'TradeAmt'    => '1000',
			'TradeStatus' => '2',
			'PaymentType' => '1',
			'Gateway'     => '9',
		];
	}

	/**
	 * 建立付款取消（TradeStatus=3）的標準內層 payload
	 *
	 * @param string $mer_trade_no 商店訂單編號
	 * @return array<string, string>
	 */
	private function cancelled_inner( string $mer_trade_no ): array {
		return [
			'Status'      => 'E002',
			'Message'     => '付款取消',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => $mer_trade_no,
			'TradeNo'     => 'UNIEMBED20260101CANCEL',
			'TradeAmt'    => '1000',
			'TradeStatus' => '3',
			'PaymentType' => '1',
			'Gateway'     => '9',
		];
	}

	/**
	 * 建立待確認（TradeStatus=8）的標準內層 payload
	 * UNKNOWN：60 秒無銀行回應，後續以 NotifyURL 通知最終結果
	 *
	 * @param string $mer_trade_no 商店訂單編號
	 * @return array<string, string>
	 */
	private function unknown_inner( string $mer_trade_no ): array {
		return [
			'Status'      => 'UNKNOWN',
			'Message'     => 'UNKNOWN',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => $mer_trade_no,
			'TradeNo'     => 'UNIEMBED20260101UNK',
			'TradeAmt'    => '1000',
			'TradeStatus' => '8',
			'PaymentType' => '1',
			'Gateway'     => '9',
		];
	}

	/**
	 * 以 WP_REST_Request 觸發 PayuniUniEmbedCallback，回傳 WP_REST_Response
	 *
	 * 觸發路徑：PayuniUniEmbedCallback::instance()->post_uni_embed_notify_callback($request)
	 * 此方法名稱依 ApiBase 命名規則：{method}_{endpoint_underscored}_callback
	 *
	 * @param array<string, string> $body_params 外層 Form POST body
	 * @return \WP_REST_Response
	 */
	private function dispatch_notify( array $body_params ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/payuni/uni-embed/notify' );
		$request->set_body_params( $body_params );
		return PayuniUniEmbedCallback::instance()->post_uni_embed_notify_callback( $request );
	}

	// =====================================================================
	// 冒煙測試（Smoke）
	// =====================================================================

	/**
	 * PayuniUniEmbedCallback 可被實例化
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_PayuniUniEmbedCallback可被實例化(): void {
		$this->assertInstanceOf( PayuniUniEmbedCallback::class, PayuniUniEmbedCallback::instance() );
	}

	// =====================================================================
	// Security：HashInfo timing-safe 驗章（逐環獨立，用真實 PayuniCrypto 產生向量）
	// =====================================================================

	/**
	 * 有效 HashInfo → 正常處理（訂單轉 processing）
	 * 完整 end-to-end：encrypt → hash_info → callback → verify → StatusManager
	 *
	 * @test
	 * @group happy
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_有效HashInfo_正常處理訂單轉processing(): void {
		// Given: pending 訂單，_pc_payuni_uni_trade_no = PCE100
		$order = $this->create_uni_embed_order( 'PCE100', 1000 );
		$body  = $this->build_notify_body( $this->paid_inner( 'PCE100', '1000' ) );

		// When: 發送有效 HashInfo（timing-safe 驗章通過）
		$response = $this->dispatch_notify( $body );

		// Then: 訂單轉 processing
		$this->assert_order_status( $order, 'processing' );
		// HTTP 200（UNi Embed NotifyURL always 200）
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * HashInfo 被竄改 → 拒絕（timing-safe hash_equals 不符）+ 仍回 HTTP 200
	 *
	 * 安全核心：verify_hash() 使用 hash_equals() timing-safe 比對——
	 * 即使驗章失敗也必須回 HTTP 200，避免 PAYUNi 觸發重送風暴
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_HashInfo竄改_拒絕不更新訂單且回200(): void {
		// Given: pending 訂單
		$order = $this->create_uni_embed_order( 'PCE_BAD_HASH', 1000 );
		$body  = $this->build_notify_body( $this->paid_inner( 'PCE_BAD_HASH', '1000' ) );

		// 竄改 HashInfo（即使 EncryptInfo 有效，HashInfo 不符也應拒絕）
		$body['HashInfo'] = 'DEADBEEF00000000000000000000000000000000000000000000000000000000';

		// When
		$response = $this->dispatch_notify( $body );

		// Then: 訂單維持 pending（timing-safe 驗章失敗，拒絕處理）
		$this->assert_order_status( $order, 'pending' );
		// 仍回 HTTP 200
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * HashInfo 為空字串 → 拒絕（不可用空字串繞過 timing-safe 比對）+ 仍回 HTTP 200
	 *
	 * timing-safe 防禦：hash_equals('', '') 在 PHP 中回傳 true，
	 * 實作必須在 verify_hash 前先拒絕空字串（長度守衛）
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_HashInfo空字串_拒絕並回200(): void {
		$order         = $this->create_uni_embed_order( 'PCE_EMPTY_HASH', 1000 );
		$valid_encrypt = $this->crypto->encrypt( $this->paid_inner( 'PCE_EMPTY_HASH', '1000' ) );

		$body = [
			'Status'      => 'SUCCESS',
			'MerID'       => self::MERCHANT_ID,
			'Version'     => self::VERSION,
			'EncryptInfo' => $valid_encrypt,
			'HashInfo'    => '', // 空字串不得繞過驗章
		];

		$response = $this->dispatch_notify( $body );

		$this->assert_order_status( $order, 'pending' );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * HashInfo 長度異常（非 64 字元）→ 直接拒絕，不進入解密流程 + 仍回 HTTP 200
	 *
	 * SHA256 輸出固定 64 字元 hex；長度不符代表格式錯誤，應在進入 timing-safe 比對前就拒絕
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_HashInfo長度異常_直接拒絕回200(): void {
		$order         = $this->create_uni_embed_order( 'PCE_HASH_LEN', 1000 );
		$valid_encrypt = $this->crypto->encrypt( $this->paid_inner( 'PCE_HASH_LEN', '1000' ) );

		$body = [
			'Status'      => 'SUCCESS',
			'MerID'       => self::MERCHANT_ID,
			'Version'     => self::VERSION,
			'EncryptInfo' => $valid_encrypt,
			'HashInfo'    => 'TOOSHORT', // 不足 64 字元的 HashInfo
		];

		$response = $this->dispatch_notify( $body );

		$this->assert_order_status( $order, 'pending' );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * EncryptInfo 被竄改（HashInfo 對竄改後 EncryptInfo 重算，但 AES-GCM AuthTag 驗證失敗）
	 * → 解密失敗 → catch \Throwable → 仍回 HTTP 200
	 *
	 * 測試路徑：HashInfo 通過（對竄改值重算） → decrypt() 拋出 RuntimeException（AuthTag 不符）
	 *            → Callback catch \Throwable → 回 200
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_EncryptInfo竄改_AuthTag驗證失敗仍回200(): void {
		$order = $this->create_uni_embed_order( 'PCE_BAD_ENC', 1000 );

		// 取有效 EncryptInfo 的前半段拼接隨機 hex（破壞 AuthTag）
		$valid_encrypt = $this->crypto->encrypt( $this->paid_inner( 'PCE_BAD_ENC', '1000' ) );
		$tampered_enc  = substr( $valid_encrypt, 0, 20 ) . str_repeat( 'ff', 20 );

		$body = [
			'Status'      => 'SUCCESS',
			'MerID'       => self::MERCHANT_ID,
			'Version'     => self::VERSION,
			'EncryptInfo' => $tampered_enc,
			// HashInfo 對竄改後 EncryptInfo 重算（確保 HashInfo 本身一致，使測試聚焦在解密失敗路徑）
			'HashInfo'    => $this->crypto->hash_info( $tampered_enc ),
		];

		// When
		$response = $this->dispatch_notify( $body );

		// Then: 訂單維持 pending（decrypt 失敗）
		$this->assert_order_status( $order, 'pending' );
		// 仍回 HTTP 200（catch \Throwable，避免重送風暴）
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * MerID 不符商店設定（跨商店污染攻擊）→ 拒絕，不更新訂單，仍回 HTTP 200
	 *
	 * 跨商店攻擊情境：攻擊者持有其他商店的合法 HashKey/HashIV，
	 * 對本商店 NotifyURL 發送帶有不同 MerID 的合法加密通知
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_MerID不符設定_拒絕並回200(): void {
		$order = $this->create_uni_embed_order( 'PCE_MERID_ERR', 1000 );

		// 用正確金鑰加密，但 MerID 為惡意商店代號
		$inner          = $this->paid_inner( 'PCE_MERID_ERR', '1000' );
		$inner['MerID'] = 'EVIL_MERCHANT';
		$encrypt_info   = $this->crypto->encrypt( $inner );
		$hash_info      = $this->crypto->hash_info( $encrypt_info );

		$body = [
			'Status'      => 'SUCCESS',
			'MerID'       => 'EVIL_MERCHANT', // 外層也竄改為惡意商店
			'Version'     => self::VERSION,
			'EncryptInfo' => $encrypt_info,
			'HashInfo'    => $hash_info,
		];

		$response = $this->dispatch_notify( $body );

		$this->assert_order_status( $order, 'pending' );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * _pc_payuni_uni_trade_no 反查訂單：使用 UNi Embed 專屬 meta key（PCE 前綴）
	 * 斷言：不會撈到 UPP 的 _pc_payuni_trade_no（PCU 前綴）訂單
	 *
	 * 隔離測試：建立一個 UPP 訂單（PCU 前綴），再以 PCE 格式查詢，應查無訂單（仍回 200）
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_PCE反查不撈取PCU訂單_隔離性確認(): void {
		// 建立一個 UPP 訂單（使用 UPP meta key _pc_payuni_trade_no，值為 PCU100）
		$upp_order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => 'payuni_upp',
				'total'          => 1000,
			]
		);
		// 用 UPP 的 MetaKeys 寫入 PCU100（只寫 UPP meta key，_pc_payuni_trade_no）
		( new PayuniMetaKeys( $upp_order ) )->update_trade_no( 'PCU100' );

		// 以 PCE100 查詢（UNi Embed 的 MerTradeNo），不應找到 UPP 的 PCU100 訂單
		$inner = $this->paid_inner( 'PCE100', '1000' );  // 注意：PCE 前綴
		$body  = $this->build_notify_body( $inner );

		$response = $this->dispatch_notify( $body );

		// UPP 訂單狀態維持 pending（不被 UNi Embed callback 污染）
		$this->assert_order_status( $upp_order, 'pending' );
		// 查無 UNi Embed 訂單，仍回 200
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * MerTradeNo 反查訂單：_pc_payuni_uni_trade_no 查無訂單 → 仍回 HTTP 200
	 *
	 * @test
	 * @group security
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_MerTradeNo查無訂單_仍回HTTP200(): void {
		// 不建立任何 UNi Embed 訂單；使用不存在的 PCE MerTradeNo
		$inner = $this->paid_inner( 'PCE_NONEXISTENT_999', '1000' );
		$body  = $this->build_notify_body( $inner );

		$response = $this->dispatch_notify( $body );

		// 查無訂單也必須回 200（避免 PAYUNi 重送風暴）
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * TradeAmt 防竄改：解密後 TradeAmt ≠ 本地訂單金額 → 拒絕轉 processing，維持 pending
	 * 斷言：竄改金額（1 元）不會觸發 payment_complete
	 *
	 * 此為資安最關鍵案例：攻擊者持有正確 HashKey/HashIV，以低金額換取商品。
	 * UNi Embed 在 merchant_trade 階段就可能竄改，NotifyURL 是最後防線。
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeAmt防竄改_竄改金額不會payment_complete(): void {
		// Given: 訂單應收 1000，攻擊者以合法金鑰加密 TradeAmt=1 的通知
		$order = $this->create_uni_embed_order( 'PCE_AMT_TAMPER', 1000 );
		$body  = $this->build_notify_body(
			$this->paid_inner( 'PCE_AMT_TAMPER', '1' ) // 竄改金額（1 元）
		);

		$response = $this->dispatch_notify( $body );

		// 維持 pending（金額不符，StatusManager 阻止轉 processing）
		$this->assert_order_status( $order, 'pending' );
		// 仍回 HTTP 200
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * TradeAmt 防竄改：竄改金額不轉 processing + 必須寫入告警 order note
	 * 確保管理員能察覺異常通知
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeAmt竄改_寫入告警order_note(): void {
		$order = $this->create_uni_embed_order( 'PCE_AMT_WARN', 1000 );
		$body  = $this->build_notify_body(
			$this->paid_inner( 'PCE_AMT_WARN', '1' ) // 竄改金額
		);

		$this->dispatch_notify( $body );

		// 告警 note 必須包含「金額」字樣（警示管理員）
		$this->assert_order_note_contains( $order, '金額' );
	}

	/**
	 * 冪等：已 processing 訂單重複通知 → skip，不重複 payment_complete，仍回 200
	 *
	 * PAYUNi 說明：收到 200 不重送。但若商店曾返回非 200，PAYUNi 不做 retry（建議主動查詢）。
	 * 此測試確保冪等守衛在 Callback 層即阻擋重複處理。
	 *
	 * @test
	 * @group security
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冪等_已processing重複通知skip不重複payment_complete(): void {
		$order = $this->create_uni_embed_order( 'PCE_IDEM_CB', 1000 );
		$body  = $this->build_notify_body( $this->paid_inner( 'PCE_IDEM_CB', '1000' ) );

		// 第一次：訂單轉 processing
		$this->dispatch_notify( $body );
		$this->assert_order_status( $order, 'processing' );

		// 重送 3 次（模擬 PAYUNi 因誤判未收到 200 而重送）
		for ( $i = 0; $i < 3; $i++ ) {
			$response = $this->dispatch_notify( $body );
			// 每次都回 200（不因重送報錯）
			$this->assertSame( 200, $response->get_status() );
		}

		// 狀態維持 processing（不因重送變更為其他狀態）
		$this->assert_order_status( $order, 'processing' );
	}

	// =====================================================================
	// Error：所有路徑一律回 HTTP 200（Error 群完整覆蓋）
	// =====================================================================

	/**
	 * 所有解密失敗路徑一律回 HTTP 200（not 500）
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_解密失敗_回200不拋500(): void {
		$order = $this->create_uni_embed_order( 'PCE_DEC_FAIL', 1000 );

		// 建一個看起來像 hex 但解密後 AuthTag 驗證會失敗的 EncryptInfo
		$valid_encrypt = $this->crypto->encrypt( $this->paid_inner( 'PCE_DEC_FAIL', '1000' ) );
		// 截斷後半段（破壞 :::base64(tag) 部分），仍確保是合法 hex 格式
		$corrupt_enc = bin2hex( substr( hex2bin( $valid_encrypt ), 0, 10 ) );

		$body = [
			'Status'      => 'SUCCESS',
			'MerID'       => self::MERCHANT_ID,
			'Version'     => self::VERSION,
			'EncryptInfo' => $corrupt_enc,
			'HashInfo'    => $this->crypto->hash_info( $corrupt_enc ),
		];

		// 不應拋出任何未捕捉的例外（\Throwable 全部被 catch）
		$response = $this->dispatch_notify( $body );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * EncryptInfo 欄位缺失（惡意或格式錯誤的請求）→ 回 HTTP 200（不拋 500）
	 *
	 * @test
	 * @group error
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_EncryptInfo缺失_回200不拋500(): void {
		$order = $this->create_uni_embed_order( 'PCE_NO_ENC', 1000 );

		// 刻意不帶 EncryptInfo
		$body = [
			'Status'   => 'SUCCESS',
			'MerID'    => self::MERCHANT_ID,
			'Version'  => self::VERSION,
			'HashInfo' => 'SOME_HASH',
			// EncryptInfo 故意省略
		];

		$response = $this->dispatch_notify( $body );

		$this->assert_order_status( $order, 'pending' );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * HashInfo 欄位缺失 → 回 HTTP 200（不拋 500）
	 *
	 * @test
	 * @group error
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_HashInfo欄位缺失_回200不拋500(): void {
		$order         = $this->create_uni_embed_order( 'PCE_NO_HASH', 1000 );
		$valid_encrypt = $this->crypto->encrypt( $this->paid_inner( 'PCE_NO_HASH', '1000' ) );

		$body = [
			'Status'      => 'SUCCESS',
			'MerID'       => self::MERCHANT_ID,
			'Version'     => self::VERSION,
			'EncryptInfo' => $valid_encrypt,
			// HashInfo 故意省略
		];

		$response = $this->dispatch_notify( $body );

		$this->assert_order_status( $order, 'pending' );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * 外層 Status ≠ SUCCESS（PAYUNi 本身報錯）→ 直接拒絕 + 仍回 HTTP 200
	 *
	 * 依 payuni-uni-embed-v3 §NotifyURL：外層 Status=ERROR 時無 EncryptInfo，直接拒絕
	 *
	 * @test
	 * @group error
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_外層Status非SUCCESS_直接拒絕仍回200(): void {
		$order = $this->create_uni_embed_order( 'PCE_OUT_ERR', 1000 );

		// 外層 Status=ERROR 時不會有 EncryptInfo
		$body = [
			'Status' => 'ERROR',
			'MerID'  => self::MERCHANT_ID,
			// EncryptInfo / HashInfo 刻意省略（PAYUNi ERROR 時無此欄位）
		];

		$response = $this->dispatch_notify( $body );

		// 訂單維持 pending
		$this->assert_order_status( $order, 'pending' );
		// 仍回 HTTP 200
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * 完全空白 body（無任何欄位）→ 回 HTTP 200（不拋 500）
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_空白body_回200不拋500(): void {
		// When: 以空白 body 觸發 callback
		$response = $this->dispatch_notify( [] );

		// Then: 任何情況都必須回 200
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * 任意 \Throwable 均被 catch，仍回 HTTP 200
	 *
	 * @test
	 * @group error
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_任意Throwable均被catch仍回200(): void {
		$order = $this->create_uni_embed_order( 'PCE_THROWABLE', 1000 );

		// 建一個看起來像 hex 但截斷後解密必定失敗的 EncryptInfo
		$valid_encrypt = $this->crypto->encrypt( $this->paid_inner( 'PCE_THROWABLE', '1000' ) );
		$corrupt_enc   = bin2hex( substr( hex2bin( $valid_encrypt ), 0, 10 ) );

		$body = [
			'Status'      => 'SUCCESS',
			'MerID'       => self::MERCHANT_ID,
			'Version'     => self::VERSION,
			'EncryptInfo' => $corrupt_enc,
			'HashInfo'    => $this->crypto->hash_info( $corrupt_enc ),
		];

		// 不應拋出任何未捕捉的例外（\Throwable 全部被 catch，always 200）
		$response = $this->dispatch_notify( $body );
		$this->assertSame( 200, $response->get_status() );
	}

	// =====================================================================
	// Happy：TradeStatus 分流（對照 DECISION:4a）
	// =====================================================================

	/**
	 * TradeStatus=1 → 訂單轉 processing + payment_complete + 寫入 _pc_payuni_uni_payment_detail
	 *
	 * 完整 end-to-end happy path，對照 feature 場景「付款成功轉處理中」
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus1_訂單轉processing並寫入payment_detail(): void {
		$order = $this->create_uni_embed_order( 'PCE_HAPPY_001', 1000 );
		$body  = $this->build_notify_body( $this->paid_inner( 'PCE_HAPPY_001', '1000' ) );

		$response = $this->dispatch_notify( $body );

		// 訂單轉 processing
		$this->assert_order_status( $order, 'processing' );

		// _pc_payuni_uni_payment_detail 有值（Gateway=9 識別 UNi Embed）
		$detail = ( new PayuniUniEmbedMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_detail();
		$this->assertNotEmpty( $detail, 'UNi Embed payment_detail 不應為空' );
		$this->assertSame( '1', $detail['TradeStatus'] ?? '' );
		$this->assertSame( '9', $detail['Gateway'] ?? '', 'UNi Embed Gateway 必須為 9（IFrame）' );

		// HTTP 200
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * TradeStatus=1 + Gateway=9（IFrame 識別）→ 確認 Gateway 值正確寫入 payment_detail
	 *
	 * 斷言 UNi Embed 的 Gateway=9 不是 UPP 的 Gateway=2
	 *
	 * @test
	 * @group happy
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus1_Gateway9寫入payment_detail而非UPP的2(): void {
		$order = $this->create_uni_embed_order( 'PCE_GW9', 1000 );
		$body  = $this->build_notify_body( $this->paid_inner( 'PCE_GW9', '1000' ) );

		$this->dispatch_notify( $body );

		$detail = ( new PayuniUniEmbedMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_detail();
		// Gateway 必須為 9，絕不是 2
		$this->assertSame( '9', $detail['Gateway'] ?? '' );
		$this->assertNotSame( '2', $detail['Gateway'] ?? '' );
	}

	/**
	 * 任何有效通知 → 必回 HTTP 200（成功路徑確認）
	 * PAYUNi 文件要求：只要收到 200 就視為商家已接收
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_任何有效通知_回應HTTP200(): void {
		$order    = $this->create_uni_embed_order( 'PCE_200_001', 1000 );
		$body     = $this->build_notify_body( $this->paid_inner( 'PCE_200_001', '1000' ) );
		$response = $this->dispatch_notify( $body );

		$this->assertSame( 200, $response->get_status(), 'UNi Embed NotifyURL 必須回 HTTP 200' );
	}

	// =====================================================================
	// Edge：TradeStatus=2/3/8 非成功狀態分流
	// =====================================================================

	/**
	 * TradeStatus=2（付款失敗）→ 維持 pending + order note
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus2付款失敗_維持pending並記錄note(): void {
		$order = $this->create_uni_embed_order( 'PCE107', 1000 );
		$body  = $this->build_notify_body( $this->failed_inner( 'PCE107' ) );

		$response = $this->dispatch_notify( $body );

		$this->assert_order_status( $order, 'pending' );
		$this->assertSame( 200, $response->get_status() );

		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$found = false;
		foreach ( $notes as $note ) {
			if ( str_contains( $note->content, '失敗' ) || str_contains( $note->content, '2' ) || str_contains( $note->content, 'E001' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'order note 應包含付款失敗相關字樣（依 feature 場景大綱 status=2 note=付款失敗）' );
	}

	/**
	 * TradeStatus=3（付款取消）→ 維持 pending + order note
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus3付款取消_維持pending並記錄note(): void {
		$order = $this->create_uni_embed_order( 'PCE108', 1000 );
		$body  = $this->build_notify_body( $this->cancelled_inner( 'PCE108' ) );

		$response = $this->dispatch_notify( $body );

		$this->assert_order_status( $order, 'pending' );
		$this->assertSame( 200, $response->get_status() );

		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$found = false;
		foreach ( $notes as $note ) {
			if ( str_contains( $note->content, '取消' ) || str_contains( $note->content, '3' ) || str_contains( $note->content, 'E002' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'order note 應包含付款取消相關字樣（依 feature 場景大綱 status=3 note=付款取消）' );
	}

	/**
	 * TradeStatus=8（訂單待確認 / UNKNOWN / UNAPPROVED）→ 維持 pending + order note
	 * UNKNOWN = 60 秒無銀行回應，後續以 NotifyURL 通知最終結果
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus8待確認_維持pending並記錄note(): void {
		$order = $this->create_uni_embed_order( 'PCE109', 1000 );
		$body  = $this->build_notify_body( $this->unknown_inner( 'PCE109' ) );

		$response = $this->dispatch_notify( $body );

		$this->assert_order_status( $order, 'pending' );
		$this->assertSame( 200, $response->get_status() );

		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$found = false;
		foreach ( $notes as $note ) {
			if ( str_contains( $note->content, 'UNKNOWN' ) || str_contains( $note->content, '待確認' ) || str_contains( $note->content, '8' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'order note 應包含待確認相關字樣（依 feature 場景大綱 status=8 note=訂單待確認）' );
	}

	/**
	 * UNAPPROVED（TradeStatus=8 + Status=UNAPPROVED）→ 維持 pending + order note
	 *
	 * UNAPPROVED = 買家會員審查中（與 UNKNOWN 同為 TradeStatus=8，
	 * 但 Status 欄位值不同，應確保兩者都被正確路由到 pending）
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus8_UNAPPROVED_維持pending並記錄note(): void {
		$order = $this->create_uni_embed_order( 'PCE_UNAPPROVED', 1000 );

		$unapproved_inner = [
			'Status'      => 'UNAPPROVED',
			'Message'     => '買家會員審查中',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => 'PCE_UNAPPROVED',
			'TradeNo'     => 'UNIEMBED_UNAPPR',
			'TradeAmt'    => '1000',
			'TradeStatus' => '8',
			'PaymentType' => '1',
			'Gateway'     => '9',
		];

		$body = $this->build_notify_body( $unapproved_inner );

		$response = $this->dispatch_notify( $body );

		$this->assert_order_status( $order, 'pending' );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * UNi Embed 不應有 TradeStatus=0（取號成功）
	 * 確認若 PAYUNi 意外送來 TradeStatus=0，不更新訂單、維持 pending
	 *
	 * UNi Embed 僅支援信用卡，沒有 ATM/CVS 取號流程，TradeStatus=0 不應出現
	 *
	 * @test
	 * @group edge
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_意外收到TradeStatus0_維持pending不寫payment_info(): void {
		$order = $this->create_uni_embed_order( 'PCE_TS0_UNEXPECTED', 1000 );

		// 模擬 PAYUNi 意外送來 TradeStatus=0（UNi Embed 不應有此狀態）
		$ts0_inner = [
			'Status'      => 'SUCCESS',
			'Message'     => '意外的取號',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => 'PCE_TS0_UNEXPECTED',
			'TradeNo'     => 'UNIEMBED_TS0',
			'TradeAmt'    => '1000',
			'TradeStatus' => '0',   // UNi Embed 不應出現
			'PaymentType' => '1',
			'Gateway'     => '9',
		];

		$body = $this->build_notify_body( $ts0_inner );

		$response = $this->dispatch_notify( $body );

		// 維持 pending（TradeStatus=0 不更新訂單）
		$this->assert_order_status( $order, 'pending' );
		// 仍回 200
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * timing-safe 正確性驗證：相同 EncryptInfo 計算兩次 HashInfo 結果相同
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_HashInfo_timing_safe_比對正確性(): void {
		$inner        = $this->paid_inner( 'PCE_TIMING', '1000' );
		$encrypt_info = $this->crypto->encrypt( $inner );
		$hash1        = $this->crypto->hash_info( $encrypt_info );
		$hash2        = $this->crypto->hash_info( $encrypt_info );

		// timing-safe 比對：相同輸入相同輸出
		$this->assertTrue( $this->crypto->verify_hash( $encrypt_info, $hash1 ) );
		$this->assertTrue( $this->crypto->verify_hash( $encrypt_info, $hash2 ) );

		// 竄改值不通過
		$this->assertFalse( $this->crypto->verify_hash( $encrypt_info, 'TAMPERED_HASH' ) );
		$this->assertFalse( $this->crypto->verify_hash( $encrypt_info, '' ) );
	}
}
