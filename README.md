# WebP Upload

WordPress でアップロードした JPEG / PNG を、自動で WebP に変換して軽くするプラグインです。サムネイルなどのサイズ違いも WebP で作ります。設定画面はなく、有効化するだけで動きます。

## ルール

- 変換したら、元の JPEG / PNG は残しません
- WebP にしたほうが大きくなるときは、元のままにします
- アニメーション GIF・SVG・すでに WebP のものは、そのままです
- ファイル名の最後が `-orig` なら変換しません（例: `logo-orig.png`）
- 画質は JPEG からは 82、PNG からは 90 です
- 大きい画像（2560px 超）は、WordPress 本体が縮めた版（`-scaled`）も作ります。位置情報などの EXIF は残りません

サーバーによっては、Imagick が WebP を書き出せずに黙って JPEG で保存してしまうことがあります。そのため、WebP を書き出せるエディター（GD など）を選び、本当に WebP になったときだけ元のファイルを消します。WebP を書き出せないサーバーでは、何もせず元のまま置きます。

## インストール

1. [Releases](https://github.com/donnma777/wp-webp-upload/releases) から Source code (zip) をダウンロード
2. WordPress の管理画面「プラグイン → 新規追加 → プラグインのアップロード」で zip を選び、有効化

有効化する前にアップロードした画像は変換しません。

## 動作環境

- WordPress 6.0 以上
- PHP 8.0 以上
- WebP を書き出せる GD または Imagick

## 一緒に使うと便利なもの

[Folder Upload](https://github.com/donnma777/wp-folder-upload) で置いたファイルは変換しません（置いたままの形で使うため）。

## 注意

- 元の JPEG / PNG は消えます。元の画像は手元に残しておいてください
- 個人で作ったものです。サポートや不具合対応のお約束はできません

## ライセンス

GPLv2 or later
