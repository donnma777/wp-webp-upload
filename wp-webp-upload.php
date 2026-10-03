<?php
/**
 * Plugin Name: WebP Upload
 * Description: アップロードした JPEG / PNG を WebP にして軽くします。サムネイルなどのサイズ違いも WebP で作ります。元の画像を残しておけば、あとから元に戻せます。
 * Version: 1.1.0
 * Author: donnma
 * Author URI: https://donnma.com/
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
 * 元の画像（設定「元の画像を残す」がオンのとき）
 *   ・uploads/webp-upload-originals-（乱数）/ の中に、年月のフォルダごと置く。元の画像には位置情報が残っているので、
 *     フォルダ名を推測されにくくし、.htaccess でも外から見せない
 *   ・変換した画像には、元の形式・名前・置き場所を記録する（_dwu_source）。元に戻すときに使う
 *   ・元に戻すと：元の画像（無ければ WebP から変換し直したもの）を置き、サイズ違いを作り直し、記事の本文の URL を書き換えて、WebP を消す
 *
 * 注意：サーバーによっては Imagick が WebP を書き出せず、黙って JPEG で保存してしまうことがある。
 *       そのため WebP を「書き出せる」エディター（GD）を選び、本当に WebP になったときだけ元を消す。
 */

if (!defined('ABSPATH')) exit;

const DWU_SOURCE_META = '_dwu_source';

function dwu_keep_original(): bool {
	return get_option('dwu_keep_original', '1') === '1';
}

/** uploads から見た、そのファイルのフォルダ（'2026/10' など）。uploads の外なら '' */
function dwu_rel_dir(string $file): string {
	$base = wp_normalize_path(wp_get_upload_dir()['basedir']);
	$dir = wp_normalize_path(dirname($file));
	return strpos($dir . '/', $base . '/') === 0 ? trim(substr($dir, strlen($base)), '/') : '';
}

/** 元の画像を置くフォルダ。名前に乱数を付けて URL を推測されにくくする */
function dwu_backup_base(): string {
	$name = get_option('dwu_backup_dir');
	if (!is_string($name) || !preg_match('/^webp-upload-originals-[a-z0-9]{12}$/', $name)) {
		$name = 'webp-upload-originals-' . strtolower(wp_generate_password(12, false));
		update_option('dwu_backup_dir', $name, false);
	}
	return wp_normalize_path(trailingslashit(wp_get_upload_dir()['basedir']) . $name);
}

/** 元の画像を置くフォルダを用意して返す（だめなら ''）。外から見られないよう .htaccess も置く */
function dwu_prepare_backup_dir(string $sub): string {
	$base = dwu_backup_base();
	if (!wp_mkdir_p($base)) return '';
	if (!file_exists("$base/.htaccess")) {
		@file_put_contents("$base/.htaccess", "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
	}
	if (!file_exists("$base/index.php")) @file_put_contents("$base/index.php", "<?php\n// Silence is golden.\n");
	$dir = $sub === '' ? $base : "$base/$sub";
	return wp_mkdir_p($dir) ? $dir : '';
}

/** 記録にある元の画像の場所（無い・おかしいときは ''） */
function dwu_backup_path(array $src): string {
	if (empty($src['backup']) || !is_string($src['backup']) || strpos($src['backup'], '..') !== false) return '';
	$path = dwu_backup_base() . '/' . ltrim($src['backup'], '/');
	return is_file($path) && !is_link($path) ? $path : '';
}

// =====================================================
//  アップロード時に WebP へ変換する
// =====================================================
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
	// 元の画像：残す設定なら専用のフォルダへ移す。移せなかったら変換をやめる（黙って消さない）
	$source = ['mime' => $type, 'name' => wp_basename($file), 'backup' => ''];
	if (dwu_keep_original()) {
		$sub = dwu_rel_dir($file);
		$bdir = dwu_prepare_backup_dir($sub);
		$bname = $bdir ? wp_unique_filename($bdir, wp_basename($file)) : '';
		if (!$bdir || !@rename($file, "$bdir/$bname")) {
			@unlink($saved['path']);
			return $upload;
		}
		$source['backup'] = ($sub === '' ? '' : $sub . '/') . $bname;
	} else {
		@unlink($file);
	}
	$GLOBALS['dwu_pending'][wp_normalize_path($saved['path'])] = $source;
	$upload['file'] = $saved['path'];
	$upload['url']  = trailingslashit(dirname($upload['url'])) . wp_basename($saved['path']);
	$upload['type'] = 'image/webp';
	return $upload;
}

