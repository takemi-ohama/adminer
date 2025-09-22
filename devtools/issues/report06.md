なるほど！
今度は Intelephense の **P1010: Undefined function 'apcu\_exists'** ですね。

これは **APCu 拡張がインストールされていない / Intelephense に認識されていない** ために出ています。
実行環境に APCu があっても、Intelephense は「関数のシグネチャを知らない」ので警告を出します。

---

## 解決方法

### ✅ 実際に APCu を使う予定がある場合

1. **APCu 拡張をインストール**
   Linux + PHP なら：

   ```bash
   sudo apt-get install php-apcu
   # または
   pecl install apcu
   ```

   その後 `php.ini` に追記：

   ```ini
   extension=apcu.so
   ```

2. **Intelephense に APCu の stubs を認識させる**
   APCu の `.php` スタブファイルを用意して `intelephense.stubs` に登録します。

   例: `.vscode/settings.json` に追加

   ```json
   {
     "intelephense.stubs": [
       "apache",
       "bcmath",
       "bz2",
       "calendar",
       "com_dotnet",
       "Core",
       "apcu"
     ]
   }
   ```

   👉 `apcu` がデフォルトに含まれていないので、手動で追加します。

   APCu の stubs は GitHub や Packagist にあるのでダウンロードして `vendor/php-stubs/apcu-stubs` などに入れるとよいです。
   （`composer require --dev php-stubs/apcu-stubs` で簡単に導入可能）

---
