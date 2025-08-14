<?php

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Helper;

use J7\PowerCheckoutTests\Shared\Resource;
use J7\PowerCheckoutTests\Utils\STDOUT;


/**
 * User class
 */
class User extends Resource{
	use \J7\WpUtils\Traits\SingletonTrait;
    
    /** @var string 資源標籤 */
    protected string $label = '用戶';

	/** @var \WP_User[] 測試用戶 */
	public array $items = [];

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
				'user_login' => 'phpunit',
				'user_pass'  => '123456',
				'role'       => 'customer',
			];
			$args         = \wp_parse_args($args, $default_args);
            
            $args['first_name'] .= "_{$i}";
            $args['user_login'] .= "_{$i}";
			$args['user_email'] = $args['user_login'] . '@example.com';

			$user_id       = \wp_insert_user($args);
			$this->items[] = new \WP_User($user_id);
		}

		$ids = array_map( static fn( $user ) => "#{$user->ID}", $this->items);
		STDOUT::ok("創建 {$qty} 個用戶成功: " . implode(', ', $ids));

		return $this;
	}
 

	/** 測試結束後 刪除 test 用戶 */
	public function tear_down(): void {
		$count = count($this->items);
		$ids   = array_map(fn( $user ) => "#{$user->ID}", $this->items);
		foreach ($this->items as $user) {
			\wp_delete_user($user->ID);
		}
		STDOUT::ok("刪除 {$count} 個用戶成功: " . implode(', ', $ids));
		$this->items = [];
	}
}
