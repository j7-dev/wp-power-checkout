<?php

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Helper;

use J7\PowerCheckoutTests\Utils\STDOUT;

/**
 * User class
 */
class User {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var \WP_User[] 測試用戶 */
	public array $users = [];

	/**
	 * 創建 test 用戶
	 *
	 * @param array $args 用戶資料
	 * @param int   $qty 創建數量
	 * @return self
	 */
	public function create( array $args = [], int $qty = 1 ): self {
		for ($i = 0; $i < $qty; $i++) {
			$default_args = [
				'first_name' => '明輝',
				'last_name'  => '陳',
				'user_login' => 'pest',
				'user_pass'  => '123456',
				'role'       => 'customer',
			];
			$args         = \wp_parse_args($args, $default_args);

			$args['first_name'] = $args['first_name'] . "_{$i}";
			$args['user_login'] = $args['user_login'] . "_{$i}";
			$args['user_email'] = $args['user_login'] . '@example.com';

			$user_id       = \wp_insert_user($args);
			$this->users[] = new \WP_User($user_id);
		}

		$ids = array_map(fn( $user ) => "#{$user->ID}", $this->users);
		STDOUT::ok("創建 {$qty} 個用戶成功: " . implode(', ', $ids));

		return $this;
	}

	/**
	 * 取得用戶，預設隨機
	 *
	 * @param string|int $index_or_type 用戶類型或索引
	 * @return \WP_User
	 */
	public function get_user( string|int $index_or_type = 'random' ): \WP_User {
		if (\is_numeric($index_or_type)) {
			return $this->users[ $index_or_type ];
		}

		// if ('random' === $type_or_index) {
		return $this->users[ array_rand($this->users) ];
		// }
	}

	/** 測試結束後 刪除 test 用戶 */
	public function tear_down(): void {
		$count = count($this->users);
		$ids   = array_map(fn( $user ) => "#{$user->ID}", $this->users);
		foreach ($this->users as $user) {
			\wp_delete_user($user->ID);
		}
		STDOUT::ok("刪除 {$count} 個用戶成功: " . implode(', ', $ids));
		$this->users = [];
	}
}
