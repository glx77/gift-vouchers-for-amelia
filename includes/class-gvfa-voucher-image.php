<?php
/**
 * Renders a printable gift-voucher image (JPEG) over the bundled background,
 * using GD. Returns a file path suitable for an email attachment.
 *
 * @package GiftVouchersForAmelia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GVFA_Voucher_Image {

	/** @var int Reference background width the layout ratios were designed for. */
	const REF_W = 1489;

	/** @var int Reference background height. */
	const REF_H = 1056;

	/**
	 * Generate the voucher image for one code.
	 *
	 * @param array $data { prestation:string, code:string, months:int, expires_display:string }
	 * @return string|null Absolute file path of the JPEG, or null on failure.
	 */
	public function generate( $data ) {
		if ( ! function_exists( 'imagettftext' ) || ! function_exists( 'getimagesize' ) ) {
			return null; // GD (with FreeType) unavailable.
		}

		$assets = dirname( __DIR__ ) . '/assets/';
		$bg     = apply_filters( 'gvfa_voucher_background', $assets . 'voucher-bg.jpg' );

		if ( ! is_string( $bg ) || ! is_file( $bg ) ) {
			return null;
		}

		$img = $this->load_image( $bg );

		if ( ! $img ) {
			return null;
		}

		$w = imagesx( $img );
		$h = imagesy( $img );
		$k = $h / self::REF_H; // uniform scale factor from the reference layout.

		$dark  = imagecolorallocate( $img, 55, 45, 25 );
		$gold  = imagecolorallocate( $img, 168, 128, 38 );
		$boxbg = imagecolorallocate( $img, 248, 242, 222 );

		$fb = $assets . 'fonts/Poppins-Bold.ttf';
		$fs = $assets . 'fonts/Poppins-SemiBold.ttf';
		$fm = $assets . 'fonts/Poppins-Medium.ttf';

		$cx   = (int) ( $w * 0.6146 );
		$maxw = (int) ( $w * 0.571 );

		$months = max( 1, (int) ( $data['months'] ?? 6 ) );
		$expiry = isset( $data['expires_display'] ) ? (string) $data['expires_display'] : '';

		// --- Prestation + code ---
		$this->center( $img, $fb, 24 * $k, (int) ( $h * 0.445 ), $cx, $dark, 'Prestation : ' . $data['prestation'] );
		$this->center( $img, $fm, 17 * $k, (int) ( $h * 0.496 ), $cx, $gold, 'C O D E   C A D E A U' );

		$code = (string) $data['code'];
		$cw   = $this->text_w( $fb, 30 * $k, $code );
		$pad  = (int) ( 30 * $k );
		imagefilledrectangle( $img, (int) ( $cx - $cw / 2 - $pad ), (int) ( $h * 0.519 ), (int) ( $cx + $cw / 2 + $pad ), (int) ( $h * 0.568 ), $boxbg );
		imagerectangle( $img, (int) ( $cx - $cw / 2 - $pad ), (int) ( $h * 0.519 ), (int) ( $cx + $cw / 2 + $pad ), (int) ( $h * 0.568 ), $gold );
		$this->center( $img, $fb, 30 * $k, (int) ( $h * 0.555 ), $cx, $dark, $code );

		// --- Message (from settings, {months}/{expiry} placeholders) ---
		$message = (string) GVFA_Plugin::get_setting( 'voucher_message' );
		$message = strtr(
			$message,
			array(
				'{months}' => (string) $months,
				'{expiry}' => $expiry,
			)
		);

		$y  = (int) ( $h * 0.625 );
		$lh = (int) ( 32 * $k );

		foreach ( preg_split( '/\r\n|\r|\n/', $message ) as $paragraph ) {
			$paragraph = trim( $paragraph );

			if ( '' === $paragraph ) {
				$y += (int) ( 12 * $k );
				continue;
			}

			$y = $this->block( $img, $fs, 20 * $k, $y, $cx, $gold, $paragraph, $maxw, $lh ) + (int) ( 12 * $k );
		}

		// --- Contact block below a divider ---
		$contact = trim( (string) GVFA_Plugin::get_setting( 'voucher_contact' ) );

		if ( '' !== $contact ) {
			$y += (int) ( 6 * $k );
			imagefilledrectangle( $img, (int) ( $cx - 200 * $k ), $y, (int) ( $cx + 200 * $k ), (int) ( $y + 2 ), $gold );
			$y += (int) ( 40 * $k );

			foreach ( preg_split( '/\r\n|\r|\n/', $contact ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$this->center( $img, $fm, 20 * $k, $y, $cx, $gold, $line );
				$y += (int) ( 36 * $k );
			}
		}

		// --- Write to a temp file in uploads ---
		$upload = wp_upload_dir();

		if ( ! empty( $upload['error'] ) ) {
			imagedestroy( $img );
			return null;
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'gvfa-vouchers';
		wp_mkdir_p( $dir );

		$file = trailingslashit( $dir ) . 'bon-' . sanitize_file_name( $code ) . '.jpg';

		$ok = imagejpeg( $img, $file, 92 );
		imagedestroy( $img );

		return $ok ? $file : null;
	}

	/**
	 * Load a JPEG or PNG background into a GD image.
	 *
	 * @param string $path Image path.
	 * @return \GdImage|null
	 */
	private function load_image( $path ) {
		$info = getimagesize( $path );

		if ( ! $info ) {
			return null;
		}

		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- graceful fallback on a corrupt image.
		switch ( $info[2] ) {
			case IMAGETYPE_JPEG:
				$img = @imagecreatefromjpeg( $path );
				break;
			case IMAGETYPE_PNG:
				$img = @imagecreatefrompng( $path );
				break;
			default:
				return null;
		}
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged

		return $img ? $img : null;
	}

	/**
	 * @param string $font Font file.
	 * @param float  $size Font size.
	 * @param string $text Text.
	 * @return int Text width in pixels.
	 */
	private function text_w( $font, $size, $text ) {
		$box = imagettfbbox( $size, 0, $font, $text );
		return (int) ( $box[2] - $box[0] );
	}

	/**
	 * Draw a single line centered on $cx.
	 */
	private function center( $img, $font, $size, $y, $cx, $color, $text ) {
		imagettftext( $img, $size, 0, (int) ( $cx - $this->text_w( $font, $size, $text ) / 2 ), (int) $y, $color, $font, $text );
	}

	/**
	 * Word-wrap $text to $max px and draw it centered; returns the new Y.
	 */
	private function block( $img, $font, $size, $y, $cx, $color, $text, $max, $lh ) {
		$line  = '';
		$lines = array();

		foreach ( explode( ' ', $text ) as $word ) {
			$try = '' === $line ? $word : $line . ' ' . $word;
			if ( $this->text_w( $font, $size, $try ) > $max && '' !== $line ) {
				$lines[] = $line;
				$line    = $word;
			} else {
				$line = $try;
			}
		}

		if ( '' !== $line ) {
			$lines[] = $line;
		}

		foreach ( $lines as $ln ) {
			$this->center( $img, $font, $size, $y, $cx, $color, $ln );
			$y += $lh;
		}

		return (int) $y;
	}
}