// メディアに登録されたら、変換の記録を付ける
add_action('add_attachment', function ($id) {
	if (empty($GLOBALS['dwu_pending'])) return;
	$file = get_attached_file($id, true);
	$key = $file ? wp_normalize_path($file) : '';
	if ($key !== '' && isset($GLOBALS['dwu_pending'][$key])) {
		update_post_meta($id, DWU_SOURCE_META, $GLOBALS['dwu_pending'][$key]);
		unset($GLOBALS['dwu_pending'][$key]);
	}
});

// サイズ違い（サムネイルなど）を WebP で作るときの画質
add_filter('wp_editor_set_quality', function ($quality, $mime) {
	return $mime === 'image/webp' ? 82 : $quality;
}, 10, 2);

// =====================================================
//  元に戻す
// =====================================================

/** WebP に透明な部分があるか（ファイルの頭だけ見る） */
function dwu_webp_has_alpha(string $path): bool {
	$h = (string) @file_get_contents($path, false, null, 0, 32);
	if (strlen($h) < 25 || substr($h, 0, 4) !== 'RIFF' || substr($h, 8, 4) !== 'WEBP') return false;
	$chunk = substr($h, 12, 4);
	if ($chunk === 'VP8X') return (ord($h[20]) & 0x10) !== 0;
	if ($chunk === 'VP8L') return ((unpack('V', substr($h, 21, 4))[1] >> 28) & 1) === 1;
	return false;
}

/** 記事の本文の URL を書き換える。返り値は書き換えた記事の数 */
function dwu_replace_in_posts(string $dir_url, array $map, string $needle): int {
	global $wpdb;
	$pairs = [];
	foreach ($map as $old => $new) {
		$pairs[$dir_url . '/' . $old] = $dir_url . '/' . $new;
		$enc = rawurlencode($old);
		if ($enc !== $old) $pairs[$dir_url . '/' . $enc] = $dir_url . '/' . $new;
	}
	// 長いものから置き換える（photo.webp が photo-150x150.webp の途中を置き換えないように）
	uksort($pairs, fn($a, $b) => strlen($b) <=> strlen($a));
	$likes = array_unique([$needle, rawurlencode($needle)]);
	$where = implode(' OR ', array_fill(0, count($likes), 'post_content LIKE %s'));
	$ids = $wpdb->get_col($wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type <> 'revision' AND ($where)",
		array_map(fn($l) => '%' . $wpdb->esc_like($l) . '%', $likes)
	));
	$changed = 0;
	foreach ($ids as $pid) {
		$c = get_post_field('post_content', $pid, 'raw');
		$n = strtr($c, $pairs);
		if ($n !== $c) {
			$wpdb->update($wpdb->posts, ['post_content' => $n], ['ID' => $pid]);
			clean_post_cache($pid);
			$changed++;
		}
	}
	return $changed;
}

/**
 * WebP の画像を JPEG / PNG に戻す。元の画像が残っていればそれを使い、無ければ（$reencode のときだけ）WebP から変換し直す。
 * 返り値は結果の配列か WP_Error
 */
