<?php
/**
 * PayNow ItemName（商品名組裝）測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\ItemName
 *
 * 規格依據：
 *   - specs/open-issue/paynow-implementation-plan.md §步驟 8
 *   - .claude/skills/paynow/references/payment-rest-api.md §4.1：description <= 255 字
 *   - 比照 PayuniUniEmbed ItemName 風格
 *
 * 測試場景：
 *   - 正常商品名組裝（Happy）
 *   - description > 255 字元時截斷（Edge）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowItemNameTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\ItemName;
use Tests\Integration\TestCase;

/**
 * PayNow ItemName 測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowItemNameTest extends TestCase {

	// ========== 正常商品名組裝（Happy） ==========

	/**
	 * 可從訂單組裝商品名描述
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_從訂單組裝商品名描述(): void {
		$order = $this->create_wc_order();

		$description = ItemName::get( $order );

		$this->assertIsString( $description );
		$this->assertNotEmpty( $description );
	}

	/**
	 * 商品名描述長度不超過 255 字元
	 * 依 payment-rest-api.md §4.1：description <= 255 字
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_商品名描述長度不超過255字元(): void {
		$order = $this->create_wc_order();

		$description = ItemName::get( $order );

		$this->assertLessThanOrEqual(
			255,
			mb_strlen( $description ),
			'PayNow description 不得超過 255 字，實際：' . mb_strlen( $description )
		);
	}

	// ========== 截斷（Edge） ==========

	/**
	 * 原始內容超過 255 字元時自動截斷
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_超過255字元時自動截斷(): void {
		$long_string = str_repeat( '商品名稱', 70 ); // > 255 字元

		$result = ItemName::truncate( $long_string );

		$this->assertLessThanOrEqual(
			255,
			mb_strlen( $result ),
			'截斷後長度應 <= 255，實際：' . mb_strlen( $result )
		);
	}

	/**
	 * 255 字元以內的內容不截斷
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_255字元以內不截斷(): void {
		$short_string = '測試商品';

		$result = ItemName::truncate( $short_string );

		$this->assertSame( $short_string, $result, '短字串不應被截斷' );
	}
}
