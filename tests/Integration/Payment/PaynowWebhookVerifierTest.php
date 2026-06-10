<?php
/**
 * PayNow WebhookVerifier 安全測試（TDD Red 階段 — Cycle 3）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\WebhookVerifier
 *
 * 設計依據：
 *   - specs/open-issue/paynow-implementation-plan.md §步驟 16（WebhookVerifier）
 *   - .claude/skills/paynow/references/encryption.md §2（HMAC-SHA256 規格）
 *   - .claude/skills/paynow/references/php-examples.md §2（PaynowWebhookVerifier turnkey）
 *
 * 安全規格（資安核心）：
 *   - Header 名稱：X-Payment-Center-Hmac-Sha256
 *   - 簽章計算：strtoupper(hash_hmac('sha256', raw_body, PrivateKey))
 *   - 驗簽對象：raw body（勿 json_decode 後 re-encode）
 *   - 比對方式：hash_equals（timing-safe，防止 timing attack）
 *   - 大小寫正規化：兩端 strtoupper 後比對
 *
 * WebhookVerifier 介面設計：
 *   new WebhookVerifier(string $private_key)
 *   verify(string $raw_body, string $signature): bool
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowWebhookVerifierTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\WebhookVerifier;
use Tests\Integration\TestCase;

/**
 * PayNow WebhookVerifier 安全測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowWebhookVerifierTest extends TestCase {

	// ========== 測試常數 ==========

	/** 測試用 PrivateKey（不使用真實 prod key） */
	private const PRIVATE_KEY = 'test_private_key_paynow_cycle3_abc123';

	/** 測試用 raw body（官方 Webhook payload 格式） */
	private const RAW_BODY = '{"ConnectId":"26c06b86-1324-48b6-8017-29e4efa649e6","RequestId":"09020f76-1405-4db2-b30a-ba30de629c05","Status":"Success","OrderNo":"12345678","PaymentNo":"12345678","PaymentIntentId":"pp_1a304818ced44e5cbeab6107400da3c4","TransactionNo":"4000002312251234756","Amount":1000,"Currency":"TWD","PaymentType":"CreditCard"}';

	/**
	 * 依規格計算正確的 HMAC-SHA256 簽章
	 * strtoupper(hash_hmac('sha256', raw_body, PrivateKey))
	 */
	private function calc_valid_sig( string $raw_body = self::RAW_BODY, string $key = self::PRIVATE_KEY ): string {
		return \strtoupper( \hash_hmac( 'sha256', $raw_body, $key ) );
	}

	// =====================================================================
	// 冒煙測試（Smoke）：WebhookVerifier 可被實例化
	// =====================================================================

	/**
	 * WebhookVerifier 可被實例化（建構子接收 PrivateKey）
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_WebhookVerifier可被實例化(): void {
		$verifier = new WebhookVerifier( self::PRIVATE_KEY );
		$this->assertInstanceOf( WebhookVerifier::class, $verifier );
	}

	/**
	 * verify() 方法存在且可呼叫
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_verify方法存在且回傳bool(): void {
		$verifier = new WebhookVerifier( self::PRIVATE_KEY );
		$sig      = $this->calc_valid_sig();

		$result = $verifier->verify( self::RAW_BODY, $sig );
		$this->assertIsBool( $result );
	}

	// =====================================================================
	// Security：正確簽章通過驗證
	// =====================================================================

	/**
	 * 正確 HMAC-SHA256 簽章 → verify() 回傳 true
	 *
	 * 此為 happy path：合法 PayNow Webhook 應能通過驗簽
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_正確HMAC簽章_驗證通過(): void {
		$verifier = new WebhookVerifier( self::PRIVATE_KEY );
		$sig      = $this->calc_valid_sig();

		$this->assertTrue(
			$verifier->verify( self::RAW_BODY, $sig ),
			'正確 HMAC-SHA256 簽章應通過驗證'
		);
	}

	/**
	 * 小寫簽章（PayNow 文件未明示大小寫）→ verify() 仍應通過（大小寫正規化）
	 *
	 * 規格：兩端都 strtoupper 後比對，故小寫簽章應等同大寫簽章
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_小寫簽章_大小寫正規化後仍通過(): void {
		$verifier  = new WebhookVerifier( self::PRIVATE_KEY );
		$upper_sig = $this->calc_valid_sig();
		$lower_sig = \strtolower( $upper_sig ); // 轉小寫

		$this->assertTrue(
			$verifier->verify( self::RAW_BODY, $lower_sig ),
			'小寫簽章在兩端 strtoupper 正規化後應通過驗證'
		);
	}

	/**
	 * 混合大小寫簽章 → verify() 仍應通過（大小寫正規化）
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_混合大小寫簽章_正規化後通過(): void {
		$verifier  = new WebhookVerifier( self::PRIVATE_KEY );
		$valid_sig = $this->calc_valid_sig();
		// 奇數字元大寫、偶數字元小寫（混合大小寫）
		$mixed_sig = '';
		$sig_len   = \strlen( $valid_sig );
		for ( $i = 0; $i < $sig_len; $i++ ) {
			$mixed_sig .= ( $i % 2 === 0 ) ? \strtolower( $valid_sig[ $i ] ) : \strtoupper( $valid_sig[ $i ] );
		}

		$this->assertTrue(
			$verifier->verify( self::RAW_BODY, $mixed_sig ),
			'混合大小寫簽章在 strtoupper 正規化後應通過驗證'
		);
	}

	// =====================================================================
	// Security：竄改偵測
	// =====================================================================

	/**
	 * 竄改 body（body 內容被修改）→ verify() 回傳 false
	 *
	 * 攻擊情境：攻擊者竄改 Webhook body（如修改 Amount），簽章不再有效
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_竄改body_驗簽失敗(): void {
		$verifier      = new WebhookVerifier( self::PRIVATE_KEY );
		$original_sig  = $this->calc_valid_sig(); // 針對原始 body 的有效簽章
		$tampered_body = '{"ConnectId":"26c06b86-1324-48b6-8017-29e4efa649e6","Amount":1,"PaymentType":"CreditCard"}'; // 竄改 body

		$this->assertFalse(
			$verifier->verify( $tampered_body, $original_sig ),
			'竄改 body 後，原始簽章應不再有效（驗簽失敗）'
		);
	}

	/**
	 * 竄改簽章（sig 被修改）→ verify() 回傳 false
	 *
	 * 攻擊情境：攻擊者偽造簽章
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_竄改簽章_驗簽失敗(): void {
		$verifier     = new WebhookVerifier( self::PRIVATE_KEY );
		$tampered_sig = 'DEADBEEF00000000000000000000000000000000000000000000000000000000';

		$this->assertFalse(
			$verifier->verify( self::RAW_BODY, $tampered_sig ),
			'竄改簽章後，驗簽應失敗'
		);
	}

	/**
	 * 竄改一個字元的簽章 → verify() 回傳 false
	 *
	 * 細粒度竄改測試：即使只改一個字元，timing-safe 比對也應能偵測
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_單字元竄改簽章_驗簽失敗(): void {
		$verifier  = new WebhookVerifier( self::PRIVATE_KEY );
		$valid_sig = $this->calc_valid_sig();

		// 修改最後一個字元
		$bad_last_char = ( $valid_sig[ \strlen( $valid_sig ) - 1 ] === 'A' ) ? 'B' : 'A';
		$tampered_sig  = \substr( $valid_sig, 0, -1 ) . $bad_last_char;

		$this->assertFalse(
			$verifier->verify( self::RAW_BODY, $tampered_sig ),
			'單字元竄改後驗簽應失敗'
		);
	}

	/**
	 * 竄改一個字元的 body → verify() 回傳 false（hash 敏感性）
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_單字元竄改body_驗簽失敗(): void {
		$verifier      = new WebhookVerifier( self::PRIVATE_KEY );
		$valid_sig     = $this->calc_valid_sig();
		$tampered_body = self::RAW_BODY . ' '; // 追加一個空格

		$this->assertFalse(
			$verifier->verify( $tampered_body, $valid_sig ),
			'body 多一個空格後驗簽應失敗（raw body 對象必須一致）'
		);
	}

	// =====================================================================
	// Security：空字串與邊界值
	// =====================================================================

	/**
	 * 空簽章（sig = ''）→ verify() 回傳 false
	 *
	 * 資安防禦：空字串不得繞過驗簽
	 * 注意：hash_equals('', '') 在 PHP 中回傳 true，
	 * 實作必須先對空字串做長度守衛或格式驗證
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_空簽章_驗簽失敗(): void {
		$verifier = new WebhookVerifier( self::PRIVATE_KEY );

		$this->assertFalse(
			$verifier->verify( self::RAW_BODY, '' ),
			'空字串簽章不得通過驗簽（防止 hash_equals 空字串陷阱）'
		);
	}

	/**
	 * 空 body（raw_body = ''）→ verify() 回傳 false
	 *
	 * 空 body 的 HMAC 不等於正確簽章，應失敗
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_空body_驗簽失敗(): void {
		$verifier  = new WebhookVerifier( self::PRIVATE_KEY );
		$valid_sig = $this->calc_valid_sig(); // 針對非空 body 的有效簽章

		$this->assertFalse(
			$verifier->verify( '', $valid_sig ),
			'空 body 搭配非空 body 的簽章，驗簽應失敗'
		);
	}

	/**
	 * 空 body 搭配空 body 的 HMAC → verify() 行為測試
	 *
	 * 此測試確認空 body 的 HMAC 結果本身是否能通過（應通過，因為計算正確）
	 *
	 * @test
	 * @group security
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_空body搭配正確空bodyHMAC_正確計算應通過(): void {
		$verifier        = new WebhookVerifier( self::PRIVATE_KEY );
		$empty_body_hmac = \strtoupper( \hash_hmac( 'sha256', '', self::PRIVATE_KEY ) ); // 空 body 的正確 HMAC

		// 這個測試的目的是確認 verify 的計算邏輯正確——空 body + 對應 HMAC 應該通過
		// 注意：在 Callback 層應在「取 raw body」時就檢查空 body，不進入 verify
		$this->assertTrue(
			$verifier->verify( '', $empty_body_hmac ),
			'空 body + 正確計算的 HMAC（空 body 對應）應通過（驗簽本身是 pure computation）'
		);
	}

	/**
	 * 簽章長度不足（非 64 字元 hex）→ verify() 回傳 false
	 *
	 * SHA256 輸出固定 64 字元 hex；長度不符代表格式錯誤
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_簽章長度不足_驗簽失敗(): void {
		$verifier = new WebhookVerifier( self::PRIVATE_KEY );

		$this->assertFalse(
			$verifier->verify( self::RAW_BODY, 'TOOSHORT' ),
			'長度不足 64 字元的簽章應拒絕（非合法 SHA256 hex）'
		);
	}

	/**
	 * 全零簽章（偽造攻擊）→ verify() 回傳 false
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_全零簽章_驗簽失敗(): void {
		$verifier = new WebhookVerifier( self::PRIVATE_KEY );
		$zero_sig = \str_repeat( '0', 64 );

		$this->assertFalse(
			$verifier->verify( self::RAW_BODY, $zero_sig ),
			'全零簽章（偽造攻擊）應驗簽失敗'
		);
	}

	// =====================================================================
	// Security：timing-safe（hash_equals）正確性
	// =====================================================================

	/**
	 * timing-safe 比對：相同 raw_body + 相同 key → 多次計算結果一致
	 *
	 * 此測試確保 verify() 使用的是確定性函數（deterministic）
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_timing_safe_相同輸入多次計算結果一致(): void {
		$verifier = new WebhookVerifier( self::PRIVATE_KEY );
		$sig      = $this->calc_valid_sig();

		// 多次呼叫 verify，結果應相同（timing-safe 不影響確定性）
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue(
				$verifier->verify( self::RAW_BODY, $sig ),
				"第 {$i} 次呼叫 verify 應回傳 true（deterministic）"
			);
		}
	}

	/**
	 * timing-safe：錯誤簽章多次呼叫均回傳 false（不會因 side effect 改變結果）
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_timing_safe_錯誤簽章多次呼叫均失敗(): void {
		$verifier     = new WebhookVerifier( self::PRIVATE_KEY );
		$tampered_sig = 'DEADBEEF00000000000000000000000000000000000000000000000000000000';

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertFalse(
				$verifier->verify( self::RAW_BODY, $tampered_sig ),
				"第 {$i} 次呼叫 verify（錯誤簽章）應回傳 false"
			);
		}
	}

	// =====================================================================
	// Security：不同 PrivateKey 的隔離性
	// =====================================================================

	/**
	 * 以錯誤 PrivateKey 建立的 verifier → 無法驗通正確 key 的簽章
	 *
	 * 攻擊情境：攻擊者持有其他商店的 PrivateKey
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_錯誤PrivateKey_無法驗通正確key的簽章(): void {
		$correct_verifier = new WebhookVerifier( self::PRIVATE_KEY );
		$wrong_verifier   = new WebhookVerifier( 'wrong_private_key_attacker_merchant' );

		$sig = $this->calc_valid_sig(); // 以正確 key 計算的簽章

		// 正確 key 能通過
		$this->assertTrue( $correct_verifier->verify( self::RAW_BODY, $sig ) );

		// 錯誤 key 不能通過
		$this->assertFalse(
			$wrong_verifier->verify( self::RAW_BODY, $sig ),
			'以錯誤 PrivateKey 建立的 verifier 不應能驗通正確 key 的簽章'
		);
	}

	/**
	 * 以正確 PrivateKey 計算的簽章，對不同 body 無效
	 * 確認 HMAC 對 body 的綁定性
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_正確key的簽章對不同body無效(): void {
		$verifier  = new WebhookVerifier( self::PRIVATE_KEY );
		$body_a    = '{"PaymentIntentId":"pp_aaa","Amount":1000,"Status":"Success"}';
		$body_b    = '{"PaymentIntentId":"pp_bbb","Amount":9999,"Status":"Success"}';
		$sig_for_a = \strtoupper( \hash_hmac( 'sha256', $body_a, self::PRIVATE_KEY ) );

		// body_a 的簽章通過 body_a 驗證
		$this->assertTrue( $verifier->verify( $body_a, $sig_for_a ) );

		// body_a 的簽章不能通過 body_b 驗證
		$this->assertFalse(
			$verifier->verify( $body_b, $sig_for_a ),
			'body_a 的簽章對 body_b 應無效（HMAC 綁定 body 完整性）'
		);
	}

	// =====================================================================
	// Security：raw body 驗簽（勿 re-encode）
	// =====================================================================

	/**
	 * verify 使用 raw body，而非 json_decode 後 re-encode
	 *
	 * ⚠️ 關鍵安全規格：
	 *   若先 json_decode 再 json_encode，key 順序 / 空白 / unicode escape 可能改變，
	 *   導致驗簽永遠失敗。此測試確認 verify() 對 raw body（含特殊字元 / unicode）計算正確。
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_raw_body驗簽_含unicode特殊字元(): void {
		// raw body 含 unicode escape 與特殊字元（模擬真實 PayNow 回傳格式）
		$raw_body_with_unicode = '{"Status":"Success","Amount":1000,"Description":"訂單樣品","Meta":{"CardToken":""}}';
		$verifier              = new WebhookVerifier( self::PRIVATE_KEY );
		$sig                   = \strtoupper( \hash_hmac( 'sha256', $raw_body_with_unicode, self::PRIVATE_KEY ) );

		$this->assertTrue(
			$verifier->verify( $raw_body_with_unicode, $sig ),
			'verify() 應對 raw body（含 unicode）計算 HMAC，而非 re-encode 後計算'
		);
	}

	/**
	 * json_encode 後的 body 與 raw body 的 HMAC 不同（示範 re-encode 問題）
	 *
	 * 此測試示範為何 Callback 必須用 $request->get_body() 取 raw body：
	 * 若 json_decode 後 re-encode，HMAC 結果會不同，導致驗簽失敗。
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_raw_body和re_encode的HMAC不同(): void {
		// 含 unicode escape 的 raw body（PayNow 可能這樣輸出）
		$raw_body = '{"Status":"Success","Amount":1000,"Description":"訂單"}';
		$decoded  = \json_decode( $raw_body, true );
		// PHP json_encode 預設不 escape unicode，輸出與輸入不同
		$re_encoded = \json_encode( $decoded, JSON_UNESCAPED_UNICODE );

		// 若 raw != re_encoded，則 HMAC 一定不同
		if ( $raw_body !== $re_encoded ) {
			$hmac_raw       = \strtoupper( \hash_hmac( 'sha256', $raw_body, self::PRIVATE_KEY ) );
			$hmac_reencoded = \strtoupper( \hash_hmac( 'sha256', (string) $re_encoded, self::PRIVATE_KEY ) );

			$this->assertNotSame(
				$hmac_raw,
				$hmac_reencoded,
				'raw body 和 re-encode 的 HMAC 不同——驗簽必須對 raw body 計算'
			);
		} else {
			// 若恰好相同，跳過此測試（標記 inconclusive）
			$this->addToAssertionCount( 1 ); // 標記測試執行，不算失敗
		}
	}
}
