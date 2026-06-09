<?php
/**
 * PAYUNi UPP V2 NotifyURL 幕後通知回調整合測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Payuni\Http\PayuniCallback
 *   - extends ApiBase；namespace = 'power-checkout/payuni'；endpoint = 'upp/notify'
 *   - permission_callback: __return_true（驗章在 callback 內部完成）
 *   - 回應格式：HTTP 200（PAYUNi 只要求收到 200，不需特定 body 字串）
 *
 * 設計依據：
 *   - specs/features/payment/payuni-upp-callback.feature
 *   - specs/features/payment/payuni-upp-payment-info.feature
 *   - payuni-upp-v2 SKILL.md §回傳參數 / §驗章流程 / §常見注意事項
 *   - 風格對齊：tests/Integration/Payment/EcpayAioCallbackTest.php（callback 驗章風格）
 *
 * 觸發機制（對齊 EcpayAioCallbackTest）：
 *   - 直接呼叫 PayuniCallback::instance() 取得單例
 *   - 以 WP_REST_Request::set_body_params() 模擬 Form POST body
 *   - 呼叫 post_upp_notify_callback( $request ) 取得 WP_REST_Response
 *   - 也可直接呼叫 handle_notify( $body_params ) 測試核心邏輯（不含 HTTP 層）
 *
 * 加解密工具（已存在）：
 *   PayuniCrypto：encrypt(array $params): string → EncryptInfo（hex）
 *              hash_info(string $encrypt_info): string → HashInfo（SHA256 大寫）
 *              verify_hash(string $encrypt_info, string $hash_info): bool
 *              decrypt(string $encrypt_info): array
 *   測試金鑰：HashKey=12345678901234567890123456789012 / HashIV=1234567890123456
 *
 * 外層 Form POST 欄位（payuni-upp-v2 §外層欄位）：
 *   Status（SUCCESS / 其他）、MerID、Version（2.0）、EncryptInfo（hex）、HashInfo（SHA256 大寫）
 *
 * 回應規格：HTTP 200（PAYUNi 收到 200 不重送；無需特定 body 字串）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Payuni\Http\PayuniCallback;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PAYUNi UPP V2 NotifyURL 幕後通知回調測試類別
 *
 * @group integration
 * @group payuni
 * @group payment
 */
final class PayuniCallbackTest extends TestCase {

	// PAYUNi 官方公開測試向量金鑰（payuni-upp-v2 encryption.md §官方測試向量）
	private const HASH_KEY    = '12345678901234567890123456789012';
	private const HASH_IV     = '1234567890123456';
	private const MERCHANT_ID = 'TEST_MER';
	private const VERSION     = '2.0';

	/** @var PayuniCrypto */
	private PayuniCrypto $crypto;

	/** 每次測試前啟用 payuni_upp（test 模式 + 官方測試向量金鑰） */
	protected function configure_dependencies(): void {
		ProviderUtils::update_option(
			'payuni_upp',
			[
				'enabled'     => 'yes',
				'mode'        => 'test',
				'merchant_id' => self::MERCHANT_ID,
				'hash_key'    => self::HASH_KEY,
				'hash_iv'     => self::HASH_IV,
			]
		);
		if ( \method_exists( PayuniSettingsDTO::class, 'reset' ) ) {
			PayuniSettingsDTO::reset();
		}
		$this->crypto = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		\delete_option( ProviderUtils::get_option_name( 'payuni_upp' ) );
		if ( \method_exists( PayuniSettingsDTO::class, 'reset' ) ) {
			PayuniSettingsDTO::reset();
		}
		parent::tear_down();
	}

	// ========== 測試輔助方法 ==========

