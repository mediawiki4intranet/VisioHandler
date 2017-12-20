<?php

/**
 * Dual-render (raster+vector) SVG thumbnail
 *
 * Used both here and in Mediawiki4Intranet core
 *
 * @author Vitaliy Filippov <vitalif@mail.ru>
 * @copyright Copyright © 2017 Vitaliy Filippov
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

class SvgThumbnailImage extends ThumbnailImage {

	function __construct( $file, $url, $svgpath, $svgurl, $width, $height, $path = false, $page = false, $later = false ) {
		$this->svgpath = $svgpath;
		$this->svgurl = $svgurl;
		$this->later = $later;
		parent::__construct( $file, $url, $width, $height, $path, $page );
	}

	static function scaleParam( $name, $value, $sw, $sh ) {
		// $name could be width, height or viewBox
		$i = ( $name == 'height' ? 1 : 0 );
		$mul = array( $sw, $sh );
		preg_match_all( '/\d+(?:\.\d+)?/', $value, $nums, PREG_PATTERN_ORDER|PREG_OFFSET_CAPTURE );
		$r = '';
		$p = 0;
		foreach( $nums[0] as $num ) {
			$r .= substr( $value, $p, $num[1]-$p );
			$r .= $num[0] * $mul[($i++) & 1];
			$p = $num[1] + strlen( $num[0] );
		}
		$r .= substr( $value, $p );
		return $name.'="'.$r.'"';
	}

	function toHtml( $options = array() ) {
		if ( count( func_get_args() ) == 2 ) {
			throw new MWException( __METHOD__ .' called in the old style' );
		}

		$alt = empty( $options['alt'] ) ? '' : $options['alt'];
		$query = empty( $options['desc-query'] )  ? '' : $options['desc-query'];

		if ( !empty( $options['custom-url-link'] ) ) {
			$linkAttribs = array( 'href' => $options['custom-url-link'] );
			if ( !empty( $options['title'] ) ) {
				$linkAttribs['title'] = $options['title'];
			}
		} elseif ( !empty( $options['custom-title-link'] ) ) {
			$title = $options['custom-title-link'];
			$linkAttribs = array(
				'href' => $title->getLinkUrl(),
				'title' => empty( $options['title'] ) ? $title->getFullText() : $options['title']
			);
		} elseif ( !empty( $options['desc-link'] ) ) {
			$linkAttribs = $this->getDescLinkAttribs( empty( $options['title'] ) ? null : $options['title'], $query );
		} elseif ( !empty( $options['file-link'] ) ) {
			$linkAttribs = array( 'href' => $this->file->getURL() );
		} else {
			$linkAttribs = array( 'href' => '' );
		}

		$attribs = array(
			'alt' => $alt,
			'src' => $this->url,
			'width' => $this->width,
			'height' => $this->height,
		);
		if ( !empty( $options['valign'] ) ) {
			$attribs['style'] = "vertical-align: {$options['valign']}";
		}
		if ( !empty( $options['img-class'] ) ) {
			$attribs['class'] = $options['img-class'];
		}

		$linkurl = $this->svgurl;

		if ( !empty( $linkAttribs['href'] ) ||
			$this->width != $this->file->getWidth() ||
			$this->height != $this->file->getHeight() ) {
			if ( empty( $linkAttribs['href'] ) ) {
				$linkAttribs['href'] = '';
			}
			if ( empty( $linkAttribs['title'] ) ) {
				$linkAttribs['title'] = '';
			}
			// :-( The only cross-browser way to link from SVG
			// is to add an <a xlink:href> into SVG image itself
			global $wgServer;
			$href = $linkAttribs['href'];
			if ( substr( $href, 0, 1 ) == '/' ) {
				$href = $wgServer . $href;
			}
			$method = method_exists( $this->file, 'getPhys' ) ? 'getPhys' : 'getName'; // 4intra.net
			$hash = '/' . $this->file->$method() . '-linked-' . crc32( $href . "\0" .
				$linkAttribs['title'] . "\0" . $this->width . "\0" . $this->height ) . '.svg';
			$linkfn = $this->file->getThumbPath() . $hash;
			$linkurl = $this->file->getThumbUrl() . $hash;

			// Cache changed SVGs only when TRANSFORM_LATER is on
			$mtime = false;
			if ( $this->later ) {
				$mtime = $this->file->repo->getFileTimestamp( $linkfn );
			}
			if ( !$mtime || $mtime < $this->file->getTimestamp() ) {
				// Load original SVG or SVGZ and extract opening element
				$readfn = $this->svgpath ? $this->svgpath : $this->file->getLocalRefPath();
				if ( function_exists( 'gzopen' ) ) {
					$fp = gzopen( $readfn, 'rb' );
				} else {
					$fp = fopen( $readfn, 'rb' );
				}
				$skip = false;
				if ( $fp ) {
					$svg = stream_get_contents( $fp );
					fclose( $fp );
					if ( substr( $svg, 0, 3 ) == "\x1f\x8b\x08" ) {
						wfDebug( __CLASS__.": Zlib is not available, can't scale SVGZ image\n" );
						$skip = true;
					}
				}
				else {
					wfDebug( __CLASS__.": Cannot read file $readfn\n" );
					$skip = true;
				}
				if ( !$skip ) {
					// Find opening and closing tags
					preg_match( '#<svg[^<>]*>#is', $svg, $m, PREG_OFFSET_CAPTURE );
					$closepos = strrpos( $svg, '</svg' );
					if ( !$m || $closepos === false ) {
						wfDebug( __CLASS__.": Invalid SVG (opening or closing tag not found)\n" );
						$skip = true;
					}
				}
				if ( !$skip ) {
					$open = $m[0][0];
					$openpos = $m[0][1];
					$openlen = strlen( $m[0][0] );
					$sw = $this->width / $this->file->getWidth();
					$sh = $this->height / $this->file->getHeight();
					$close = '';
					// Scale width, height and viewBox
					$open = preg_replace_callback( '/(viewBox|width|height)=[\'\"]([^\'\"]+)[\'\"]/',
						create_function( '$m', "return SvgThumbnailImage::scaleParam( \$m[1], \$m[2], $sw, $sh );" ), $open );
					// Add xlink namespace, if not yet
					if ( !strpos( $open, 'xmlns:xlink' ) ) {
						$open = substr( $open, 0, -1 ) . ' xmlns:xlink="http://www.w3.org/1999/xlink">';
					}
					// Check svg namespace
					$ns = '';
					if ( preg_match( '#xmlns:([a-z]+)=[\"\']?http://www\.w3\.org/2000/svg#', $open, $m ) ) {
						$ns = $m[1].':';
					}
					if ( $sw < 0.99 || $sw > 1.01 || $sh < 0.99 || $sh > 1.01 ) {
						// Wrap contents into a scaled layer
						$open .= "<${ns}g transform='scale($sw $sh)'>";
						$close = "</${ns}g>$close";
					}
					// Wrap contents into a hyperlink
					if ( $href ) {
						$open .= "<${ns}a xlink:href=\"".htmlspecialchars( $href ).
							'" target="_parent" xlink:title="'.htmlspecialchars( $linkAttribs['title'] ).'">';
						$close = "</${ns}a>$close";
					}
					// Write modified SVG
					$svg = substr( $svg, 0, $openpos ) . $open .
						substr( $svg, $openpos+$openlen, $closepos-$openpos-$openlen ) . $close .
						ltrim( substr( $svg, $closepos ), ">\t\r\n" );
					$tmpFile = TempFSFile::factory( 'transform_svg' );
					$tmpThumbPath = $tmpFile->getPath(); // path of 0-byte temp file
					file_put_contents( $tmpThumbPath, $svg );
					$this->file->repo->quickImport( $tmpThumbPath, $linkfn );
				} else {
					// Skip SVG scaling
					$linkurl = $this->svgurl;
				}
			}
		}

		// Output PNG <img> wrapped into SVG <object>
		$html = $this->linkWrap( $linkAttribs, Xml::element( 'img', $attribs ) );
		$html = Xml::tags( 'object', array(
			'type' => 'image/svg+xml',
			'data' => $linkurl,
			'style' => 'overflow: hidden; vertical-align: middle',
			'width' => $this->width,
			'height' => $this->height,
		), $html );
		return $html;
	}
}
