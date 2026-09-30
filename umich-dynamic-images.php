<?php
/**
 * Plugin Name: University of Michigan: Dynamic Image Resizing
 * Plugin URI: https://github.com/umdigital/umich-dynamic-images/
 * Description: Replaces wordpress built-in pre-built image thumbnails with a dymamic system that creates them on the fly.
 * Version: 1.0.1
 * Author: U-M: OVPC Digital
 * Author URI: http://vpcomm.umich.edu
 * Update URI: https://github.com/umdigital/umich-dynamic-images/releases/latest
 */

class UMDynamicImages
{
    static private $_cronHook        = 'um_dynamic_images_cron_hook';
    static private $_cronRecurrence  = 'daily';
    static private $_defaultSettings = [
        'cache_time' => 60 * 60 * 24, // 24 hours
    ];
    static private $_imageExtensions = ['jpg', 'jpeg', 'jpe', 'gif', 'png', 'webp', 'avif', 'heic'];
    static private $_urlPatterns     = [
        '#(?<path>wp-content/uploads/(sites/[0-9]+/)?(umich-dynamic-images/)?[0-9]+/[0-9]+/)(?<file>(.*))(?<suffix>-(?<width>[0-9]+)x(?<height>[0-9]+)(?<crop>c)?)\.(?<extension>{{EXTENSIONS}})$#i'
    ];

    static private $_settings = [];

