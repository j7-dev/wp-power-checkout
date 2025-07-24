<?php

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Utils;

/** 輸出工具 */
class STDOUT {

	/** 成功 */
	public static function ok( string $message ): void {
		if (!getenv('DEBUG')) {
			return;
		}
		fwrite(STDOUT, "\n\033[32m✅ " . $message . "\033[0m\n");
	}

	/** 錯誤 */
	public static function err( string $message ): void {
		if (!getenv('DEBUG')) {
			return;
		}
		fwrite(STDOUT, "\n\033[31m❌ " . $message . "\033[0m\n");
	}

	/** 除錯 */
	public static function debug( mixed $message ): void {
		if (!getenv('DEBUG')) {
			return;
		}
		ob_start();
		var_dump($message);
		$output = ob_get_clean();
		fwrite(STDOUT, "\n\033[31m🐛 " . $output . "\033[0m\n");
	}
}
