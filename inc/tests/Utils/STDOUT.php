<?php

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Utils;

class STDOUT {
	public static function ok(string $message) {
		fwrite(STDOUT, "\n\033[32m✅ " . $message . "\033[0m\n");
	}

	public static function err(string $message) {
		fwrite(STDOUT, "\n\033[31m❌ " . $message . "\033[0m\n");
	}

	public static function debug($message) {
		ob_start();
		var_dump($message);
		$output = ob_get_clean();
		fwrite(STDOUT, "\n\033[31m🐛 " . $output . "\033[0m\n");
	}
}