    static function init()
    {
        /** PLUGIN UPDATER **/
        // load updater library
        if( file_exists( implode( DIRECTORY_SEPARATOR, [ __DIR__, 'vendor', 'autoload.php' ] ) ) ) {
            include implode( DIRECTORY_SEPARATOR, [ __DIR__, 'vendor', 'autoload.php' ] );
        }

        // Initialize Github Updater
        if( class_exists( '\Umich\GithubUpdater\Init' ) ) {
            new \Umich\GithubUpdater\Init([
                'repo' => 'umdigital/umich-dynamic-images',
                'slug' => plugin_basename( __FILE__ ),
            ]);
        }
        // Show error upon failure
        else {
            add_action( 'admin_notices', function(){
                echo '<div class="error notice"><h3>WARNING</h3><p>U-M: Dynamic Images plugin is currently unable to check for updates due to a missing dependency.  Please <a href="https://github.com/umdigital/umich-dynamic-images">reinstall the plugin</a>.</p></div>';
            });
        }
        /** END: PLUGIN UPDATER **/

        /** LOAD SETTINGS **/
        add_action( 'init', function(){
            self::$_settings = array_merge(
                self::$_defaultSettings,
                array_filter( get_site_option( 'umich_dynamic_images_settings', [] ), 'trim' )
            );
        });
        /** END LOAD SETTINGS **/

        add_filter( 'umich-dynamic-images_url-rewrites', function( $rules ){
            return self::_replaceRegexPlaceholders( $rules );
        }, 99);
        add_filter( 'umich-dynamic-images_url-rewrite-conditions', function( $rules ){
            return self::_replaceRegexPlaceholders( $rules );
        }, 99);
        add_filter( 'umich-dynamic-images_url-patterns', function( $rules ){
            return self::_replaceRegexPlaceholders( $rules );
        }, 99);

        // REWRITE CUSTOM IMAGE SIZES
        add_filter('mod_rewrite_rules', function( $rules ){
            $lines = explode( "\n", $rules );

            $key = array_search( 'RewriteBase /', $lines );
            if( $key !== false ) {
                 $lines = array_merge(
                    array_slice( $lines, 0, ($key + 1) ),
                    apply_filters( 'umich-dynamic-images_url-rewrites', [
                        'RewriteRule ^(wp-content/uploads/[0-9]+/[0-9]+/.+?-[0-9]+x[0-9]+c?\.({{EXTENSIONS}}))$ /wp-content/uploads/umich-dynamic-images/$1 [PT]',
                    ]),
                    array_slice( $lines, ($key + 1) )
                );
            }

            $key = array_search( 'RewriteCond %{REQUEST_FILENAME} !-d', $lines );
            if( $key !== false ) {
                $lines = array_merge(
                    array_slice( $lines, 0, ($key + 1) ),
                    apply_filters( 'umich-dynamic-images_url-rewrite-conditions', [
                        'RewriteCond %{REQUEST_URI} /wp-content/uploads/umich-dynamic-images/.+?-[0-9]+x[0-9]+c?\.({{EXTENSIONS}})(\?.*)?$ [OR]',
                        'RewriteCond %{REQUEST_URI} !\.(jpg|jpe|jpeg|webp|gif|png|avif|heic|svg|svgz|ico|css|zip|tgz|tbz|gz|rar|bz2|pdf|txt|tar|wav|ogg|ogv|webm|mp3|mp4|bmp|rtf|js|flv|swf|html|htm|woff|ttf|ttc|otf|eot|eps|docx?|xlsx?)(\?.*)?$',
                    ]),
                    array_slice( $lines, ($key + 1) )
                );
            }

            return implode( "\n", $lines );
        });

        // HANDLE DYNAMIC IMAGE RESIZING
        add_action('parse_request', function( $wp ){
            $match = false;
            foreach( apply_filters( 'umich-dynamic-images_url-patterns', self::$_urlPatterns ) as $pattern ) {
                if( preg_match( $pattern, $wp->request, $match ) ) {
                    break;
                }
            }

            if( $match ) {
                $uploadsDir = self::_getUploadDir();

                $path   = $match['path'];
                $file   = urldecode( basename( $match['file'] ) );
                $suffix = $match['suffix'];
                $ext    = $match['extension'];
                $width  = $match['width'];
                $height = $match['height'];
                $crop   = empty( $match['crop'] ) ? false : true;

                $source = ABSPATH ."{$path}{$file}.{$ext}";
                $dest   = "{$uploadsDir['basedir']}/umich-dynamic-images/{$path}{$file}{$suffix}.{$ext}";

                if( (realpath( $source ) != $source) || !file_exists( $source ) ) {
                    return;
                }

                // (re)create resized if no cache or its stale
                if( !self::_checkImageCache( $dest, $source ) || !self::_checkImageCache( $dest, __FILE__ ) ) {
                    $image = wp_get_image_editor( $source );
                    if( !is_wp_error( $image ) ) {
                        $image->resize( $width, $height, $crop );
                        $image->save( $dest );
                    }
                }

                if( file_exists( $dest ) ) {
                    $fInfo = finfo_open( FILEINFO_MIME_TYPE );
                    $fileType = finfo_file( $fInfo, $dest );
                    finfo_close( $fInfo );

                    header("Content-Type: {$fileType}");
                    header("Content-Length: ". filesize( $dest ) );
                    header("Last-Modified: ". gmdate( 'D, d M Y H:i:s T', filemtime( $dest ) ) );
                    readfile( $dest );
                    exit;
                }
            }
        });

        // PREVENT WP FROM GENERATING IMAGE SIZES ON UPLOAD
        add_filter('intermediate_image_sizes_advanced', function( $sizes ){
            return [];
        });

        // DONT WORRY WP WE WILL LIE TO YOU LATER
        add_filter('wp_generate_attachment_metadata', function( $meta ){
            return $meta;
        });

        // LIE TO WP ABOUT AVAILABLE IMAGE SIZES
        add_filter('wp_get_attachment_metadata', function( $meta ){
            foreach( wp_get_registered_image_subsizes() as $key => $thumb ) {
                $thumb = array_merge([
                    'width'  => null,
                    'height' => null,
                    'crop'   => null
                ], $thumb );

                // WWWD: what would wp do
                $imgSize = image_resize_dimensions(
                    @$meta['width'],
                    @$meta['height'],
                    $thumb['width'],
                    $thumb['height'],
                    $thumb['crop']
                );

                if( $imgSize ) {
                    $fileInfo = pathinfo( $meta['file'] );
                    $name = wp_basename( $meta['file'], ".{$fileInfo['extension']}" );

                    $suffix = "{$imgSize[4]}x{$imgSize[5]}". ($thumb['crop'] ? 'c' : '');

                    $meta['sizes'][ $key ] = [
                        'file'   => "{$name}-{$suffix}.{$fileInfo['extension']}",
                        "width"  => $imgSize[4],
                        'height' => $imgSize[5]
                    ];
                }
            }

            return $meta;
        });

        // cleanup
        add_action( self::$_cronHook, function(){
            $uploadsDir = self::_getUploadDir();

            $path = "{$uploadsDir['basedir']}/umich-dynamic-images/";
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach( $iterator as $file ) {
                if( $file->isDir() ) {
                    if( count( scandir( $file->getPathname() ) ) == 2 ) {
                        rmdir( $file->getPathname() );
                    }
                }
                else if( (filemtime( $file->getPathname() ) + self::$_settings['cache_time']) < time() ) {
                    unlink( $file->getPathname() );
                }
            }
        });

        if( !wp_next_scheduled( self::$_cronHook ) ) {
            wp_schedule_event(
                strtotime( '03:00:00' ),
                self::$_cronRecurrence,
                self::$_cronHook
            );
        }

        /** ADMIN **/
        add_filter( 'plugin_action_links_'. plugin_basename(__FILE__), function( $links ){
            return array_merge(
                $links, [
                    '<a href="'. admin_url( 'options-general.php?page=umich-dynamic-images' ) .'">Settings</a>'
                ]
            );
        });

        add_action( 'admin_notices', function(){
            if( ($message = get_transient('umich-dynamic-images-message')) ) {
                if( $message['uid'] == get_current_user_id() ) {
                    echo '<div class="notice notice-success is-dismissible"><p>'. esc_html( $message['message'] ) .'</p></div>';
                    delete_transient( 'umich-dynamic-images-message' );
                }
            }
        });

        add_action( 'admin_menu', function( $isNetwork = false ){
            switch( @$_GET['action'] ) {
                case 'purge_cache':
                    $parts = array_merge([
                            'path' => '',
                            'query' => '',
                        ], 
                        parse_url( $_SERVER['REQUEST_URI'] )
                    );  

                    parse_str( $parts['query'], $parts['query'] );
                    unset( $parts['query']['action'] );

                    $parts['query'] = http_build_query( $parts['query'] );
                    $parts['query'] = $parts['query'] ? '?'. $parts['query'] : '';

                    self::doTask( 'purge_cache' );

                    set_transient( 'umich-dynamic-images-message', [
                        'uid'     => get_current_user_id(),
                        'message' => 'Dynamic image cache purged successfully!'
                    ], 10 );

                    wp_redirect( "{$parts['path']}{$parts['query']}" );
                    exit;
                    break;

                default:
                    break;
            }

            // HANDLE FORM SAVE
            if( $_POST && isset( $_POST['umich_dynamic_images_nonce'] ) && wp_verify_nonce( $_POST['umich_dynamic_images_nonce'], 'umich-dynamic-images' ) ) {
                $settings = array_merge([
                        'cache_time' => '',
                    ],
                    array_filter( $_POST['umich_dynamic_images_settings'] ?: [], 'trim' )
                );

                $settings['cache_time'] = $settings['cache_time'] ? (int) $settings['cache_time'] : '';

                $hasErrors = false;

                // verify cache_time
                if( $settings['cache_time'] && !is_int( $settings['cache_time'] ) ) {
                    $hasErrors = true;

                    add_settings_error(
                        'umich_dynamic_images_settings_cache_time',
                        'error',
                        'Invalid Cache Time value.',
                        'error'
                    );
                }

                do_action_ref_array( 'umich_dynamic_images_admin_settings_save', array( &$settings, &$hasErrors ) );

                if( !$hasErrors ) {
                    update_option(
                        'umich_dynamic_images_settings',
                        array_filter( $settings ?: [], 'trim' )
                    );

                    // rebuild class $_settings
                    self::$_settings = array_merge(
                        self::$_defaultSettings,
                        $settings
                    );
                }
            }

            add_submenu_page(
                $isNetwork ? 'settings.php' : 'options-general.php',
                'U-M: Dynamic Images',
                'U-M: Dynamic Images',
                'administrator',
                'umich-dynamic-images',
                function() use ( $isNetwork ) {
                    $umDynamicImagesDefaults = self::$_defaultSettings;
                    $umDynamicImagesSettings = self::$_settings;

                    switch( @$_GET['action'] ) {
                        case 'build_thumbnails':
                            include implode( DIRECTORY_SEPARATOR, [
                                __DIR__, 'templates', 'rebuild-thumbnails.tpl'
                            ]);
                            break;

                        default:
                            include implode( DIRECTORY_SEPARATOR, [
                                __DIR__, 'templates', 'admin.tpl'
                            ]);
                            break;
                    }
                }
            );
        });

        add_action( 'wp_ajax_umich_dynamic_images', function(){
            global $wpdb;

            check_ajax_referer( 'umich-dynamic-images', 'security' );

            if( !current_user_can('administrator') ) {
                wp_send_json_error( 'Unauthorized', 403 );
                exit;
            }

            switch( @$_POST['task'] ) {
                case 'image-list':
                    wp_send_json( self::doTask( $_POST['task'] ) );
                    exit;
                    break;

                case 'image-rebuild':
                    $response = [
                        'id'      => $id,
                        'status'  => 'failure',
                        'message' => 'Unable to locate file.'
                    ];

                    if( self::doTask( $_POST['task'], [ 'id' => @$_POST['id'] ] ) ) {
                        $response['status']  = 'success';
                        $response['message'] = '';
                    }

                    wp_send_json( $response );
                    exit;
                    break;

                case 'image-purge-resized':
                    $response = [
                        'id'      => $id,
                        'status'  => 'failure',
                        'message' => 'Unable to locate file.'
                    ];

                    if( self::doTask( $_POST['task'], [ 'id' => @$_POST['id'] ] ) ) {
                        $response['status']  = 'success';
                        $response['message'] = '';
                    }

                    wp_send_json( $response );
                    exit;
                    break;

                default:
                    wp_send_json_error( 'Invalid Task ('. @$_POST['task'] .')', 404 );
                    exit;
                    break;
            }
        });
        /** END: ADMIN **/
    }

