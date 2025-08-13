****# PHPUnit 測試教學

本教學將介紹如何在 WordPress 專案中使用 PHPUnit，並說明 @testdox、@group、@dataProvider、@depends 等常用註解的用法。

---

## 1. @testdox：自訂測試說明

`@testdox` 可用來自訂測試方法的說明，讓測試報告更易讀。

```php
/**
 * @testdox 測試 do_action 函式是否存在
 */
public function test_do_action_exist()
{
    $this->assertTrue(function_exists('do_action'));
}
```

執行測試時，會顯示「測試 do_action 函式是否存在」而不是方法名稱。

---

## 2. @group：分組與多組

`@group` 可將測試分組，方便只執行特定群組。可定義多個 group。

```php
/**
 * @testdox 測試 trim 函式
 * @group debug
 * @group string
 */
public function testTrim($expected, $input): void
{
    $this->assertSame($expected, trim($input));
}
```

執行特定 group 測試：

```bash
phpunit --group debug
```

---

## 3. @dataProvider：資料提供者

`@dataProvider` 可讓一個測試方法用多組資料重複執行。

```php
/**
 * @testdox 測試 trim 函式
 * @dataProvider provideTrimData
 */
public function testTrim($expected, $input): void
{
    $this->assertSame($expected, trim($input));
}

public function provideTrimData(): array
{
    return [
        'leading space' => ['Hello World', ' Hello World'],
        'newline trimmed' => ['Hello World', "Hello World\n"],
        'spaces removed' => ['HelloWorld', ' HelloWorld'],
    ];
}
```

---

## 4. @depends：測試依賴

`@depends` 可讓一個測試依賴另一個測試的結果，並取得其回傳值。

```php
public function testUserCanBeCreated(): User
{
    $user = new User('Jerry');
    $this->assertNotNull($user);
    return $user;
}

/**
 * @depends testUserCanBeCreated
 */
public function testUserHasDefaultRole(User $user): void
{
    $this->assertEquals('subscriber', $user->getRole());
}
```

---

## 5. 綜合範例

可參考 `inc/tests/ExampleTest.php`，裡面有 @testdox、@group、@dataProvider 的實際用法。

---

如需更多進階用法，可參考 [PHPUnit 官方文件](https://phpunit.de/manual/current/zh_cn/annotations.html)。

