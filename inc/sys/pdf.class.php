<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

if ( ! class_exists( 'QSOT_pdf' ) ):

if ( ! defined( 'QSOT_DEBUG_PDF' ) )
	define( 'QSOT_DEBUG_PDF', 0 );

class QSOT_pdf {
	protected static $dompdf_admin_notice = '';

	// setup our class
	public static function pre_init() {
		// during activation, we need to do a couple things
		add_action( 'qsot-activate', array( __CLASS__, 'on_activate' ), 5000 );

		// setup the dompdf temp dir path, right before we are about to render the page
		add_action( 'wp_loaded', array( __CLASS__, 'setup_dompdf_tmp_path' ), 10 );

		// if we are in the admin, then...
		if ( is_admin() ) {
			add_action( 'qsot-otce-updated', array( __CLASS__, 'after_plugin_update' ), 10 );

			// add an admin notice for any DOMPDF related error messages
			add_action( 'admin_notices', array( __CLASS__, 'show_dompdf_admin_notices' ), 10 );
		}
	}

	// after the plugin is updated, we may need to copy new fonts over
	public static function after_plugin_update() {
		self::on_activate();
	}

	// during page load, setup the paths that dompdf will use. some paths include the FONT dirs and the TEMP path
	public static function setup_dompdf_tmp_path() {
		// setup the TMP path
		if ( ! defined( 'DOMPDF_TEMP_DIR' ) )
			define( 'DOMPDF_TEMP_DIR', self::_get_dompdf_tmp_dir() );

		// determine the cache dir name
		$final_path = self::_font_cache_path();

		try {
			// find the font path to use
			$font_path = QSOT_cache_helper::create_find_path( $final_path, 'fonts' );
			if ( ! is_writable( $font_path ) )
				throw new Exception( sprintf( __( 'The %s path is not writable. Please update the permissions to allow write access.', 'opentickets-community-edition' ), 'fonts' ) );

			// setup the FONT paths
			if ( ! defined( 'DOMPDF_FONT_DIR' ) )
				define( 'DOMPDF_FONT_DIR', $font_path );
			if ( ! defined( 'DOMPDF_FONT_CACHE' ) )
				define( 'DOMPDF_FONT_CACHE', $font_path );
		} catch ( Exception $e ) { }
	}

	// display any dompdf related admin notices
	public static function show_dompdf_admin_notices() {
		// if there is no message to display, then bail early
		if ( ! self::$dompdf_admin_notice )
			return;

		// render the message
		?>
			<div class="error">
				<p>
					<span class="dashicons dashicons-no"></span>
					<?php echo self::$dompdf_admin_notice ?>
				</p>
			</div>
		<?php
	}

	// actually find or create the appropriate dompdf tmp dir path
	protected static function _get_dompdf_tmp_dir() {
		// get the unique dir name from the database
		$dirname = get_option( '_qsot_dompdf_tmp_path', '' );

		// if that name does not exist yet, make one
		if ( empty( $dirname ) )
			$dirname = self::_new_dompdf_tmp_dirname();

		// get the actual path to the directory to use
		$pathname = self::_find_or_create_dompdf_tmp_dir( $dirname );

		// if the path lookup failed, fallback to what the system reports as the tmp dir
		return $pathname ? $pathname : sys_get_temp_dir();
	}

