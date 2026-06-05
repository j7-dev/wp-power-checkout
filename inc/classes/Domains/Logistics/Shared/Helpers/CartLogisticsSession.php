<?php
/**
 * 購物車（cart / session）級物流選店暫存與權杖綁定
 *
 * 第一性原理：超商選店發生在「結帳下單前」，此時無 WC 訂單，既有 order-bound 流程
 * （pc_oid + order_key）無法使用。本 helper 提供 cart-bound 替代路徑：
 *
 *   1. 產生不可猜測的「選店權杖」（wp_generate_password 64 字元）→ 存入 WC session
 *      （鍵 SESSION_TOKEN_KEY），並編入 RedirectToLogisticsSelection 的 ClientReplyURL（pc_st）。
 *   2. 同時建立「token → WC session customer_id」的 transient 索引（TTL 15 分鐘），
 *      讓選店回呼（綠界 RWD 頁瀏覽器 POST，可能不帶 cookie / 跨請求）能反查到正確 session。
 *   3. 選店回呼以 token timing-safe 比對（hash_equals）驗證綁定後，將門市資訊寫入「該 session」
 *      （非 order meta，因尚無訂單）。
 *   4. 下單時（block: woocommerce_store_api_checkout_order_processed / classic:
 *      woocommerce_checkout_create_order）把 session 暫存門市搬進 order meta，並清除 session + 索引。
 *
 * 安全特性：
 *   - 權杖以 wp_generate_password 產生（CSPRNG），長度 64，不可猜測。
 *   - 權杖與單一 WC session 綁定（透過 customer_id 索引），無法跨 session 重放。
 *   - 比對一律 hash_equals（timing-safe），杜絕時序側信道。
 *   - 索引有 TTL（15 分鐘），逾時自動失效，限制權杖生命週期。
 *
 * ⚠️ 與 order-bound 路徑（LogisticsMetaKeys）並存：兩條路徑寫入相同的 order meta key，
 *    故下游建單 / 貨態 callback 完全一致。本 helper 僅負責「下單前」的 session 暫存與搬移。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Helpers;

/** 購物車級物流選店暫存與權杖綁定 */
final class CartLogisticsSession {

	/** @var string WC session 內存放選店權杖的鍵 */
	private const SESSION_TOKEN_KEY = 'pc_logistics_selection_token';

	/** @var string WC session 內存放暫存門市資訊的鍵 */
	private const SESSION_STORE_KEY = 'pc_logistics_selected_store';

	/** @var string token → customer_id 索引 transient 前綴 */
	private const TOKEN_INDEX_PREFIX = 'pc_lg_sel_';

	/** @var int 索引 TTL（秒）；綠界 RWD 選店頁停留 + 回呼，15 分鐘足夠 */
	private const TOKEN_TTL = 900;

	/** @var int 權杖長度（字元數） */
	private const TOKEN_LENGTH = 64;

	/**
	 * 產生並綁定新的選店權杖（存入當前 WC session + 建立索引）
	 *
	 * 每次重新發起選店都產生新權杖（取代舊權杖），避免舊權杖殘留。
	 *
	 * @return string 新權杖（編入 ClientReplyURL 的 pc_st）
	 * @throws \RuntimeException WC session 不可用
	 */
	public static function issue_token(): string {
		$session = self::get_session();
		if (null === $session) {
			throw new \RuntimeException( 'WC session 不可用，無法產生選店權杖' );
		}

		// CSPRNG 不可猜測權杖（不含特殊字元，URL-safe）
		$token = \wp_generate_password( self::TOKEN_LENGTH, false, false );

		$session->set( self::SESSION_TOKEN_KEY, $token );
		self::save_session( $session );

		// 建立 token → customer_id 索引，供無 cookie 的回呼反查 session
		$customer_id = (string) $session->get_customer_id();
		\set_transient( self::TOKEN_INDEX_PREFIX . $token, $customer_id, self::TOKEN_TTL );

		return $token;
	}

	/**
	 * 以權杖反查並 timing-safe 驗證對應的 WC session customer_id
	 *
	 * 回呼端（可能不帶 cookie）以 URL 帶回的 token：
	 *   1. 查 transient 索引取得 customer_id（查無 → null）。
	 *   2. 讀該 session 內真正儲存的權杖，hash_equals timing-safe 比對（不符 → null）。
	 *
	 * @param string $token URL 帶回的權杖（pc_st）
	 * @return string|null 驗證通過的 customer_id，否則 null
	 */
	public static function resolve_customer_id( string $token ): ?string {
		if ('' === \trim( $token )) {
			return null;
		}

		$customer_id = \get_transient( self::TOKEN_INDEX_PREFIX . $token );
		if (!\is_string( $customer_id ) || '' === $customer_id) {
			return null;
		}

		// 讀該 session 真正儲存的權杖，timing-safe 比對
		$stored = self::read_session_value( $customer_id, self::SESSION_TOKEN_KEY );
		if (!\is_string( $stored ) || '' === $stored) {
			return null;
		}

		if (!\hash_equals( $stored, $token )) {
			return null;
		}

		return $customer_id;
	}