	/**
	 * 建立已綁 MerTradeNo 的 pending 訂單
	 *
	 * @param string $mer_trade_no 商店訂單編號（存於 _pc_payuni_trade_no）
	 * @param int    $total        訂單應收金額（元）
	 * @return \WC_Order
	 */
	private function create_payuni_order( string $mer_trade_no, int $total = 1000 ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => 'payuni_upp',
				'total'          => $total,
			]
		);
		( new PayuniMetaKeys( $order ) )->update_trade_no( $mer_trade_no );
		return $order;
	}

	/**
	 * 建立有效的外層 Form POST body（含正確 HashInfo）
	 *
	 * 組裝流程（依 payuni-upp-v2 §驗章流程）：
	 *   1. 內層 payload → encrypt() → EncryptInfo
	 *   2. hash_info(EncryptInfo) → HashInfo
	 *   3. 外層 body：{ Status, MerID, Version, EncryptInfo, HashInfo }
	 *
	 * @param array<string, string> $inner_params 內層付款明細（解密後的 payload）
	 * @param string $outer_status 外層 Status（預設 SUCCESS）
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
	 * @param string $mer_trade_no 商店訂單編號
	 * @param string $trade_amt    交易金額字串
	 * @return array<string, string>
	 */
	private function paid_inner( string $mer_trade_no, string $trade_amt = '1000' ): array {
		return [
			'Status'      => 'SUCCESS',
			'Message'     => '授權成功',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => $mer_trade_no,
			'TradeNo'     => 'UNI20260101001',
			'TradeAmt'    => $trade_amt,
			'TradeStatus' => '1',
			'PaymentType' => '1',
			'Gateway'     => '2',
		];
	}

	/**
	 * 建立 ATM 取號（TradeStatus=0）的標準內層 payload
	 *
	 * @param string $mer_trade_no 商店訂單編號
	 * @return array<string, string>
	 */
	private function atm_get_code_inner( string $mer_trade_no ): array {
		return [
			'Status'      => 'SUCCESS',
			'Message'     => '(ATM)建立成功',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => $mer_trade_no,
			'TradeNo'     => 'UNI20260101ATM',
			'TradeAmt'    => '1000',
			'TradeStatus' => '0',
			'PaymentType' => '2',
			'Gateway'     => '2',
			'BankType'    => '822',
			'PayNo'       => '00000000001234',
			'PaySet'      => '1',
			'ExpireDate'  => '2026-12-31 23:59:59',
		];
	}

	/**
	 * 以 WP_REST_Request 觸發 PayuniCallback，回傳 WP_REST_Response
	 *
	 * 觸發路徑：PayuniCallback::instance()->post_upp_notify_callback($request)
	 * 此方法名稱依 ApiBase 命名規則：{method}_{endpoint_underscored}_callback
	 *
	 * @param array<string, string> $body_params 外層 Form POST body
	 * @return \WP_REST_Response
	 */
	private function dispatch_notify( array $body_params ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/payuni/upp/notify' );
		$request->set_body_params( $body_params );
		return PayuniCallback::instance()->post_upp_notify_callback( $request );
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * PayuniCallback 可被實例化
	 *
	 * @test
	 * @group smoke
	 * @group payuni
	 * @group payment
	 */
	public function test_冒煙_PayuniCallback可被實例化(): void {
		$this->assertInstanceOf( PayuniCallback::class, PayuniCallback::instance() );
	}

	// ========== 安全性：HashInfo timing-safe 驗章（Security — 最重要） ==========

	/**
	 * 有效 HashInfo → 正常處理（訂單轉 processing）
	 *
	 * 完整 end-to-end：encrypt → hash_info → callback → verify → StatusManager
	 *
	 * @test
	 * @group happy
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_有效HashInfo_正常處理訂單轉processing(): void {
		// Given: pending 訂單
		$order = $this->create_payuni_order( 'PCU_VALID_HASH', 1000 );
		$body  = $this->build_notify_body( $this->paid_inner( 'PCU_VALID_HASH', '1000' ) );

		// When: 發送有效 HashInfo 的通知
		$response = $this->dispatch_notify( $body );

		// Then: 訂單轉 processing
		$this->assert_order_status( $order, 'processing' );
		// HTTP 200
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * HashInfo 被竄改 → 拒絕（不更新訂單狀態）+ 仍回 HTTP 200
	 *
	 * 核心安全規則：竄改 HashInfo 時 verify_hash() 使用 hash_equals() timing-safe 比對
	 * 即使驗章失敗，也必須回 HTTP 200（避免 PAYUNi 觸發重送風暴）
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_HashInfo竄改_拒絕不更新訂單狀態且回200(): void {
		// Given: pending 訂單
		$order = $this->create_payuni_order( 'PCU_BAD_HASH', 1000 );
		$body  = $this->build_notify_body( $this->paid_inner( 'PCU_BAD_HASH', '1000' ) );

		// 竄改 HashInfo（即使 EncryptInfo 有效，HashInfo 不符也應拒絕）
		$body['HashInfo'] = 'DEADBEEF00000000000000000000000000000000000000000000000000000000';

		// When
		$response = $this->dispatch_notify( $body );

		// Then: 訂單維持 pending（驗章失敗，拒絕處理）
		$this->assert_order_status( $order, 'pending' );

		// 仍回 HTTP 200（避免 PAYUNi 重送風暴）
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * EncryptInfo 被竄改（HashInfo 隨之失效）→ 解密失敗 → 仍回 HTTP 200
	 *
	 * AES-256-GCM AuthTag 驗證失敗，decrypt() 拋出 RuntimeException；
	 * Callback 必須 catch \Throwable，仍回 HTTP 200。
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_EncryptInfo竄改_解密失敗仍回200(): void {
		$order = $this->create_payuni_order( 'PCU_BAD_ENC', 1000 );

		// 建立一個 EncryptInfo 被竄改的外層 body
		// 竄改方式：取有效 EncryptInfo 的前半段拼接隨機 hex
		$valid_encrypt = $this->crypto->encrypt( $this->paid_inner( 'PCU_BAD_ENC', '1000' ) );
		$tampered_enc  = substr( $valid_encrypt, 0, 20 ) . str_repeat( 'ff', 20 );

		$body = [
			'Status'      => 'SUCCESS',
			'MerID'       => self::MERCHANT_ID,
			'Version'     => self::VERSION,
			'EncryptInfo' => $tampered_enc,
			// HashInfo 對竄改後的 EncryptInfo 重算（確保 HashInfo 本身是一致的，測試解密失敗路徑）
			'HashInfo'    => $this->crypto->hash_info( $tampered_enc ),
		];

		// When
		$response = $this->dispatch_notify( $body );

		// Then: 訂單維持 pending
		$this->assert_order_status( $order, 'pending' );

		// 仍回 HTTP 200（catch \Throwable，避免重送風暴）
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * 外層 Status ≠ SUCCESS（PAYUNi 本身報錯）→ 直接拒絕 + 仍回 HTTP 200
	 *
	 * 依 payuni-upp-v2 §驗章流程：外層 Status=ERROR 時無 EncryptInfo，直接拒絕。
	 *
	 * @test
	 * @group security
	 * @group error
	 * @group payuni
	 * @group payment
	 */
	public function test_外層Status非SUCCESS_直接拒絕仍回200(): void {
		$order = $this->create_payuni_order( 'PCU_OUT_ERR', 1000 );

		// 外層 Status=ERROR 時不會有 EncryptInfo
		$body = [
			'Status' => 'ERROR',
			'MerID'  => self::MERCHANT_ID,
			// EncryptInfo / HashInfo 刻意省略（PAYUNi 文件說 ERROR 時無此欄位）
		];

		$response = $this->dispatch_notify( $body );

		// 訂單維持 pending
		$this->assert_order_status( $order, 'pending' );

		// 仍回 HTTP 200
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * HashInfo 為空字串 → 拒絕（不可用空字串繞過 timing-safe 比對）
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_HashInfo為空字串_拒絕並回200(): void {
		$order = $this->create_payuni_order( 'PCU_EMPTY_HASH', 1000 );

		$valid_encrypt = $this->crypto->encrypt( $this->paid_inner( 'PCU_EMPTY_HASH', '1000' ) );
		$body          = [
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

	// ========== 錯誤處理：其他異常情境（Error / Security） ==========

	/**
	 * MerTradeNo 查無訂單 → 仍回 HTTP 200（避免重送風暴）
	 *
	 * @test
	 * @group error
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_MerTradeNo查無訂單_仍回HTTP200(): void {
		// 不建立任何訂單；使用不存在的 MerTradeNo
		$inner = $this->paid_inner( 'PCU_NONEXISTENT_ORDER', '1000' );
		$body  = $this->build_notify_body( $inner );

		$response = $this->dispatch_notify( $body );

		// 查無訂單也必須回 200（避免 PAYUNi 重送風暴）
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * EncryptInfo 欄位缺失（惡意或錯誤的請求）→ 回 HTTP 200（不拋 500）
	 *
	 * @test
	 * @group error
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_EncryptInfo缺失_回200不拋500(): void {
		$order = $this->create_payuni_order( 'PCU_NO_ENC', 1000 );

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
	 * 任意 \Throwable 均被 catch，仍回 HTTP 200
	 *
	 * 模擬方式：傳入非法格式的 EncryptInfo（純 hex 格式但內容無效），
	 * 使 decrypt() 拋出 RuntimeException；確認 Callback catch 後仍回 200。
	 *
	 * @test
	 * @group error
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_任意Throwable均被catch仍回200(): void {
		$order = $this->create_payuni_order( 'PCU_THROWABLE', 1000 );

		// 建一個看起來像 hex 但解密後 AuthTag 驗證會失敗的 EncryptInfo
		// 做法：把合法 EncryptInfo 中的 AuthTag 部分截斷
		$valid_encrypt = $this->crypto->encrypt( $this->paid_inner( 'PCU_THROWABLE', '1000' ) );
		// 直接截斷後半段（破壞 :::base64(tag) 部分），仍確保是合法 hex 格式
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

	// ========== 快樂路徑（Happy） ==========

	/**
	 * TradeStatus=1 有效通知（金額相符）→ 訂單轉 processing + 寫入 payment_detail
	 *
	 * 完整 end-to-end happy path
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus1有效通知_訂單轉processing並寫入payment_detail(): void {
		$order = $this->create_payuni_order( 'PCU_HAPPY_001', 1000 );
		$body  = $this->build_notify_body( $this->paid_inner( 'PCU_HAPPY_001', '1000' ) );

		$response = $this->dispatch_notify( $body );

		// 訂單轉 processing
		$this->assert_order_status( $order, 'processing' );

		// payment_detail 有值
		$detail = ( new PayuniMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_detail();
		$this->assertNotEmpty( $detail );
		$this->assertSame( '1', $detail['TradeStatus'] ?? '' );

		// HTTP 200
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * TradeStatus=0 ATM 取號通知 → 維持 pending + 寫入 payment_info（ATM 欄位）
	 *
	 * 同一 NotifyURL endpoint 處理取號（TradeStatus=0）與付款（TradeStatus=1）分流
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus0ATM取號_維持pending並寫入payment_info(): void {
		$order = $this->create_payuni_order( 'PCU_ATM_NTFY', 1000 );
		$body  = $this->build_notify_body( $this->atm_get_code_inner( 'PCU_ATM_NTFY' ) );

		$response = $this->dispatch_notify( $body );

		// 維持 pending（非付款成功，不轉 processing）
		$this->assert_order_status( $order, 'pending' );

		// payment_info 有 ATM 欄位
		$info = ( new PayuniMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_info();
		$this->assertNotEmpty( $info, 'ATM 取號 payment_info 不應為空' );
		$this->assertSame( '822', $info['BankType'] ?? '' );
		$this->assertSame( '00000000001234', $info['PayNo'] ?? '' );
		$this->assertNotEmpty( $info['ExpireDate'] ?? '' );

		// HTTP 200
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * 任何通知 → 必回 HTTP 200（成功路徑確認）
	 *
	 * PAYUNi 文件要求：只要收到 200 就視為商家已接收，不重送
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_任何有效通知_回應HTTP200(): void {
		$order    = $this->create_payuni_order( 'PCU_200_001', 1000 );
		$body     = $this->build_notify_body( $this->paid_inner( 'PCU_200_001', '1000' ) );
		$response = $this->dispatch_notify( $body );

		$this->assertSame( 200, $response->get_status(), 'PAYUNi NotifyURL 必須回 HTTP 200' );
	}

	// ========== 冪等：重複通知（Edge） ==========

	/**
	 * 冪等：同一 MerTradeNo 重複通知（PAYUNi 重送），已 processing 不重複 payment_complete
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_冪等_重複通知已processing訂單不重複處理仍回200(): void {
		$order = $this->create_payuni_order( 'PCU_IDEM_CB', 1000 );
		$body  = $this->build_notify_body( $this->paid_inner( 'PCU_IDEM_CB', '1000' ) );

		// 第一次：訂單轉 processing
		$this->dispatch_notify( $body );
		$this->assert_order_status( $order, 'processing' );

		// 重送 3 次
		for ( $i = 0; $i < 3; $i++ ) {
			$response = $this->dispatch_notify( $body );
			// 每次都回 200（不因重送報錯）
			$this->assertSame( 200, $response->get_status() );
		}

		// 狀態維持 processing（不因重送變更為其他狀態）
		$this->assert_order_status( $order, 'processing' );
	}

	// ========== 安全性：金額竄改（Security） ==========

	/**
	 * 金額竄改通知（HashInfo 合法但 TradeAmt ≠ 訂單應收）→ 維持 pending + 仍回 200
	 *
	 * 此情境：攻擊者持有正確 HashKey/HashIV 並重新加密低金額通知（或 PAYUNi 平台方被入侵）。
	 * 本層（Callback）委派 StatusManager 驗證金額，確保 Callback 不繞過金額驗證。
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_金額竄改通知_維持pending仍回200(): void {
		// Given: 訂單應收 1000，攻擊者以合法金鑰加密 TradeAmt=1 的通知
		$order = $this->create_payuni_order( 'PCU_AMT_CB', 1000 );
		$body  = $this->build_notify_body( $this->paid_inner( 'PCU_AMT_CB', '1' ) ); // 竄改金額

		$response = $this->dispatch_notify( $body );

		// 維持 pending（金額不符，StatusManager 阻止轉 processing）
		$this->assert_order_status( $order, 'pending' );

		// 仍回 HTTP 200
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * HashInfo timing-safe 比對：相同 EncryptInfo 計算兩次結果相同（verify_hash 正確性）
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_HashInfo_timing_safe_比對正確性(): void {
		$inner        = $this->paid_inner( 'PCU_TIMING', '1000' );
		$encrypt_info = $this->crypto->encrypt( $inner );
		$hash1        = $this->crypto->hash_info( $encrypt_info );
		$hash2        = $this->crypto->hash_info( $encrypt_info );

		// timing-safe 比對：相同輸入相同輸出
		$this->assertTrue( $this->crypto->verify_hash( $encrypt_info, $hash1 ) );
		$this->assertTrue( $this->crypto->verify_hash( $encrypt_info, $hash2 ) );

		// 竄改值不通過
		$this->assertFalse( $this->crypto->verify_hash( $encrypt_info, 'TAMPERED' ) );
		$this->assertFalse( $this->crypto->verify_hash( $encrypt_info, '' ) );
	}

	/**
	 * HashInfo 長度異常（非 64 字元）→ 直接拒絕，不進入解密流程
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_HashInfo長度異常_直接拒絕回200(): void {
		$order         = $this->create_payuni_order( 'PCU_HASH_LEN', 1000 );
		$valid_encrypt = $this->crypto->encrypt( $this->paid_inner( 'PCU_HASH_LEN', '1000' ) );

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
	 * MerID 不符商店設定（跨商店污染攻擊）→ 拒絕，不更新訂單
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_MerID不符設定_拒絕並回200(): void {
		$order = $this->create_payuni_order( 'PCU_MERID_CB', 1000 );

		// 用正確金鑰加密，但 MerID 為惡意商店代號
		$inner          = $this->paid_inner( 'PCU_MERID_CB', '1000' );
		$inner['MerID'] = 'EVIL_MERCHANT';
		$encrypt_info   = $this->crypto->encrypt( $inner );
		$hash_info      = $this->crypto->hash_info( $encrypt_info );

		$body = [
			'Status'      => 'SUCCESS',
			'MerID'       => 'EVIL_MERCHANT', // 外層也竄改
			'Version'     => self::VERSION,
			'EncryptInfo' => $encrypt_info,
			'HashInfo'    => $hash_info,
		];

		$response = $this->dispatch_notify( $body );

		$this->assert_order_status( $order, 'pending' );
		$this->assertSame( 200, $response->get_status() );
	}
}