	// find or create the temp dir, based on the temp dir name supplied
	protected static function _find_or_create_dompdf_tmp_dir( $dirname ) {
		// keep track of the times we attempt to create a new dir
		static $iteration = 0;

		$u = wp_upload_dir();
		$pathname = trailingslashit( $u['basedir'] ) . $dirname;
		// if the dir exists, return it now
		if ( file_exists( $pathname ) && is_dir( $pathname ) && is_writable( $pathname ) && is_readable( $pathname ) )
			return $pathname;

		// otherwise increment the creation attempt tracker
		$iteration++;

		// if we have tried to create it more than twice, then bail
		if ( $iteration > 2 ) {
			self::$dompdf_admin_notice = __( 'Tried to create a new DOMPDF temp directory twice, and failed both times. There is probably a permission issue in your uploads directory.', 'opentickets-community-edition' );
			return false;
		}

		// if the path exists, but is not a dir, try once more
		if ( file_exists( $pathname ) && ! is_dir( $pathname ) )
			return self::_find_or_create_dompdf_tmp_dir( self::_new_dompdf_tmp_dirname() );

		// if the path simply does not exist, attempt to create it now
		if ( ! file_exists( $pathname ) ) {
			// if the parent dir is not writable, then bail with an error
			if ( ! is_writable( $u['basedir'] ) || ! mkdir( $pathname, 0755, true ) ) {
				self::$dompdf_admin_notice = sprintf(
					__( 'Your main uploads path is NOT writable. You must resolve this issue before PDF creation will work properly. The path is %s', 'opentickets-community-edition' ),
					'<code>' . $u['basedir'] . '</code>'
				);
				return false;
			} else {
				return true;
			}
		}

		// if the file exists, is a dir, but is not readable, bail with a message
		if ( file_exists( $pathname ) && ! is_readable( $pathname ) ) {
			self::$dompdf_admin_notice = sprintf(
				__( 'The DOMPDF temp path does exist, but the file permissions do not allow reading. Please open the file permissions for path %s so that it is readable and writable.', 'opentickets-community-edition' ),
				'<code>' . $u['basedir'] . '</code>'
			);
			return false;
		}

		// if the file exists, is a dir, but is not writable, bail with a message
		if ( file_exists( $pathname ) && ! is_readable( $pathname ) ) {
			self::$dompdf_admin_notice = sprintf(
				__( 'The DOMPDF temp path does exist, but the file permissions do not allow writing, which is required. Please open the file permissions for path %s so that it is readable and writable.', 'opentickets-community-edition' ),
				'<code>' . $u['basedir'] . '</code>'
			);
			return false;
		}

		// if we got here, it means we have a problem that i dont know how to test. bail with a generic message
		self::$dompdf_admin_notice = __( 'The DOMPDF temporary dir does not exist and could not be created for an unknown reason. Please contact support, or troubleshoot the issue yourself.', 'opentickets-community-edition' );
		return false;
	}

	// create a new dirname for the dompdf tmp path
	protected static function _new_dompdf_tmp_dirname() {
		$dirname = 'qsot-dompdf-tmp-' . substr( md5( AUTH_SALT . microtime( true ) . rand( 0, PHP_INT_MAX ) . AUTH_KEY ), 11, 15 );
		update_option( '_qsot_dompdf_tmp_path', $dirname );
		return $dirname;
	}

	// allow some pre-processing to occur on html before it gets integrated into a final pdf
	public static function from_html( $html, $filename ) {
		// give us soem breathing room
		ini_set( 'max_execution_time', 180 );
		//$ohtml = $html;

		// pre-parse remote or url based assets
		try {
			$html = self::_pre_parse_remote_assets( $html );
		} catch ( Exception $e ) {
			die('error');
			echo '<h1>Problem parsing html.</h1>';
			echo '<h2>' . force_balance_tags( $e->getMessage() ) . '</h2>';
			return;
		}

		// if we are debugging the pdf, then depending on the mode, dump the html contents onw
		if ( ( QSOT_DEBUG_PDF & 2 ) ) { // || ( current_user_can( 'edit_posts' ) && isset( $_GET['as'] ) && 'html' == $_GET['as'] ) ) {
			echo '<pre>';
			echo "RELEVANT DIRS\n";
			var_dump( DOMPDF_TEMP_DIR, DOMPDF_FONT_DIR, DOMPDF_FONT_CACHE );
			echo "\n\nHTML OUTPUT\n";
			echo htmlspecialchars( $html );
			echo '</pre>';
			die();
		}

		// include the library
		require_once QSOT::plugin_dir() . 'libs/dompdf/dompdf_config.inc.php';

		// make and output the pdf
		$pdf = new DOMPDF();
		$pdf->load_html( $html );
		$pdf->render();
		$pdf->stream( sanitize_file_name( $filename ), array( 'Attachment' => 1 ) );
		exit;
	}

