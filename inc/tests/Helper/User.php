<?php

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Helper;

use J7\PowerCheckoutTests\Utils\STDOUT;
use J7\PowerCheckoutTests\Utils\WC_UnitTestCase;

/**
 * User class
 * 1. 實例化 User 類別時，會自動創建 test 用戶
 * 2. 有 create 跟 delete 方法
 */
class User extends WC_UnitTestCase
{
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var \WP_User */
	public \WP_User $user;


	/**
	 * 創建 test 用戶
	 */
	public function create($user_login = 'testtest', $user_email = 'testtest@example.com', $role = 'customer'):self
	{

		$user_id = \wp_insert_user([
			'first_name' => '明輝',
			'last_name' => '陳',
			'user_login' => $user_login,
			'user_email' => $user_email,
			'user_pass' => '123456',
			'role' => $role
		]);

		$this->user = new \WP_User($user_id);

		STDOUT::ok('用戶創建成功: #' . $this->user->ID);

		return $this;
	}

	/**
	 * 測試結束後 刪除 test 用戶
	 */
	public function tear_down()
	{
		\wp_delete_user($this->user->ID);
	}
}