	/**
	 * 以權杖驗證後，將門市資訊寫入對應 session
	 *
	 * @param string                $token URL 帶回的權杖
	 * @param array<string, string> $store 門市資訊（temp_id / store_id / store_name / store_addr / sub_type）
	 * @return bool 是否成功寫入（權杖驗證失敗回 false）
	 */
	public static function store_by_token( string $token, array $store ): bool {
		$customer_id = self::resolve_customer_id( $token );
		if (null === $customer_id) {
			return false;
		}

		$payload = [
			'temp_id'    => (string) ( $store['temp_id'] ?? '' ),
			'store_id'   => (string) ( $store['store_id'] ?? '' ),
			'store_name' => (string) ( $store['store_name'] ?? '' ),
			'store_addr' => (string) ( $store['store_addr'] ?? '' ),
			'sub_type'   => (string) ( $store['sub_type'] ?? '' ),
		];

		return self::write_session_value( $customer_id, self::SESSION_STORE_KEY, $payload );
	}

	/**
	 * 讀取「當前 WC session」暫存的門市資訊（結帳頁 cart extensions / 下單搬 meta 用）
	 *
	 * @return array<string, string>|null 門市資訊，無則 null
	 */
	public static function get_selected_store(): ?array {
		$session = self::get_session();
		if (null === $session) {
			return null;
		}
		return self::normalize_store( $session->get( self::SESSION_STORE_KEY ) );
	}

	/**
	 * 清除「當前 WC session」的選店暫存（權杖 + 門市 + 索引）
	 *
	 * 下單搬 meta 後呼叫，避免殘留影響下一筆訂單。
	 *
	 * @return void
	 */
	public static function clear(): void {
		$session = self::get_session();
		if (null === $session) {
			return;
		}

		$token = $session->get( self::SESSION_TOKEN_KEY );
		if (\is_string( $token ) && '' !== $token) {
			\delete_transient( self::TOKEN_INDEX_PREFIX . $token );
		}

		$session->set( self::SESSION_TOKEN_KEY, null );
		$session->set( self::SESSION_STORE_KEY, null );
		self::save_session( $session );
	}

	// region 內部 session 存取（含跨請求以 customer_id 反查）

	/**
	 * 取得當前 WC session（含 handler）
	 *
	 * ⚠️ WC()->session 執行期實為 WC_Session_Handler（含 save_data / get_cache_prefix），
	 * 但於 stub 型別為抽象 WC_Session；故回傳前 instanceof 收斂為具體 handler。
	 *
	 * @return \WC_Session_Handler|null
	 */
	private static function get_session(): ?\WC_Session_Handler {
		if (!\function_exists( 'WC' )) {
			return null;
		}
		/** @var \WC_Session_Handler|\WC_Session|null $session */
		$session = \WC()->session;
		if ($session instanceof \WC_Session_Handler) {
			return $session;
		}
		return null;
	}

	/**
	 * 儲存 session（save_data 為 WC_Session_Handler 提供）
	 *
	 * @param \WC_Session_Handler $session session handler
	 * @return void
	 */
	private static function save_session( \WC_Session_Handler $session ): void {
		$session->save_data();
	}

	/**
	 * 以 customer_id 跨請求讀取指定 session 鍵（回呼端無 cookie 時使用）
	 *
	 * @param string $customer_id WC session customer_id
	 * @param string $key         session 鍵
	 * @return mixed
	 */
	private static function read_session_value( string $customer_id, string $key ) {
		// 優先：當前 session 的 customer_id 即為目標（同 session）
		$session = self::get_session();
		if (null !== $session && (string) $session->get_customer_id() === $customer_id) {
			return $session->get( $key );
		}

		// 跨請求：以 handler 反查 DB 內 session 資料
		$data = self::get_raw_session_data( $customer_id );
		if (null === $data || !\array_key_exists( $key, $data )) {
			return null;
		}
		$raw = $data[ $key ];
		return \is_string( $raw ) ? \maybe_unserialize( $raw ) : $raw;
	}

	/**
	 * 以 customer_id 跨請求寫入指定 session 鍵
	 *
	 * 同 session（cookie 命中）→ 直接 set + save_data。
	 * 跨 session（回呼無 cookie）→ 讀 DB raw session，合併後以 handler 回寫。
	 *
	 * @param string $customer_id WC session customer_id
	 * @param string $key         session 鍵
	 * @param mixed  $value       值
	 * @return bool
	 */
	private static function write_session_value( string $customer_id, string $key, $value ): bool {
		$session = self::get_session();

		// 同 session：直接寫
		if (null !== $session && (string) $session->get_customer_id() === $customer_id) {
			$session->set( $key, $value );
			$session->save_data();
			return true;
		}

		// 跨 session：以 handler 直接讀寫 DB（回呼端不帶該 session cookie）
		$handler = self::get_handler();
		if (null === $handler) {
			return false;
		}

		$data         = self::get_raw_session_data( $customer_id ) ?? [];
		$data[ $key ] = \maybe_serialize( $value );

		return self::persist_raw_session_data( $handler, $customer_id, $data );
	}

