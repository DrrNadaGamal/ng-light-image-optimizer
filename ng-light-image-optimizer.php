<?php
/**
 * Plugin Name: NG Light Image Optimizer
 * Description: Converts uploaded JPG/PNG images (and all their sizes) to WebP/AVIF, keeps the originals, and can restore them in one click. Part of the NG WordPress Suite.
 * Version: 0.1.0
 * Author: Nada Gamal
 * License: GPL-2.0-or-later
 * Text Domain: ng-lio
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'NGLIO_VER', '0.1.0' );

final class NGLIO {
	const OPT = 'nglio_settings';

	static function defaults() {
		return array( 'webp' => 1, 'avif' => 0, 'quality' => 82, 'serve' => 1, 'max_width' => 0 );
	}
	static function s() { return wp_parse_args( get_option( self::OPT, array() ), self::defaults() ); }

	static function init() {
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_meta' ), 20, 2 );
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'swap_url' ), 20, 2 );
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'swap_src' ), 20, 4 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'swap_srcset' ), 20, 5 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_nglio_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_nglio_bulk', array( __CLASS__, 'bulk' ) );
		add_action( 'admin_post_nglio_restore', array( __CLASS__, 'restore_all' ) );
		self::i18n();
	}

	/* ---- engine ---- */
	static function can( $fmt ) {
		if ( class_exists( 'Imagick' ) ) {
			$f = array_map( 'strtolower', Imagick::queryFormats() );
			if ( in_array( strtolower( $fmt ), $f, true ) ) { return true; }
		}
		if ( 'webp' === $fmt ) { return function_exists( 'imagewebp' ); }
		if ( 'avif' === $fmt ) { return function_exists( 'imageavif' ); }
		return false;
	}
	static function convert( $src, $fmt, $q ) {
		$dst = preg_replace( '/\.(jpe?g|png)$/i', '', $src ) . '.' . $fmt;
		$dst = preg_replace( '/(\.[a-z0-9]+)$/i', '$1', $dst );
		$dst = $src . '.' . $fmt; // photo.jpg -> photo.jpg.webp (never collides with a real file)
		$ok  = false;
		if ( class_exists( 'Imagick' ) && self::can( $fmt ) ) {
			try {
				$im = new Imagick( $src );
				$im->setImageFormat( $fmt );
				$im->setImageCompressionQuality( $q );
				$im->stripImage();
				$ok = $im->writeImage( $dst );
				$im->destroy();
			} catch ( Exception $e ) { $ok = false; }
		}
		if ( ! $ok && function_exists( 'image' . $fmt ) ) {
			$info = @getimagesize( $src );
			$img  = false;
			if ( $info && 'image/jpeg' === $info['mime'] ) { $img = @imagecreatefromjpeg( $src ); }
			if ( $info && 'image/png' === $info['mime'] ) {
				$img = @imagecreatefrompng( $src );
				if ( $img ) { imagepalettetotruecolor( $img ); imagealphablending( $img, true ); imagesavealpha( $img, true ); }
			}
			if ( $img ) { $fn = 'image' . $fmt; $ok = $fn( $img, $dst, $q ); imagedestroy( $img ); }
		}
		// Safety: keep the converted file only if it exists, is non-empty and is smaller than the original.
		if ( $ok && file_exists( $dst ) && filesize( $dst ) > 0 && filesize( $dst ) < filesize( $src ) ) { return $dst; }
		if ( file_exists( $dst ) ) { @unlink( $dst ); }
		return false;
	}
	static function files_for( $id ) {
		$file = get_attached_file( $id );
		if ( ! $file ) { return array(); }
		$out  = array( $file );
		$meta = wp_get_attachment_metadata( $id );
		if ( ! empty( $meta['sizes'] ) ) {
			$dir = dirname( $file );
			foreach ( $meta['sizes'] as $sz ) { $out[] = $dir . '/' . $sz['file']; }
		}
		return array_unique( $out );
	}
	static function optimize( $id ) {
		$s = self::s(); $done = array(); $saved = 0;
		if ( ! preg_match( '/^image\/(jpeg|png)$/', (string) get_post_mime_type( $id ) ) ) { return null; }
		foreach ( self::files_for( $id ) as $f ) {
			if ( ! is_readable( $f ) ) { continue; }
			foreach ( array( 'webp', 'avif' ) as $fmt ) {
				if ( empty( $s[ $fmt ] ) || ! self::can( $fmt ) ) { continue; }
				if ( file_exists( $f . '.' . $fmt ) ) { continue; }
				$d = self::convert( $f, $fmt, (int) $s['quality'] );
				if ( $d ) { $done[] = basename( $d ); $saved += filesize( $f ) - filesize( $d ); }
			}
		}
		update_post_meta( $id, '_nglio', array( 'files' => $done, 'saved' => $saved, 'time' => time() ) );
		return $done;
	}
	static function on_meta( $meta, $id ) { self::optimize( $id ); return $meta; }
	static function on_delete( $id ) {
		foreach ( self::files_for( $id ) as $f ) { foreach ( array( 'webp', 'avif' ) as $x ) { if ( file_exists( "$f.$x" ) ) { @unlink( "$f.$x" ); } } }
	}

	/* ---- serving ---- */
	static function best( $url ) {
		$s = self::s();
		if ( ! $s['serve'] || ! preg_match( '/\.(jpe?g|png)$/i', $url ) ) { return $url; }
		$up = wp_get_upload_dir();
		if ( 0 !== strpos( $url, $up['baseurl'] ) ) { return $url; }
		$path = $up['basedir'] . substr( $url, strlen( $up['baseurl'] ) );
		if ( $s['avif'] && self::accepts( 'avif' ) && file_exists( $path . '.avif' ) ) { return $url . '.avif'; }
		if ( $s['webp'] && file_exists( $path . '.webp' ) ) { return $url . '.webp'; }
		return $url;
	}
	static function accepts( $fmt ) { return isset( $_SERVER['HTTP_ACCEPT'] ) && false !== strpos( $_SERVER['HTTP_ACCEPT'], 'image/' . $fmt ); }
	static function swap_url( $url, $id ) { return is_admin() ? $url : self::best( $url ); }
	static function swap_src( $img, $id, $size, $icon ) { if ( $img && ! is_admin() ) { $img[0] = self::best( $img[0] ); } return $img; }
	static function swap_srcset( $sources ) {
		if ( is_admin() || ! is_array( $sources ) ) { return $sources; }
		foreach ( $sources as $k => $v ) { $sources[ $k ]['url'] = self::best( $v['url'] ); }
		return $sources;
	}

	/* ---- admin ---- */
	static function i18n() {
		add_filter( 'gettext_ng-lio', function ( $t ) {
			$m = self::T();
			$l = substr( apply_filters( 'nglio_locale', determine_locale() ), 0, 2 );
			return isset( $m[ $l ][ $t ] ) ? $m[ $l ][ $t ] : $t;
		} );
	}
	static function T() {
		return array(
			'ar' => array( 'Image Optimizer' => 'ضغط الصور', 'Save settings' => 'حفظ الإعدادات', 'Convert existing images' => 'تحويل الصور الحالية', 'Restore originals' => 'استعادة الأصول', 'Quality' => 'الجودة', 'Serve optimized images' => 'عرض الصور المحسّنة', 'Images optimized' => 'الصور المحسّنة', 'Space saved' => 'المساحة الموفّرة' ),
			'fr' => array( 'Image Optimizer' => 'Optimiseur d\'images', 'Save settings' => 'Enregistrer', 'Convert existing images' => 'Convertir les images existantes', 'Restore originals' => 'Restaurer les originaux', 'Quality' => 'Qualité', 'Serve optimized images' => 'Servir les images optimisées', 'Images optimized' => 'Images optimisées', 'Space saved' => 'Espace économisé' ),
			'de' => array( 'Image Optimizer' => 'Bildoptimierer', 'Save settings' => 'Einstellungen speichern', 'Convert existing images' => 'Vorhandene Bilder konvertieren', 'Restore originals' => 'Originale wiederherstellen', 'Quality' => 'Qualität', 'Serve optimized images' => 'Optimierte Bilder ausliefern', 'Images optimized' => 'Optimierte Bilder', 'Space saved' => 'Gesparter Platz' ),
		);
	}
	static function menu() { add_media_page( 'NG Image Optimizer', __( 'Image Optimizer', 'ng-lio' ), 'manage_options', 'nglio', array( __CLASS__, 'page' ) ); }
	static function stats() {
		global $wpdb; $n = 0; $b = 0;
		foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_nglio'" ) as $v ) {
			$m = maybe_unserialize( $v );
			if ( ! empty( $m['files'] ) ) { $n++; $b += (int) $m['saved']; }
		}
		return array( $n, $b );
	}
	static function page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$s = self::s(); list( $n, $b ) = self::stats();
		$rtl = is_rtl() ? ' dir="rtl"' : '';
		echo '<div class="wrap"' . $rtl . '><h1>NG ' . esc_html__( 'Image Optimizer', 'ng-lio' ) . '</h1>';
		if ( isset( $_GET['done'] ) ) { echo '<div class="notice notice-success"><p>' . esc_html( (int) $_GET['done'] ) . ' ✓</p></div>'; }
		echo '<p>WebP: ' . ( self::can( 'webp' ) ? 'OK' : 'N/A' ) . ' &middot; AVIF: ' . ( self::can( 'avif' ) ? 'OK' : 'N/A' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Images optimized', 'ng-lio' ) . ':</strong> ' . (int) $n . ' &middot; <strong>' . esc_html__( 'Space saved', 'ng-lio' ) . ':</strong> ' . esc_html( size_format( $b ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'nglio' ); echo '<input type="hidden" name="action" value="nglio_save"><table class="form-table">';
		printf( '<tr><th>WebP</th><td><input type="checkbox" name="webp" value="1" %s></td></tr>', checked( $s['webp'], 1, false ) );
		printf( '<tr><th>AVIF</th><td><input type="checkbox" name="avif" value="1" %s></td></tr>', checked( $s['avif'], 1, false ) );
		printf( '<tr><th>%s</th><td><input type="number" min="40" max="100" name="quality" value="%d"></td></tr>', esc_html__( 'Quality', 'ng-lio' ), (int) $s['quality'] );
		printf( '<tr><th>%s</th><td><input type="checkbox" name="serve" value="1" %s></td></tr>', esc_html__( 'Serve optimized images', 'ng-lio' ), checked( $s['serve'], 1, false ) );
		echo '</table>'; submit_button( __( 'Save settings', 'ng-lio' ) ); echo '</form>';
		foreach ( array( 'bulk' => 'Convert existing images', 'restore' => 'Restore originals' ) as $a => $label ) {
			echo '<form method="post" style="display:inline-block;margin-inline-end:8px" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'nglio' ); echo '<input type="hidden" name="action" value="nglio_' . esc_attr( $a ) . '">';
			submit_button( __( $label, 'ng-lio' ), 'restore' === $a ? 'secondary' : 'primary', '', false ); echo '</form>';
		}
		echo '</div>';
	}
	static function guard() { if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden', 403 ); } check_admin_referer( 'nglio' ); }
	static function save() {
		self::guard();
		update_option( self::OPT, array(
			'webp' => empty( $_POST['webp'] ) ? 0 : 1, 'avif' => empty( $_POST['avif'] ) ? 0 : 1,
			'quality' => max( 40, min( 100, (int) ( $_POST['quality'] ?? 82 ) ) ), 'serve' => empty( $_POST['serve'] ) ? 0 : 1, 'max_width' => 0,
		) );
		wp_safe_redirect( admin_url( 'upload.php?page=nglio' ) ); exit;
	}
	static function bulk() {
		self::guard(); @set_time_limit( 120 ); $c = 0;
		$ids = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => array( 'image/jpeg', 'image/png' ), 'posts_per_page' => 200, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_nglio', 'compare' => 'NOT EXISTS' ) ) ) );
		foreach ( $ids as $id ) { if ( self::optimize( $id ) ) { $c++; } }
		wp_safe_redirect( admin_url( 'upload.php?page=nglio&done=' . $c ) ); exit;
	}
	static function restore_all() {
		self::guard(); $c = 0;
		foreach ( get_posts( array( 'post_type' => 'attachment', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_nglio' ) ) as $id ) { self::on_delete( $id ); delete_post_meta( $id, '_nglio' ); $c++; }
		wp_safe_redirect( admin_url( 'upload.php?page=nglio&done=' . $c ) ); exit;
	}
}
NGLIO::init();
