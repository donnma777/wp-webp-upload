<?php
/**
 * Plugin Name: WebP Upload
 * Description: アップロードした JPEG / PNG を WebP にして軽くします（元の JPEG / PNG は残しません）。サムネイルなどのサイズ違いも WebP で作ります。
 * Version: 1.0.0
 * Author: donnma
 * Plugin URI: https://github.com/donnma777/wp-webp-upload
 * License: GPLv2 or later
 * Requires PHP: 8.0
 *
 * ルール
 *   ・WebP にしたほうが大きくなるときは、元のままにする
 *   ・アニメーション GIF・SVG・すでに WebP のものは、そのまま
 *   ・ファイル名の最後が「-orig」なら変換しない（例: logo-orig.png）
 *   ・大きい画像（2560px 超）は WordPress 本体が縮めた版（-scaled）も作る。位置情報などの EXIF は残らない
 *   ・メディアの「フォルダにアップロード」（Folder Upload：https://github.com/donnma777/wp-folder-upload）で置いたファイルは変換しない（置いたままの形で使うため）
 *
 * 注意：サーバーによっては Imagick が WebP を書き出せず、黙って JPEG で保存してしまうことがある。
 *       そのため WebP を「書き出せる」エディター（GD）を選び、本当に WebP になったときだけ元を消す。
 */

if (!defined('ABSPATH')) exit;

add_filter('wp_handle_upload', 'dwu_convert_upload', 10, 2);

function dwu_convert_upload($upload, $context) {
	$type = $upload['type'] ?? '';
	$file = $upload['file'] ?? '';
	if (!in_array($type, ['image/jpeg', 'image/png'], true) || !$file || !is_file($file)) return $upload;
	if (preg_match('/-orig\.(jpe?g|png)$/i', $file)) return $upload;
	$args = ['mime_type' => $type, 'output_mime_type' => 'image/webp'];
	if (!wp_image_editor_supports($args)) return $upload;
	$editor = wp_get_image_editor($file, $args);
	if (is_wp_error($editor)) return $upload;
	$editor->set_quality($type === 'image/png' ? 90 : 82);
	$dir = dirname($file);
	$name = wp_unique_filename($dir, preg_replace('/\.(jpe?g|png)$/i', '.webp', wp_basename($file)));
	$saved = $editor->save($dir . '/' . $name, 'image/webp');
	if (is_wp_error($saved) || empty($saved['path']) || !is_file($saved['path'])) return $upload;
	// 本当に WebP になったときだけ元を消す（違う形式で保存されたら、作ったものを消して元のまま）
	$real = wp_get_image_mime($saved['path']);
	if ($real !== 'image/webp' || !preg_match('/\.webp$/i', $saved['path']) || realpath($saved['path']) === realpath($file)) {
		if (realpath($saved['path']) !== realpath($file)) @unlink($saved['path']);
		return $upload;
	}
	if (filesize($saved['path']) >= filesize($file)) {
		@unlink($saved['path']);
		return $upload;
	}
	@unlink($file);
	$upload['file'] = $saved['path'];
	$upload['url']  = trailingslashit(dirname($upload['url'])) . wp_basename($saved['path']);
	$upload['type'] = 'image/webp';
	return $upload;
}

// サイズ違い（サムネイルなど）を WebP で作るときの画質
add_filter('wp_editor_set_quality', function ($quality, $mime) {
	return $mime === 'image/webp' ? 82 : $quality;
}, 10, 2);