function dwu_revert(int $id, bool $reencode) {
	if (get_post_mime_type($id) !== 'image/webp') return new WP_Error('dwu_revert', 'WebP の画像ではありません');
	$main = get_attached_file($id, true);
	if (!$main || !is_file($main)) return new WP_Error('dwu_revert', 'ファイルが見つかりません');
	$main = wp_normalize_path($main);
	$dir = dirname($main);
	if (dwu_rel_dir($main) === '' && $dir !== wp_normalize_path(wp_get_upload_dir()['basedir'])) return new WP_Error('dwu_revert', 'uploads の外のファイルは戻せません');
	$meta = wp_get_attachment_metadata($id);
	if (!is_array($meta)) $meta = [];
	$src = get_post_meta($id, DWU_SOURCE_META, true);
	if (!is_array($src)) $src = [];
	$largest = !empty($meta['original_image']) && is_file($dir . '/' . $meta['original_image']) ? $dir . '/' . $meta['original_image'] : $main;
	$backup = dwu_backup_path($src);
	if ($backup === '' && !$reencode) return new WP_Error('dwu_revert', '元の画像が残っていません');

	// 戻す形式と名前
	if ($backup !== '') {
		$mime = wp_get_image_mime($backup);
	} else {
		$mime = in_array($src['mime'] ?? '', ['image/jpeg', 'image/png'], true) ? $src['mime'] : (dwu_webp_has_alpha($largest) ? 'image/png' : 'image/jpeg');
	}
	if (!in_array($mime, ['image/jpeg', 'image/png'], true)) return new WP_Error('dwu_revert', '元の画像が JPEG / PNG ではありません');
	$ext = $mime === 'image/png' ? 'png' : 'jpg';
	$orig_ext = strtolower(pathinfo((string) ($src['name'] ?? ''), PATHINFO_EXTENSION));
	if ($mime === 'image/jpeg' && $orig_ext === 'jpeg') $ext = 'jpeg';
	$base = pathinfo((string) ($src['name'] ?? ''), PATHINFO_FILENAME);
	if ($base === '') $base = pathinfo($largest, PATHINFO_FILENAME);
	$new = $dir . '/' . wp_unique_filename($dir, sanitize_file_name($base . '.' . $ext));

	// ファイルを置く
	if ($backup !== '') {
		if (!@copy($backup, $new)) return new WP_Error('dwu_revert', '元の画像を置けませんでした');
	} else {
		$editor = wp_get_image_editor($largest, ['mime_type' => 'image/webp', 'output_mime_type' => $mime]);
		if (is_wp_error($editor)) return new WP_Error('dwu_revert', 'このサーバーでは WebP を変換し直せません');
		if ($mime === 'image/jpeg') $editor->set_quality(90);
		$saved = $editor->save($new, $mime);
		if (is_wp_error($saved) || empty($saved['path']) || !is_file($saved['path']) || wp_get_image_mime($saved['path']) !== $mime) {
			if (!is_wp_error($saved) && !empty($saved['path']) && is_file($saved['path'])) @unlink($saved['path']);
			return new WP_Error('dwu_revert', '変換し直せませんでした');
		}
		$new = wp_normalize_path($saved['path']);
	}
	@chmod($new, 0644);

	// メディアの記録を新しいファイルにして、サイズ違いを作り直す
	global $wpdb;
	update_attached_file($id, $new);
	$wpdb->update($wpdb->posts, ['post_mime_type' => $mime], ['ID' => $id]);
	clean_post_cache($id);
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$new_meta = wp_generate_attachment_metadata($id, $new);
	wp_update_attachment_metadata($id, $new_meta);
	$new_main = wp_basename((string) get_attached_file($id, true));

	// 古い名前 → 新しい名前（同じ大きさのもの同士。新しい方に無い大きさは本体に）
	$map = [wp_basename($main) => $new_main];
	if (!empty($meta['original_image'])) $map[$meta['original_image']] = $new_meta['original_image'] ?? $new_main;
	foreach (($meta['sizes'] ?? []) as $key => $s) {
		if (!empty($s['file'])) $map[$s['file']] = $new_meta['sizes'][$key]['file'] ?? $new_main;
	}
	$keep = [$new_main => true, wp_basename($new) => true];
	if (!empty($new_meta['original_image'])) $keep[$new_meta['original_image']] = true;
	foreach (($new_meta['sizes'] ?? []) as $s) if (!empty($s['file'])) $keep[$s['file']] = true;
	foreach (array_keys($map) as $old) {
		if (!isset($keep[$old]) && is_file($dir . '/' . $old)) @unlink($dir . '/' . $old);
	}

	$rel = dwu_rel_dir($main);
	$dir_url = untrailingslashit(wp_get_upload_dir()['baseurl']) . ($rel === '' ? '' : '/' . $rel);
	$posts = dwu_replace_in_posts($dir_url, array_filter($map, fn($n, $o) => $n !== $o, ARRAY_FILTER_USE_BOTH), pathinfo($largest, PATHINFO_FILENAME));

	if ($backup !== '') @unlink($backup);
	delete_post_meta($id, DWU_SOURCE_META);
	return ['file' => $new_main, 'mime' => $mime, 'posts' => $posts, 'reencoded' => $backup === ''];
}