	// find any and all remote assets in the html of the pdf, and either cache them locally, and use the local url, or embed them directly into the html
	protected static function _pre_parse_remote_assets( $html ) {
		// next, 'flatten' all styles
		$html = preg_replace_callback( '#\<link([^\>]*?(?:(?:(\'|")[^\2]*?\2)[^\>]*?)*?)\>#s', array( __CLASS__, '_flatten_styles' ), $html );

		// remove the srcset and sizes attributes
		$html = preg_replace( '#(sizes|srcset)="[^"]*?"#', '', $html );

		// find all images in the html, and try to localize them into base64 strings
		$html = preg_replace_callback( '#\<img([^\>]*?(?:(?:(\'|")[^\2]*?\2)[^\>]*?)*?)\>#s', array( __CLASS__, '_parse_image' ), $html );

		return $html;
	}

	// aggregate all css into style tags instead of link tags, and flatten imports
	protected static function _flatten_styles( $match ) {
		// if this is not a stylesheet link tag, then bail
		if ( ! preg_match( '#rel=[\'"]stylesheet[\'"]#', $match[0] ) )
			return $match[0];

		// get the tag atts
		$atts = self::_get_atts( $match[1] );

		// if there is no url then remove and bail
		if ( ! isset( $atts['href'] ) || empty( $atts['href'] ) )
			return '<!-- FAIL CSS 1: ' . $match[0] . ' -->';

		// get css file contents based on url
		$css_contents = self::_get_css_content( $atts['href'] );

		// if there is no content to embed, then remove and bail
		if ( empty( $css_contents ) )
			return '<!-- FAIL CSS 2: ' . $match[0] . ' -->';

		return '<style>' . $css_contents . '</style>';
	}

	// get the contents of a css file, and flatten the @import tags
	protected static function _get_css_content( $url ) {
		// get the image local path
		$local_path = QSOT_cache_helper::find_local_path( $url );

		// if there is not a local file, then bail with empty str
		if ( ! $local_path )
			return '';

		// get the contents of the local file
		$content = ! WP_DEBUG ? @file_get_contents( $local_path ) : file_get_contents( $local_path );

		// if there is no content then return empty content
		if ( ! $content || '' == trim( $content ) )
			return '';

		// flatten any @imports
		$content = preg_replace_callback( '#@import url\((.*?)\);#', array( __CLASS__, '_flatten_at_import' ), $content );

		return $content;
	}

	// flatten any @import tag we found
	public static function _flatten_at_import( $match ) {
		// if there is no import url, then remove and bail
		if ( empty( $match[1] ) )
			return '';

		return self::_get_css_content( $match[1] );
	}

	// construct a new image tag based on the one we found
	public static function _parse_image( $match ) {
		// get the tag atts
		$atts = self::_get_atts( $match[1] );

		// if there is not an src, bail
		if ( ! isset( $atts['src'] ) || empty( $atts['src'] ) )
			return $match[0] . ( WP_DEBUG ? '<!-- NO SRC : BAIL -->' : '' );

		// roll through embeded images
		if ( preg_match( '#^data:image\/#', $atts['src'] ) )
			return $match[0] . ( WP_DEBUG ? '<!-- EMBEDED SRC : BAIL -->' : '' );

		// get the image local path
		$local_path = QSOT_cache_helper::find_local_path( $atts['src'] );

		// if there was not a local path to be found, then remove the image from the output and bail
		if ( '' == $local_path )
			return ( WP_DEBUG ? '<!-- NO LOCAL PATH -->' : '' );

		// next, text that the local file is actually an image
		$img_data = ! WP_DEBUG ? @getimagesize( $local_path ) : getimagesize( $local_path );

		// there was no image data, or is not a vaild supported type, remove and then bail
		if ( ! is_array( $img_data ) || ! isset( $img_data[0], $img_data[1], $img_data[2] ) || ! in_array( $img_data[2], array( IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_JPEG ) ) )
			return ( WP_DEBUG ? '<!-- INVALID IMAGE TYPE : ' . $local_path . ' : ' . ( is_array( $img_data ) ? http_build_query( $img_data ) : 'NOT-ARRAY' ) . ' -->' : '' );

		// set the image path to the local path
		$atts['src'] = $local_path;

		// set the width and height from the data
		if ( ( ! isset( $atts['width'] ) || empty( $atts['width'] ) ) && $img_data[0] )
			$atts['width'] = $img_data[0];
		if ( ( ! isset( $atts['height'] ) || empty( $atts['height'] ) ) && $img_data[1] )
			$atts['height'] = $img_data[1];

		// reconstruct the img tag
		$pieces = array();
		foreach ( $atts as $k => $v )
			$pieces[] = $k . '="' . esc_attr( $v ) . '"';
		$tag = '<img ' . implode( ' ', $pieces ) . ' />';

		// if debugging, the add the path that we are looking for right above the image
		if ( WP_DEBUG && QSOT_DEBUG_PDF & 1 )
			$tag = sprintf(
					'<pre style="width:%spx;height:auto;font-size:10px;border:1px solid #000;word-wrap:break-word;display:block;">%s</pre>',
					$atts['width'],
					implode( '<br/>', str_split( $local_path, ( $atts['width'] / 6 ) - 1 ) )
				) . $tag;

		return $tag;
	}