    static public function doTask( $task, $params = false )
    {
        global $wpdb;

        switch( $task ) {
            case 'purge_cache':
                // purge action here
                $uploadsDir = self::_getUploadDir();

                $path = "{$uploadsDir['basedir']}/umich-dynamic-images/";

                if( is_multisite() && (get_current_blog_id() != get_main_site_id()) ) {
                    $path .= 'wp-content/uploads/sites/'. get_current_blog_id() .'/';
                }

                if( is_dir( $path ) ) {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );

                    foreach( $iterator as $file ) {
                        if( $file->isDir() ) {
                            rmdir( $file->getPathname() );
                        }
                        else {
                            unlink( $file->getPathname() );
                        }
                    }
                }

                rmdir( $path );

                return true;
                break;

            case 'image-list':
                $sql = "
                SELECT p.ID,
                       p.post_title AS title,
                       m.meta_value AS file

                  FROM {$wpdb->posts} p

                 INNER JOIN {$wpdb->postmeta} m
                    ON m.post_id = p.ID
                   AND m.meta_key = '_wp_attached_file'

                 WHERE p.post_type = 'attachment'
                   AND p.post_mime_type LIKE 'image/%'

                 ORDER BY p.post_date desc
                ";

                return $wpdb->get_results( $sql );
                break;

            case 'image-rebuild':
                $id   = sanitize_text_field( wp_unslash( @$params['id'] ) );
                $file = get_attached_file( $id );

                if( $file && @file_exists( $file ) ) {
                    $fMeta = wp_get_attachment_metadata( $id, true );

                    // check for original image
                    if( isset( $fMeta['original_image'] ) ) {
                        $oFile = str_replace(
                            basename( $file ),
                            $fMeta['original_image'],
                            $file
                        );

                        if( file_exists( $oFile ) ) {
                            $file = $oFile;
                        }
                    }

                    if( file_exists( $file ) ) {
                        $fMeta['sizes'] = [];
                        foreach( wp_get_registered_image_subsizes() as $sKey => $size ) {
                            $fSize = image_make_intermediate_size(
                                $file,
                                $size['width'],
                                $size['height'],
                                $size['crop']
                            );

                            $fMeta['sizes'][ $sKey ] = $fSize;
                        }

                        wp_update_attachment_metadata(
                            $id,
                            $fMeta
                        );

                        return true;
                    }
                }

                return false;
                break;

            case 'image-purge-resized':
                $id    = sanitize_text_field( wp_unslash( $params['id'] ) );
                $fMeta = wp_get_attachment_metadata( $id, true );

                $uploadsDir = self::_getUploadDir();

                $files = [];
                if( $fMeta && @$fMeta['sizes'] ) {
                    foreach( $fMeta['sizes'] as $sKey => $sFile ) {
                        $tFile = "{$uploadsDir['basedir']}/". str_replace(
                            basename( $fMeta['file'] ),
                            $sFile['file'],
                            $fMeta['file']
                        );

                        if( file_exists( $tFile ) ) {
                            unlink( $tFile );

                            if( !file_exists( $tFile ) ) {
                                unset( $fMeta['sizes'][ $sKey ] );
                            }
                        }
                    }

                    wp_update_attachment_metadata(
                        $id,
                        $fMeta
                    );

                    return true;
                }

                return false;
                break;

            default:
                return false;
                break;
        }
    }

    static private function _getUploadDir()
    {
        if ( is_multisite() ) {
            switch_to_blog( get_main_site_id() );
        }

        $uploadDir = wp_upload_dir();

        if ( is_multisite() ) {
            restore_current_blog();
        }

        return $uploadDir;
    }

    static private function _checkImageCache( $cache, $source )
    {
        if( file_exists( $cache ) ) {
            // cache is newer than source then OK
            if( $source && (@filemtime( $cache ) > @filemtime( $source )) ) {
                return true;
            }
        }

        return false;
    }

    static private function _replaceRegexPlaceholders( $rules )
    {
        foreach( $rules as &$rule ) {
            $rule = str_replace(
                '{{EXTENSIONS}}',
                implode( '|', apply_filters( 'umich-dynamic-images_extensions', self::$_imageExtensions ) ),
                $rule
            );
        }

        return $rules;
    }
}
UMDynamicImages::init();