/** 残した元の画像をすべて消す。返り値は消したファイルの数 */
function dwu_delete_backups(): int {
	global $wpdb;
	$base = dwu_backup_base();
	$n = 0;
	if (is_dir($base) && !is_link($base)) {
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $f) {
			if ($f->isDir() && !$f->isLink()) {
				@rmdir($f->getPathname());
			} else {
				if (@unlink($f->getPathname()) && !in_array($f->getFilename(), ['.htaccess', 'index.php'], true)) $n++;
			}
		}
		@rmdir($base);
	}
	// 記録からも元の画像の場所を外す（形式と名前は残す。WebP から変換し直すときに使う）
	foreach ($wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", DWU_SOURCE_META)) as $pid) {
		$src = get_post_meta((int) $pid, DWU_SOURCE_META, true);
		if (is_array($src) && !empty($src['backup'])) {
			$src['backup'] = '';
			update_post_meta((int) $pid, DWU_SOURCE_META, $src);
		}
	}
	return $n;
}

/** WebP の画像の ID を、元の画像があるもの・記録だけのもの・記録が無いものに分けて返す */
function dwu_webp_ids(): array {
	global $wpdb;
	$rows = $wpdb->get_results($wpdb->prepare(
		"SELECT p.ID, m.meta_value FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
		 WHERE p.post_type = 'attachment' AND p.post_mime_type = 'image/webp' ORDER BY p.ID",
		DWU_SOURCE_META
	));
	$out = ['backup' => [], 'recorded' => [], 'unknown' => []];
	foreach ($rows as $r) {
		$src = $r->meta_value === null ? null : maybe_unserialize($r->meta_value);
		if (!is_array($src)) $out['unknown'][] = (int) $r->ID;
		elseif (dwu_backup_path($src) !== '') $out['backup'][] = (int) $r->ID;
		else $out['recorded'][] = (int) $r->ID;
	}
	return $out;
}

/** 元の画像のフォルダの大きさ（バイト） */
function dwu_backup_size(): int {
	$base = dwu_backup_base();
	if (!is_dir($base)) return 0;
	$size = 0;
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $f) {
		if ($f->isFile()) $size += $f->getSize();
	}
	return $size;
}

// =====================================================
//  管理画面
// =====================================================
add_action('admin_init', function () {
	register_setting('dwu', 'dwu_keep_original', ['type' => 'string', 'default' => '1', 'sanitize_callback' => fn($v) => $v === '1' ? '1' : '0']);
});

add_action('admin_menu', function () {
	add_media_page('WebP 変換', 'WebP 変換', 'manage_options', 'dwu', 'dwu_render_page');
});

// プラグイン一覧に「設定」のリンクを出す
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
	if (current_user_can('manage_options')) array_unshift($links, '<a href="' . esc_url(admin_url('upload.php?page=dwu')) . '">設定</a>');
	return $links;
});

/** 1枚戻すリンク（元の画像があるかで文言を変える） */
function dwu_revert_link(int $id, string $back): string {
	$src = get_post_meta($id, DWU_SOURCE_META, true);
	$has = is_array($src) && dwu_backup_path($src) !== '';
	$label = $has ? '元の画像に戻す' : 'JPEG / PNG に戻す（画質が少し落ちます）';
	$msg = $has ? '元の画像に戻します。記事の本文の URL も書き換えます。よろしいですか？' : '元の画像が残っていないので、WebP から変換し直します（画質が少し落ちます）。記事の本文の URL も書き換えます。よろしいですか？';
	$url = wp_nonce_url(admin_url('admin-post.php?action=dwu_revert&id=' . $id . '&back=' . rawurlencode($back)), 'dwu_revert_' . $id);
	return '<a href="' . esc_url($url) . '" onclick="return confirm(' . esc_attr(wp_json_encode($msg)) . ')">' . esc_html($label) . '</a>';
}

// メディアの一覧（リスト表示）の各行に「元に戻す」
add_filter('media_row_actions', function ($actions, $post) {
	if (current_user_can('manage_options') && $post->post_mime_type === 'image/webp') {
		$actions['dwu_revert'] = dwu_revert_link($post->ID, 'upload.php');
	}
	return $actions;
}, 10, 2);