	// get the attributes from part of a tag element
	protected static function _get_atts( $str ) {
		// get the atts
		preg_match_all( '#([^\s=]+?)=([\'"])([^\2]*?)\2#', $str, $raw_atts, PREG_SET_ORDER );

		// parse the raw atts into key value pairs
		$atts = array();
		if ( count( $raw_atts ) )
			foreach ( $raw_atts as $attr )
				$atts[ $attr[1] ] = $attr[3];

		return $atts;
	}

	// fetch the pdf font cache directory
	protected static function _font_cache_path() {
		// determine the cache dir name
		$u = wp_upload_dir();
		$base_dir_name = 'qsot-dompdf-fonts-' . substr( sha1( site_url() ), 21, 5 );
		$final_path = $u['basedir'] . DIRECTORY_SEPARATOR . $base_dir_name . DIRECTORY_SEPARATOR;
		return $final_path;
	}

	// during activation
	public static function on_activate() {
		// determine the cache dir name
		$final_path = self::_font_cache_path();

		try {
			$font_path = QSOT_cache_helper::create_find_path( $final_path, 'fonts' );
			if ( ! is_writable( $font_path ) )
				throw new Exception( sprintf( __( 'The %s path is not writable. Please update the permissions to allow write access.', 'opentickets-community-edition' ), 'fonts' ) );
		} catch ( Exception $e ) {
			// just fail. we can go without the custom config
			return;
		}

		$libs_dir = QSOT::plugin_dir() . 'libs/';
		// make sure that the libs dir is also writable
		/* dont need this since we never remove the files
		if ( ! @file_exists( $libs_dir ) || ! is_dir( $libs_dir ) || ! is_writable( $libs_dir ) ) 
			return;
		*/

		// find all the fonts that come with the lib we packaged with the plugin, and move them to the new fonts dir, if they are not already there
		$remove_files = $updated_files = array();
		$core_fonts_dir = $libs_dir . 'dompdf/lib/fonts/';
		// open the core included fonts dir
		if ( @file_exists( $core_fonts_dir ) && is_readable( $core_fonts_dir ) && ( $dir = opendir( $core_fonts_dir ) ) ) {
			// find all the files in the dir
			while ( $file_basename = readdir( $dir ) ) {
				$filename = $core_fonts_dir . $file_basename;
				$new_filename = $font_path . $file_basename;
				// if the current file is a dir or link, skip it
				if ( is_dir( $filename ) || is_link( $filename ) )
					continue;

				// overwrite any existing copy of the file, with the new version from the updated plugin
				if ( copy( $filename, $new_filename ) ) {
					$remove_files[] = $filename;
					$updated_files[] = basename( $filename );
				}
			}

			file_put_contents( $font_path . 'updated', 'updated on ' . date( 'Y-m-d H:i:s' ) . ":\n" . implode( "\n", $remove_files ) );

			// attempt to create the new custom config file
			/* handling this a different way now
			if ( $config_file = fopen( $libs_dir . 'wp.dompdf.config.php', 'w+' ) ) {
				// create variable names to use in the heredoc
				$variable_names = array(
					'$_SERVER["SCRIPT_FILENAME"]',
				);

				// generate the contents of the config file
				$contents = <<<CONTENTS
<?php if ( __FILE__ == {$variable_names[0]} ) die( header( 'Location: /' ) );
if ( ! defined( 'DOMPDF_FONT_DIR' ) )
	define( 'DOMPDF_FONT_DIR', '$font_path' );
if ( ! defined( 'DOMPDF_FONT_CACHE' ) )
	define( 'DOMPDF_FONT_CACHE', '$font_path' );
CONTENTS;
				
				// write the config file, and close it
				fwrite( $config_file, $contents, strlen( $contents ) );
				fclose( $config_file );

				// remove any files that are marked to be removed, now that we have successfully written them to the new location, and pointed DOMPDF at them
				// skip this for now
				//if ( is_array( $remove_files ) && count( $remove_files ) )
				//	foreach ( $remove_files as $remove_file )
				//		if ( is_writable( $remove_file ) && ! is_dir( $remove_file ) && ! is_link( $remove_file ) )
				//			@unlink( $remove_file );
			}
			*/

			// attempt to remove the previously created wp.dompdf.config.php file, since we dont need it anymore
			if ( file_exists( $libs_dir . 'wp.dompdf.config.php' ) && is_writable( $libs_dir . 'wp.dompdf.config.php' ) )
				@unlink( $libs_dir . 'wp.dompdf.config.php' );
		}
	}
}

