<?php

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Helper;

use J7\PowerCheckoutTests\Utils\STDOUT;

/**
 * User class
 * 1. 實例化 User 類別時，會自動創建 test 用戶
 * 2. 有 create 跟 delete 方法
 */
class User extends \WP_UnitTestCase
{
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var \WP_User */
	public $user;

	public function __construct()
	{
		$this->create();
	}

	/**
	 * 創建 test 用戶
	 */
	public function create($user_login = 'testtest', $user_email = 'testtest@example.com', $role = 'customer'):self
	{
		$user = self::factory()->user->create_and_get([
			'role' => $role,
			'user_login' => $user_login,
			'user_email' => $user_email
		]);

		$this->user = $user;

		STDOUT::ok('用戶創建成功: #' . $this->user->ID);

		return $this;
	}

	/**
	 * 測試結束後 刪除 test 用戶
	 */
	public function tear_down()
	{
		parent::tear_down();
		self::delete_user($this->user->ID);
	}
}