// 添付ファイルの詳細に「元に戻す」
add_filter('attachment_fields_to_edit', function ($fields, $post) {
	if (!current_user_can('manage_options') || $post->post_mime_type !== 'image/webp') return $fields;
	$fields['dwu_revert'] = [
		'label' => 'WebP 変換',
		'input' => 'html',
		'html'  => dwu_revert_link($post->ID, 'post.php?post=' . $post->ID . '&action=edit'),
	];
	return $fields;
}, 10, 2);

add_action('admin_post_dwu_revert', function () {
	$id = (int) ($_GET['id'] ?? 0);
	if (!current_user_can('manage_options')) wp_die('権限がありません');
	check_admin_referer('dwu_revert_' . $id);
	$r = dwu_revert($id, true);
	set_transient('dwu_notice_' . get_current_user_id(), is_wp_error($r)
		? ['error', '元に戻せませんでした：' . $r->get_error_message()]
		: ['success', sprintf('%s に戻しました%s。記事 %d 件の URL を書き換えました。', $r['file'], $r['reencoded'] ? '（WebP から変換し直し）' : '', $r['posts'])], 60);
	$back = (string) wp_unslash($_GET['back'] ?? 'upload.php');
	if (!preg_match('/^(upload\.php|post\.php\?post=\d+&action=edit)$/', $back)) $back = 'upload.php';
	if (!is_wp_error($r) && str_starts_with($back, 'post.php')) $back = 'post.php?post=' . $id . '&action=edit';
	wp_safe_redirect(admin_url($back));
	exit;
});

add_action('admin_post_dwu_delete_backups', function () {
	if (!current_user_can('manage_options')) wp_die('権限がありません');
	check_admin_referer('dwu_delete_backups');
	$n = dwu_delete_backups();
	set_transient('dwu_notice_' . get_current_user_id(), ['success', sprintf('元の画像を %d 枚消しました。', $n)], 60);
	wp_safe_redirect(admin_url('upload.php?page=dwu'));
	exit;
});

add_action('admin_notices', function () {
	$key = 'dwu_notice_' . get_current_user_id();
	$n = get_transient($key);
	if (!is_array($n)) return;
	delete_transient($key);
	printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($n[0]), esc_html($n[1]));
});

// 一括で戻す：1枚ずつ呼ぶ
add_action('wp_ajax_dwu_revert', function () {
	if (!current_user_can('manage_options')) wp_send_json_error(['message' => '権限がありません'], 403);
	if (!check_ajax_referer('dwu_bulk', 'nonce', false)) wp_send_json_error(['message' => '確認用の情報が古くなっています。ページを開き直してください'], 403);
	$id = (int) ($_POST['id'] ?? 0);
	$r = dwu_revert($id, ($_POST['reencode'] ?? '0') === '1');
	if (is_wp_error($r)) wp_send_json_error(['message' => $r->get_error_message()]);
	wp_send_json_success($r);
});