if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_pdf::pre_init();

endif;

if ( ! class_exists( 'QSOT_cache_helper' ) ):

class QSOT_cache_helper {
	// cached asset map, original_url => local_file_path
	protected static $cache_map = array();

	// cache path
	protected static $cache_path = false;

	// is this a force recache?
	protected static $force_recache = null;

	// figure out the local path of the asset, based on the found url
	public static function find_local_path( $url ) {
		static $local = false, $local_url = false, $whats_old_in_seconds = false;

		// determine if this is a force recache
		if ( null === self::$force_recache )
			self::$force_recache = isset( $_COOKIE, $_COOKIE['qsot-recache'] ) && '1' == $_COOKIE['qsot-recache'];

		// figure out what is considered old, in seconds
		if ( false === $whats_old_in_seconds )
			$whats_old_in_seconds = apply_filters( 'qsot-whats-old-in-seconds', 7 * DAY_IN_SECONDS );

		// figure out the local url
		if ( false === $local )
			$local = ! WP_DEBUG ? @parse_url( $local_url = site_url() ) : parse_url( $local_url = site_url() );

		// if we have already cached the local path of this asset, then just use the cached value
		if ( isset( self::$cache_map[ $url ] ) )
			return self::$cache_map[ $url ];

		// parse the src
		$parsed_url = ! WP_DEBUG ? @parse_url( $url ) : parse_url( $url );

		// if the parse failed, something is majorly effed up with the image, so remove the image from the html and bail
		if ( false == $parsed_url )
			return self::$cache_map[ $url ] = '';

		// try to find/create a relevant local copy of the src

		// if this is a URL to a resource outside of this site, then
		if ( ! self::_is_local_file( $parsed_url, $local, $url, $local_url ) ) {
			// figure out the cache dir now, since we definitely need it
			if ( false === self::$cache_path )
				self::create_find_cache_dir();
			if ( false === self::$cache_path )
				return self::$cache_map[ $url ] = '';

			// figure out the target filename, based on the url
			$local_filename = self::_local_filename_from_url( $url, $parsed_url );
			if ( empty( $local_filename ) )
				return self::$cache_map[ $url ] = '';
			$local_filename = self::$cache_path . $local_filename;

			// if the file does not already exist, or if it is old, then fetch and store a new copy
			$age = self::_get_file_age( $local_filename );
			if ( self::$force_recache || ! $age || $age > $whats_old_in_seconds ) {
				// setup the http api args
				$args = array(
					'timeout' => 5,
					'redirection' => 3,
				);

				// get the final response
				$response = wp_remote_get( html_entity_decode( $url ), $args );
				if ( WP_DEBUG && is_wp_error( $response ) )
					die( var_dump( 'WP_Error on wp_remote_get( "'. $url . '", ' . @json_encode( $args ) . ' )', $response ) );
				$response = is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';

				// if there was not a valid response, then bail now
				if ( empty( $response ) )
					return self::$cache_map[ $url ] = '';

				// write the data to the local file. on failure, bail
				$test = ! WP_DEBUG ? ! @file_put_contents( $local_filename, $response ) : ! file_put_contents( $local_filename, $response );
				if ( $test )
					return self::$cache_map[ $url ] = '';
			}

			// update the cached filename, and return the new file path
			self::$cache_map[ $url ] = $local_filename;
			return self::$cache_map[ $url ] = 'file://' . $local_filename;
		}

		// if it is not obviously a remote asset, assume it is local, and start trying to find the actual filename
		// if the path is empty, bail
		if ( '' == $parsed_url['path'] || '/' == $parsed_url['path'] || '\\' == $parsed_url['path'] )
			return self::$cache_map[ $url ] = '';

		// if it is a relative path, then figure out the part of the path that would make it absolute
		$extra_path = '';
		if ( '/' !== $parsed_url['path']{0} && '\\' !== $parsed_url['path']{0} ) {
			// find the request path
			$req_purl = ! WP_DEBUG ? @parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' ) : parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' );

			// if there is a paht, and if the request_uri is a dir, then use the 'path' as the extra_path. otherwise it is probably a file, so get the parent dir of the file
			$test_path = realpath( ABSPATH . $req_purl['path'] );
			if ( '' != $req_purl['path'] && $test_path )
				$extra_path = trailingslashit( is_dir( $test_path ) ? $req_purl['path'] : dirname( $req_purl['path'] ) );
		}