	/**
	 * 以 handler 取得 DB 內某 customer_id 的 raw session 陣列
	 *
	 * @param string $customer_id WC session customer_id
	 * @return array<string, mixed>|null
	 */
	private static function get_raw_session_data( string $customer_id ): ?array {
		$handler = self::get_handler();
		if (null === $handler) {
			return null;
		}
		$raw = $handler->get_session( $customer_id, false );
		if (!\is_array( $raw )) {
			return null;
		}
		/** @var array<string, mixed> $raw */
		return $raw;
	}

	/**
	 * 以 handler 將 raw session 陣列回寫 DB（跨請求寫入）
	 *
	 * WC_Session_Handler 無公開「以任意 customer_id 寫入」API，故直接 UPSERT session 表。
	 *
	 * @param \WC_Session_Handler  $handler     handler（取表名 / 快取前綴）
	 * @param string               $customer_id WC session customer_id
	 * @param array<string, mixed> $data        完整 session 資料陣列
	 * @return bool
	 */
	private static function persist_raw_session_data( \WC_Session_Handler $handler, string $customer_id, array $data ): bool {
		global $wpdb;
		if (!isset( $wpdb )) {
			return false;
		}

		$table      = "{$wpdb->prefix}woocommerce_sessions";
		$expiration = \time() + ( 48 * 3600 );

		// 直接 UPSERT WC 標準 session 表（回呼端不帶該 session cookie，無法走 WC()->session）；
		// 寫入後立即清快取（見 invalidate_session_cache）。表名為 WC 標準表常數，非使用者輸入。
		$sql = "INSERT INTO {$table} (`session_key`, `session_value`, `session_expiry`) VALUES (%s, %s, %d)
			ON DUPLICATE KEY UPDATE `session_value` = VALUES(`session_value`), `session_expiry` = VALUES(`session_expiry`)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare( $sql, $customer_id, \maybe_serialize( $data ), $expiration ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		// 同步清除該 customer_id 的 session 物件快取（避免讀到舊值）
		self::invalidate_session_cache( $customer_id );

		return false !== $result;
	}

	/**
	 * 清除指定 customer_id 的 WC session 物件快取
	 *
	 * WC_Session_Handler::get_cache_prefix() 為 private，故改用其等價的公開組合
	 * （WC_Cache_Helper::get_cache_prefix(WC_SESSION_CACHE_GROUP)）求得相同快取 key。
	 *
	 * @param string $customer_id WC session customer_id
	 * @return void
	 */
	private static function invalidate_session_cache( string $customer_id ): void {
		if (!\class_exists( '\WC_Cache_Helper' ) || !\defined( 'WC_SESSION_CACHE_GROUP' )) {
			return;
		}
		$group  = (string) \constant( 'WC_SESSION_CACHE_GROUP' );
		$prefix = (string) \WC_Cache_Helper::get_cache_prefix( $group );
		\wp_cache_delete( $prefix . $customer_id, $group );
	}

	/**
	 * 取得 WC session handler（用於跨請求 DB 反查）
	 *
	 * 當前 session 為 handler 時直接用；否則新建一個（僅用於 DB 反查，不影響當前 session）。
	 *
	 * @return \WC_Session_Handler|null
	 */
	private static function get_handler(): ?\WC_Session_Handler {
		$session = self::get_session();
		if (null !== $session) {
			return $session;
		}
		if (\class_exists( '\WC_Session_Handler' )) {
			return new \WC_Session_Handler();
		}
		return null;
	}

	/**
	 * 正規化門市資訊（過濾非陣列 / 空門市）
	 *
	 * @param mixed $raw session 內讀回的值
	 * @return array<string, string>|null
	 */
	private static function normalize_store( $raw ): ?array {
		if (!\is_array( $raw )) {
			return null;
		}
		$store_id = (string) ( $raw['store_id'] ?? '' );
		if ('' === $store_id) {
			return null;
		}
		return [
			'temp_id'    => (string) ( $raw['temp_id'] ?? '' ),
			'store_id'   => $store_id,
			'store_name' => (string) ( $raw['store_name'] ?? '' ),
			'store_addr' => (string) ( $raw['store_addr'] ?? '' ),
			'sub_type'   => (string) ( $raw['sub_type'] ?? '' ),
		];
	}

	// endregion
}