/** WPCLI INTEGRATION **/
class UMDynamicImagesWPCLI
{
    public function purge_cache()
    {
        UMDynamicImages::doTask('purge_cache');

        WP_CLI::success( 'UMich Dynamic Images Cache purged successfully!' );
    }

    public function rebuild_thumbnails()
    {
        $images = UMDynamicImages::doTask('image-list');

        $progress = \WP_CLI\Utils\make_progress_bar( 'Progress:', count( $images ) );
        $progress->tick(0);

        foreach( $images as $image ) {
            UMDynamicImages::doTask( 'image-rebuild', [ 'id' => $image->ID ] );

            $progress->tick();
        }

        $progress->finish();

        WP_CLI::success( 'Thumbnail images have been rebuilt successfully.' );
    }

    public function delete_thumbnails()
    {
        $images = UMDynamicImages::doTask('image-list');

        $progress = \WP_CLI\Utils\make_progress_bar( 'Progress:', count( $images ) );
        $progress->tick(0);

        foreach( $images as $image ) {
            UMDynamicImages::doTask( 'image-purge-resized', [ 'id' => $image->ID ] );

            $progress->tick();
        }

        $progress->finish();

        WP_CLI::success( 'Thumbnail images have been deleted successfully.' );
    }
}

if( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'umich-dynamic-images', 'UMDynamicImagesWPCLI' );
}