function dwu_render_page() {
	if (!current_user_can('manage_options')) wp_die('権限がありません');
	$ids = dwu_webp_ids();
	$nb = count($ids['backup']); $nr = count($ids['recorded']); $nu = count($ids['unknown']);
	?>
	<div class="wrap">
		<h1>WebP 変換</h1>
		<p>アップロードした JPEG / PNG は、自動で WebP になります。ファイル名の最後を「-orig」にすると変換しません（例: logo-orig.png）。</p>

		<h2>設定</h2>
		<form method="post" action="options.php">
			<?php settings_fields('dwu'); ?>
			<label><input type="checkbox" name="dwu_keep_original" value="1" <?php checked(dwu_keep_original()); ?>> 元の画像を残す</label>
			<p class="description">残しておくと、あとで画質を落とさずに元に戻せます。そのぶんサーバーの容量を使います。<br>
				元の画像は外から見られないフォルダに置きます（Apache / LiteSpeed の場合。nginx のサーバーではフォルダ名を推測されにくくしているだけです）。</p>
			<?php submit_button('保存'); ?>
		</form>

		<h2>元に戻す</h2>
		<table class="widefat striped" style="max-width:640px">
			<tbody>
				<tr><td>元の画像が残っている WebP</td><td><?php echo esc_html($nb); ?> 枚</td></tr>
				<tr><td>このプラグインで変換したが、元の画像が残っていない WebP</td><td><?php echo esc_html($nr); ?> 枚</td></tr>
				<tr><td>記録が無い WebP（記録を付ける前に変換したもの・最初から WebP で上げたもの）</td><td><?php echo esc_html($nu); ?> 枚</td></tr>
			</tbody>
		</table>
		<p>元に戻すと、JPEG / PNG を置いてサイズ違いを作り直し、記事の本文の URL を書き換えて、WebP を消します。テーマの設定やカスタムフィールドなど、本文以外に書いた URL は書き換えません。<br>
			元の画像が残っていないものは WebP から変換し直すので、画質が少し落ちます。1枚ずつ戻すときは、メディアの一覧（リスト表示）か、画像の詳細から戻せます。</p>
		<p><label><input type="checkbox" id="dwu-reencode"> 元の画像が残っていないものも、WebP から変換し直して戻す</label></p>
		<p><label id="dwu-unknown-wrap" style="display:none"><input type="checkbox" id="dwu-unknown"> 記録が無い WebP も戻す（最初から WebP で上げた画像も JPEG / PNG になります）</label></p>
		<p>
			<button type="button" class="button button-primary" id="dwu-run">一括で元に戻す</button>
			<span id="dwu-status" style="margin-left:8px"></span>
		</p>
		<ul id="dwu-errors" style="color:#b32d2e"></ul>

		<h2>残した元の画像を消す</h2>
		<p>WebP のまま使い続けると決めたら、残した元の画像を消して容量を空けられます。消すと、元に戻すときは WebP から変換し直すことになります（画質が少し落ちます）。<br>
			今の大きさ：<?php echo esc_html(size_format(dwu_backup_size()) ?: '0 B'); ?></p>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('残した元の画像をすべて消します。元には戻せません。よろしいですか？')">
			<input type="hidden" name="action" value="dwu_delete_backups">
			<?php wp_nonce_field('dwu_delete_backups'); ?>
			<?php submit_button('元の画像をすべて消す', 'delete', 'submit', false); ?>
		</form>
	</div>
	<script>
	(() => {
		const ids = <?php echo wp_json_encode($ids); ?>;
		const nonce = <?php echo wp_json_encode(wp_create_nonce('dwu_bulk')); ?>;
		const $ = id => document.getElementById(id);
		$('dwu-reencode').onchange = () => { $('dwu-unknown-wrap').style.display = $('dwu-reencode').checked ? '' : 'none'; if (!$('dwu-reencode').checked) $('dwu-unknown').checked = false; };
		$('dwu-run').onclick = async () => {
			const re = $('dwu-reencode').checked;
			const list = [...ids.backup, ...(re ? ids.recorded : []), ...(re && $('dwu-unknown').checked ? ids.unknown : [])];
			if (!list.length) { $('dwu-status').textContent = '戻す画像がありません'; return; }
			if (!confirm(`${list.length} 枚を元に戻します。記事の本文の URL も書き換えます。よろしいですか？`)) return;
			$('dwu-run').disabled = true; $('dwu-errors').innerHTML = '';
			let ok = 0, ng = 0, posts = 0;
			for (const [i, id] of list.entries()) {
				$('dwu-status').textContent = `${i + 1} / ${list.length} 枚目を戻しています…`;
				const fd = new FormData();
				fd.append('action', 'dwu_revert'); fd.append('nonce', nonce); fd.append('id', id); fd.append('reencode', re ? '1' : '0');
				try {
					const j = await (await fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })).json();
					if (j.success) { ok++; posts += j.data.posts; } else throw new Error(j.data && j.data.message || '失敗しました');
				} catch (e) {
					ng++;
					const li = document.createElement('li'); li.textContent = `#${id}: ${e.message}`; $('dwu-errors').appendChild(li);
				}
			}
			$('dwu-status').textContent = `終わりました。${ok} 枚を戻し、記事の URL を書き換えました（${posts} 回）。` + (ng ? `${ng} 枚は戻せませんでした。` : '');
			$('dwu-run').disabled = false;
		};
	})();
	</script>
	<?php
}