		// adjust the parsed_url path to account for the wordpress install being in a subdir of the domain
		$parsed_path = $parsed_url['path'];
		if ( ( '/' === $parsed_path{0} || '\\' === $parsed_path{0} ) && isset( $local['path'] ) && ( $local_adjust = untrailingslashit( $local['path'] ) ) && 0 === strpos( $parsed_path, $local_adjust ) )
			$parsed_path = substr( $parsed_path, strlen( $local_adjust ) );

		// if the file exists, is a file, and is readable, then success
		$test_path = realpath( ABSPATH . $extra_path . $parsed_path );
		$test = ! WP_DEBUG
				? $test_path && @file_exists( $test_path ) && @is_file( $test_path ) && @is_readable( $test_path )
				: $test_path && file_exists( $test_path ) && is_file( $test_path ) && is_readable( $test_path );
		if ( $test )
			return self::$cache_map[ $url ] = 'file://' . $test_path;

		// otherwise bail on absolute paths
		return self::$cache_map[ $url ] = '';
	}

	// determine if a url is of a local resource
	protected static function _is_local_file( $parsed_url, $parsed_local, $url, $local_url ) {
		// if there is no url host, then it is assumed that the host is the local host, meaning it is a local file
		if ( ! isset( $parsed_url['host'] ) )
			return true;

		// if the scheme is present, and set to 'file' then it is definitely supposed to be a local asset
		if ( isset( $parsed_url['scheme'] ) && 'file' === strtolower( $parsed_url['scheme'] ) )
			return true;

		// on windows servers d:/path/to/file gets registerd as a url with scheme d and path /path/to/file. we need to compensate for this
		// do this by a regex test to see if the path starts with the path to the installation
		$test_path = preg_replace( '#^' . preg_quote( ABSPATH, '#' ) . '#', '', $url );
		if ( $test_path != $url )
			return true;

		// figure out the host and path of both urls. this will help determine if this asset lives at a local path. the site_url() could be a host with a path, if the installation is in a subdir
		$url = explode( '/', $url, 3 );
		$local_url = explode( '/', $local_url, 3 );
		$remote_path = strtolower( end( $url ) );
		$local_path = strtolower( end( $local_url ) );

		// if the local path is present at the beginning of the remote path string, then it is a local path
		if ( 0 === strpos( $remote_path, $local_path ) )
			return true;

		// otherwise it is most logically a remote file
		return false;
	}

	// build a local filename based on the remote url path
	protected static function _local_filename_from_url( $url, $purl ) {
		// find out the extension of the file
		$ext = '';
		if ( isset( $purl['path'] ) ) {
			$basename = $purl['path'];
			if ( ! empty( $basename ) ) {
				$basename = explode( '.', $basename );
				if ( count( $basename ) > 1 )
					$ext = end( $basename ) . '.';
			}
		}

		// create and return a unique, secure, but consistent cache name for the file
		return $ext . sha1( AUTH_SALT . $url );
	}

	// determine the age of a local file
	protected static function _get_file_age( $path ) {
		// get the file modify time
		$ftime = ! WP_DEBUG || ! ( QSOT_DEBUG_PDF & 4 ) ? @filemtime( $path ) : filemtime( $path );

		// if there was no file modify time, then the file does not exist, for bail
		if ( false === $ftime )
			return false;

		// adjust for the windows DST bug http://bugs.php.net/bug.php?id=40568
		$ftime_dst = ( date( 'I', $ftime ) == 1 );
		$system_dst = ( date( 'I' ) == 1 );

		// calculate the DST bug adjustment
		$adjustment = 0;
		if ( ! $ftime_dst && $system_dst )
			$adjustment = 3600;
		else if ( $ftime_dst && ! $system_dst )
			$adjustment = -3600;

		// finalize ftime
		$ftime = $ftime + $adjustment;

		return time() - $ftime;
	}

	// create or find the cache dir
	public static function create_find_cache_dir() {
		// if we already have a cache path reutrn it
		if ( ! empty( self::$cache_path ) )
			return self::$cache_path;

		// determine the cache dir name
		$u = wp_upload_dir();
		$base_dir_name = 'qsot-cache-' . substr( sha1( site_url() ), 10, 10 );
		$final_path = $u['basedir'] . DIRECTORY_SEPARATOR . $base_dir_name . DIRECTORY_SEPARATOR;

		return self::$cache_path = trailingslashit( self::create_find_path( $final_path ) );
	}

	// create or find a path
	public static function create_find_path( $final_path, $path_name='cache' ) {
		// if the path already exists, just return the path
		$test = ! WP_DEBUG ? @file_exists( $final_path ) && @is_dir( $final_path ) && @is_readable( $final_path ) : file_exists( $final_path ) && is_dir( $final_path ) && is_readable( $final_path );
		if ( $test )
			return trailingslashit( $final_path );

		// if the path is simply not readable, exception saying that
		$test = ! WP_DEBUG ? @file_exists( $final_path ) && ! @is_readable( $final_path ) : file_exists( $final_path ) && ! is_readable( $final_path );
		if ( $test )
			throw new Exception( sprintf( __( 'The %s path exists, but it cannot be read. Please update the permissions to allow read access.', 'opentickets-community-edition' ), $path_name ) );

		// if the path is there, but is not a dir, exception saying that
		$test = ! WP_DEBUG ? @file_exists( $final_path ) && ! @is_dir( $final_path ) : file_exists( $final_path ) && ! is_dir( $final_path );
		if ( $test )
			throw new Exception( sprintf( __( 'The %s path exists, but is not a dir. Please remove or rename the existing file, and create a directory with the cache path name.', 'opentickets-community-edition' ), $path_name ) );

		// at this point the path probably does not exist. try to create it.

		// first check if we have permission to create it
		$parent_dir = dirname( $final_path );
		$test = ! WP_DEBUG ? ! @is_writable( $parent_dir ) : ! is_writable( $parent_dir );
		if ( $test )
			throw new Exception( sprintf( __( 'Could not create the %s path directory. Please update the permissions to allow write access.', 'opentickets-community-edition' ), $path_name ) );

		// attempt to create a new dir for the path
		$test = ! WP_DEBUG ? ! @mkdir( $final_path ) : ! mkdir( $final_path );
		if ( $test )
			throw new Exception( sprintf( __( 'Unable to create the %s path directory.', 'opentickets-community-edition' ), $path_name ) );

		// if thei new path is not writable (unlikely) then fail
		$test = ! WP_DEBUG ? ! @is_writeable( $final_path ) : ! is_writeable( $final_path );
		if ( $test )
			throw new Exception( sprintf( __( 'The %s path is not writable. Please update the permissions to allow write access.', 'opentickets-community-edition' ), $path_name ) );

		return $final_path;
	}
}

endif;
