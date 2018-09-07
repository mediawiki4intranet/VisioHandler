<?php

/**
 * An image handler which adds support for Visio (*.vsd, *.vsdx) files
 * via libvisio-utils (https://wiki.documentfoundation.org/DLP/Libraries/libvisio)
 *
 * Mostly copy-pasted from SvgImageHandler
 *
 * @author Vitaliy Filippov <vitalif@mail.ru>
 * @copyright Copyright © 2017 Vitaliy Filippov
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

class VisioImageHandler extends ImageHandler
{
	const SVG_METADATA_VERSION = 2;

	/** @var array A list of metadata tags that can be converted
	 *  to the commonly used exif tags. This allows messages
	 *  to be reused, and consistent tag names for {{#formatmetadata:..}}
	 */
	private static $metaConversion = array(
		'originalwidth' => 'ImageWidth',
		'originalheight' => 'ImageLength',
		'description' => 'ImageDescription',
		'title' => 'ObjectName',
	);

	function isEnabled() {
		global $wgSVGConverters, $wgSVGConverter;
		if ( !isset( $wgSVGConverters[$wgSVGConverter] ) ) {
			wfDebug( "\$wgSVGConverter is invalid, disabling SVG rendering.\n" );

			return false;
		} else {
			return true;
		}
	}

	function mustRender( $file ) {
		return true;
	}

	function isVectorized( $file ) {
		return true;
	}

	function isAnimatedImage( $file ) {
		return false;
	}

	function canAnimateThumbnail( $file ) {
		return false;
	}

	/**
	 * @param File $image
	 * @param array $params
	 * @return bool
	 */
	function normaliseParams( $image, &$params ) {
		global $wgSVGMaxSize;
		if ( !parent::normaliseParams( $image, $params ) ) {
			return false;
		}
		// Don't make an image bigger than wgMaxSVGSize on the smaller side
		if ( $params['physicalWidth'] <= $params['physicalHeight'] ) {
			if ( $params['physicalWidth'] > $wgSVGMaxSize ) {
				$srcWidth = $image->getWidth( $params['page'] );
				$srcHeight = $image->getHeight( $params['page'] );
				$params['physicalWidth'] = $wgSVGMaxSize;
				$params['physicalHeight'] = File::scaleHeight( $srcWidth, $srcHeight, $wgSVGMaxSize );
			}
		} else {
			if ( $params['physicalHeight'] > $wgSVGMaxSize ) {
				$srcWidth = $image->getWidth( $params['page'] );
				$srcHeight = $image->getHeight( $params['page'] );
				$params['physicalWidth'] = File::scaleHeight( $srcHeight, $srcWidth, $wgSVGMaxSize );
				$params['physicalHeight'] = $wgSVGMaxSize;
			}
		}

		return true;
	}

	function convertToSvg( $visioPath, $svgPath, &$error ) {
		global $wgVisioToXhtml, $wgUploadDirectory;

		// Prevent multiple vsd2xhtml calls during single request
		static $transformed = [];
		if ( !empty( $transformed[$svgPath] ) ) {
			return true;
		}

		if ( !file_exists( $wgUploadDirectory . '/generated/visio/' ) ) {
			@mkdir( $wgUploadDirectory . '/generated', 0771 );
			@mkdir( $wgUploadDirectory . '/generated/visio', 0771 );
		}

		$htmPath = wfTempDir() . '/visio_' . wfRandomString( 24 ) . '.htm';
		/** @noinspection PhpUnusedLocalVariableInspection */
		$cleaner = new ScopedCallback( function() use( $htmPath ) {
			MediaWiki\suppressWarnings();
			unlink( $htmPath );
			MediaWiki\restoreWarnings();
		} );

		$cmd = wfEscapeShellArg( $wgVisioToXhtml, $visioPath ) . ' > ' . wfEscapeShellArg( $htmPath );
		wfDebug( __METHOD__ . ": running vsd2xhtml: $cmd\n" );
		$retval = 0;
		$err = wfShellExecWithStderr( $cmd, $retval );

		if ( $retval !== 0 ) {
			$this->logErrorForExternalProcess( $retval, $err, $cmd );
			$error = "$err\nError code: $retval";
			return false;
		}

		$svg = file_get_contents( $htmPath );
		$begin = strpos( $svg, '<svg:svg ' );
		$end = strpos( $svg, '</svg:svg>' ) + 10;
		$svg = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>'."\n".
			'<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'."\n".
			substr( $svg, $begin, $end-$begin );
		file_put_contents( $svgPath, $svg );

		$transformed[$svgPath] = true;

		return true;
	}

	/**
	 * @param File $image
	 * @param string $dstPath
	 * @param string $dstUrl
	 * @param array $params
	 * @param int $flags
	 * @return bool|MediaTransformError|ThumbnailImage|TransformParameterError
	 */
	function doTransform( $image, $dstPath, $dstUrl, $params, $flags = 0 ) {
		global $wgVisioToXhtml, $wgUploadDirectory, $wgUploadPath;

		if ( !$this->normaliseParams( $image, $params ) ) {
			return new TransformParameterError( $params );
		}
		$clientWidth = $params['width'];
		$clientHeight = $params['height'];
		$physicalWidth = $params['physicalWidth'];
		$physicalHeight = $params['physicalHeight'];
		$lang = isset( $params['lang'] ) ? $params['lang'] : $this->getDefaultRenderLanguage( $image );

		$svgPath = $wgUploadDirectory . '/generated/visio/' . preg_replace( '#\.+[^\.]*$#is', '', basename( $image->getRel() ) ) . '.svg';
		$svgUrl = $wgUploadPath . '/generated/visio/' . basename( $svgPath );

		if ( $flags & self::TRANSFORM_LATER ) {
			return new ThumbnailImage( $image, $svgUrl, $svgPath, $params );
		}

		if ( !wfMkdirParents( dirname( $dstPath ), null, __METHOD__ ) ) {
			return new MediaTransformError( 'thumbnail_error', $clientWidth, $clientHeight,
				wfMessage( 'thumbnail_dest_directory' )->text() );
		}

		$visioPath = $image->getLocalRefPath();
		if ( $visioPath === false ) { // Failed to get local copy
			wfDebugLog( 'thumbnail',
				sprintf( 'Thumbnail failed on %s: could not get local copy of "%s"',
					wfHostname(), $image->getName() ) );
			return new MediaTransformError( 'thumbnail_error',
				$params['width'], $params['height'],
				wfMessage( 'filemissing' )->text()
			);
		}

		$tmpDir = wfTempDir() . '/visio_' . wfRandomString( 24 );
		$lnPath = "$tmpDir/" . basename( $svgPath );
		/** @noinspection PhpUnusedLocalVariableInspection */
		$cleaner = new ScopedCallback( function () use( $lnPath, $tmpDir ) {
			MediaWiki\suppressWarnings();
			unlink( $lnPath );
			rmdir( $tmpDir );
			MediaWiki\restoreWarnings();
		} );

		if ( !$this->convertToSvg( $visioPath, $svgPath, $error ) ) {
			return new MediaTransformError( 'thumbnail_error',
				$params['width'], $params['height'], $error
			);
		}

		return new ThumbnailImage( $image, $svgUrl, $svgPath, $params );
	}

	/**
	 * @param File $file
	 * @param string $path Unused
	 * @param bool|array $metadata
	 * @return array
	 */
	function getImageSize( $file, $path, $metadata = false ) {
		if ( $metadata === false ) {
			$metadata = $file->getMetadata();
		}
		$metadata = $this->unpackMetadata( $metadata );

		if ( isset( $metadata['width'] ) && isset( $metadata['height'] ) ) {
			return array( $metadata['width'], $metadata['height'], 'SVG',
				"width=\"{$metadata['width']}\" height=\"{$metadata['height']}\"" );
		} else { // error
			return array( 0, 0, 'SVG', "width=\"0\" height=\"0\"" );
		}
	}

	function getThumbType( $ext, $mime, $params = null ) {
		return array( 'svg', 'image/svg' );
	}

	/**
	 * Subtitle for the image. Different from the base
	 * class so it can be denoted that SVG's have
	 * a "nominal" resolution, and not a fixed one,
	 * as well as so animation can be denoted.
	 *
	 * @param File $file
	 * @return string
	 */
	function getLongDesc( $file ) {
		global $wgLang;

		$metadata = $this->unpackMetadata( $file->getMetadata() );
		if ( isset( $metadata['error'] ) ) {
			return wfMessage( 'svg-long-error', $metadata['error']['message'] )->text();
		}

		$size = $wgLang->formatSize( $file->getSize() );

		if ( $this->isAnimatedImage( $file ) ) {
			$msg = wfMessage( 'svg-long-desc-animated' );
		} else {
			$msg = wfMessage( 'svg-long-desc' );
		}

		$msg->numParams( $file->getWidth(), $file->getHeight() )->params( $size );

		return $msg->parse();
	}

	/**
	 * @param File $file
	 * @param string $filename
	 * @return string Serialised metadata
	 */
	function getMetadata( $file, $filename ) {
		global $wgUploadDirectory;
		$metadata = array( 'version' => self::SVG_METADATA_VERSION );
		try {
			$svgPath = $wgUploadDirectory . '/generated/visio/' . preg_replace( '#\.+[^\.]*$#is', '', basename( $filename ) ) . '.svg';
			if ( !$this->convertToSvg( $filename, $svgPath, $error ) ) {
				$metadata['error'] = array(
					'message' => $error,
					'code' => 0,
				);
			} else {
				$metadata += SVGMetadataExtractor::getMetadata( $svgPath );
			}
		} catch ( Exception $e ) { // @todo SVG specific exceptions
			// File not found, broken, etc.
			$metadata['error'] = array(
				'message' => $e->getMessage(),
				'code' => $e->getCode()
			);
			wfDebug( __METHOD__ . ': ' . $e->getMessage() . "\n" );
		}
		return serialize( $metadata );
	}

	function unpackMetadata( $metadata ) {
		MediaWiki\suppressWarnings();
		$unser = unserialize( $metadata );
		MediaWiki\restoreWarnings();
		if ( isset( $unser['version'] ) && $unser['version'] == self::SVG_METADATA_VERSION ) {
			return $unser;
		} else {
			return false;
		}
	}

	function getMetadataType( $image ) {
		return 'parsed-svg';
	}

	function isMetadataValid( $image, $metadata ) {
		$meta = $this->unpackMetadata( $metadata );
		if ( $meta === false ) {
			return self::METADATA_BAD;
		}
		if ( !isset( $meta['originalWidth'] ) ) {
			// Old but compatible
			return self::METADATA_COMPATIBLE;
		}

		return self::METADATA_GOOD;
	}

	protected function visibleMetadataFields() {
		$fields = array( 'objectname', 'imagedescription' );

		return $fields;
	}

	/**
	 * @param File $file
	 * @param bool|IContextSource $context Context to use (optional)
	 * @return array|bool
	 */
	function formatMetadata( $file, $context = false ) {
		$result = array(
			'visible' => array(),
			'collapsed' => array()
		);
		$metadata = $file->getMetadata();
		if ( !$metadata ) {
			return false;
		}
		$metadata = $this->unpackMetadata( $metadata );
		if ( !$metadata || isset( $metadata['error'] ) ) {
			return false;
		}

		/* @todo Add a formatter
		$format = new FormatSVG( $metadata );
		$formatted = $format->getFormattedData();
		*/

		// Sort fields into visible and collapsed
		$visibleFields = $this->visibleMetadataFields();

		$showMeta = false;
		foreach ( $metadata as $name => $value ) {
			$tag = strtolower( $name );
			if ( isset( self::$metaConversion[$tag] ) ) {
				$tag = strtolower( self::$metaConversion[$tag] );
			} else {
				// Do not output other metadata not in list
				continue;
			}
			$showMeta = true;
			self::addMeta( $result,
				in_array( $tag, $visibleFields ) ? 'visible' : 'collapsed',
				'exif',
				$tag,
				$value
			);
		}

		return $showMeta ? $result : false;
	}

	/**
	 * @param string $name Parameter name
	 * @param mixed $value Parameter value
	 * @return bool Validity
	 */
	function validateParam( $name, $value ) {
		if ( in_array( $name, array( 'width', 'height' ) ) ) {
			// Reject negative heights, widths
			return ( $value > 0 );
		} elseif ( $name == 'lang' ) {
			// Validate $code
			if ( $value === '' || !Language::isValidBuiltinCode( $value ) ) {
				wfDebug( "Invalid user language code\n" );

				return false;
			}

			return true;
		}

		// Only lang, width and height are acceptable keys
		return false;
	}

	/**
	 * @param array $params Name=>value pairs of parameters
	 * @return string Filename to use
	 */
	function makeParamString( $params ) {
		$lang = '';
		if ( isset( $params['lang'] ) && $params['lang'] !== 'en' ) {
			$params['lang'] = strtolower( $params['lang'] );
			$lang = "lang{$params['lang']}-";
		}
		if ( !isset( $params['width'] ) ) {
			return false;
		}

		return "$lang{$params['width']}px";
	}

	function parseParamString( $str ) {
		$m = false;
		if ( preg_match( '/^lang([a-z]+(?:-[a-z]+)*)-(\d+)px$/', $str, $m ) ) {
			return array( 'width' => array_pop( $m ), 'lang' => $m[1] );
		} elseif ( preg_match( '/^(\d+)px$/', $str, $m ) ) {
			return array( 'width' => $m[1], 'lang' => 'en' );
		} else {
			return false;
		}
	}

	function getParamMap() {
		return array( 'img_lang' => 'lang', 'img_width' => 'width' );
	}

	/**
	 * @param array $params
	 * @return array
	 */
	function getScriptParams( $params ) {
		$scriptParams = array( 'width' => $params['width'] );
		if ( isset( $params['lang'] ) ) {
			$scriptParams['lang'] = $params['lang'];
		}

		return $scriptParams;
	}

	public function getCommonMetaArray( File $file ) {
		$metadata = $file->getMetadata();
		if ( !$metadata ) {
			return array();
		}
		$metadata = $this->unpackMetadata( $metadata );
		if ( !$metadata || isset( $metadata['error'] ) ) {
			return array();
		}
		$stdMetadata = array();
		foreach ( $metadata as $name => $value ) {
			$tag = strtolower( $name );
			if ( $tag === 'originalwidth' || $tag === 'originalheight' ) {
				// Skip these. In the exif metadata stuff, it is assumed these
				// are measured in px, which is not the case here.
				continue;
			}
			if ( isset( self::$metaConversion[$tag] ) ) {
				$tag = self::$metaConversion[$tag];
				$stdMetadata[$tag] = $value;
			}
		}

		return $stdMetadata;
	}
}
