<div class="wrap">
    <h2>U-M: Dynamic Images</h2>
    <h3>Settings</h3>
    <form method="post" action="<?=($isNetwork ? 'settings.php' : 'options-general.php');?>?page=<?=$_GET['page'];?>">
        <?php wp_nonce_field( 'umich-dynamic-images', 'umich_dynamic_images_nonce' ); ?>

        <table class="form-table">
            <tr valign="top">
                <th scope="row"><label for="umdi-cache_time">Cache Time</label></th>
                <td>
                    <input type="number" id="umdi-cache_time" name="umich_dynamic_images_settings[cache_time]" value="<?=$umDynamicImagesSettings['cache_time'];?>" placeholder="Enter Time in Seconds" class="regular-text" aria-describedby="umdi-cache_time-description" />
                    <br/>
                    <p class="description" id="umdi-cache_time-description">Max amount of time (in seconds) to keep a resized image cached on disk. Default: <em><?=$umDynamicImagesDefaults['cache_time'];?></em> seconds.</p>
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>

    <h3>Actions</h3>
    <a href="<?=($isNetwork ? 'settings.php' : 'options-general.php');?>?page=<?=$_GET['page'];?>&action=purge_cache" class="components-button is-secondary is-destructive is-compact" onclick="return confirm('Are you sure you want to clear the cache?')"><span class="dashicons dashicons-trash"></span> Purge Cache</a>

    <h3>Wordpress Thumbnails</h3>
    <p>Here you can optionally build and delete the default wordpress thumbnail files.</p>
    <button id="thumbnails-build" class="components-button is-secondary is-compact"><span class="dashicons dashicons-admin-generic"></span> Build Thumbnails</button>
    <button id="thumbnails-delete" class="components-button is-secondary is-destructive is-compact"><span class="dashicons dashicons-admin-generic"></span> Delete Thumbnails</button>
    <div id="thumbnail-action-wrapper"></div>
</div>

<script>
(function($){
    let nonce = '<?=wp_create_nonce( 'umich-dynamic-images' );?>';

    $(document).ready(function(){
        let thumbnailWrapper = $('#thumbnail-action-wrapper');

        // status = error, warning, success, info
        let setMessage = function( message, status, dismissible ) {
            status = status || 'unknown';

            if( dismissible ) {
                dismissible = 'is-dismissible';
            }

            thumbnailWrapper.html(
                `<div class="notice notice-${status} ${dismissible}"><p>${message}</p></div>`
            );
        };

        $('#thumbnails-build').on('click', function( event ){
            event.preventDefault();

            if( confirm( 'This process may take some time. Do not close the window.  Do you want to continue?' ) ) {
                setMessage('Gathering images...', 'info');

                $.ajax({
                    url : ajaxurl,
                    type: 'POST',
                    data: {
                        security: nonce,
                        action  : 'umich_dynamic_images',
                        task    : 'image-list',
                    },
                    dataType: 'json',
                    success: function( imageList ){
                        let imageErrors = [];

                        setMessage( imageList.length +' images found.', 'info' );

                        let cIndex = 0;

                        let rebuildImage = function( retry ) {
                            retry = retry || 0;

                            if( cIndex >= imageList.length ) {
                                setMessage( 'Processing Completed.', imageErrors.length ? 'warning' : 'success', true );

                                if( imageErrors.length ) {
                                    thumbnailWrapper.find('.notice').append('<p>Failed Images:</p><ul class="ul-disc"></ul>');

                                    imageErrors.forEach( (image) => {
                                        thumbnailWrapper.find('.notice ul').append(`<li>[${image.ID}] ${image.file}</li>`);
                                    });
                                }
                                return;
                            }

                            let imgNum = cIndex + 1;
                            let image  = imageList[ cIndex ];
                            setMessage( `Processing Image: (${imgNum} / ${imageList.length}) — <strong>${image.file}</strong>`, 'warning' );

                            $.ajax({
                                url : ajaxurl,
                                type: 'POST',
                                data: {
                                    security: nonce,
                                    action  : 'umich_dynamic_images',
                                    task    : 'image-rebuild',
                                    id      : image.ID
                                },
                                dataType: 'json',
                                success: function( res ){
                                    cIndex++;

                                    if( res.status != 'success' ) {
                                        imageErrors.push( image );
                                    }

                                    rebuildImage();
                                },
                                error: function( req, status, error ){
                                    console.log([ 'Rebuild error', req, image ]);
                                    retry++;

                                    if( retry >= 5 ) {
                                        console.log([`Exceeded retry count.`, image]);
                                        imageErrors.push( image );

                                        cIndex++;

                                        rebuildImage();
                                    }
                                    else {
                                        setTimeout(function(){
                                            rebuildImage( retry );
                                        }, 1000 );
                                    }
                                }
                            });
                        }

                        rebuildImage();
                    },
                    error: function( req, status, error ){
                        setMessage( `[${req.status}] ${error} — ${req.responseJSON.data}`, 'error' );
                    }
                });
            }
        });

        $('#thumbnails-delete').on('click', function( event ){
            event.preventDefault();
            if( confirm('Are you sure you want to delete all wordpress thumbnails?') ) {
                $.ajax({
                    url : ajaxurl,
                    type: 'POST',
                    data: {
                        security: nonce,
                        action  : 'umich_dynamic_images',
                        task    : 'image-list',
                    },
                    dataType: 'json',
                    success: function( imageList ){
                        let imageErrors = [];

                        setMessage( imageList.length +' images found.', 'info' );

                        let cIndex = 0;

                        let purgeImageResized = function( retry ){
                            retry = retry || 0;

                            if( cIndex >= imageList.length ) {
                                setMessage( 'Processing Completed.', imageErrors.length ? 'warning' : 'success', true );

                                if( imageErrors.length ) {
                                    thumbnailWrapper.find('.notice').append('<p>Failed Images:</p><ul class="ul-disc"></ul>');

                                    imageErrors.forEach( (image) => {
                                        thumbnailWrapper.find('.notice ul').append(`<li>[${image.ID}] ${image.file}</li>`);
                                    });
                                }
                                return;
                            }

                            let imgNum = cIndex + 1;
                            let image  = imageList[ cIndex ];
                            setMessage( `Processing Image: (${imgNum} / ${imageList.length}) — <strong>${image.file}</strong>`, 'warning' );

                            $.ajax({
                                url : ajaxurl,
                                type: 'POST',
                                data: {
                                    security: nonce,
                                    action  : 'umich_dynamic_images',
                                    task    : 'image-purge-resized',
                                    id      : image.ID
                                },
                                dataType: 'json',
                                success: function( res ){
                                    cIndex++;

                                    if( res.status != 'success' ) {
                                        imageErrors.push( image );
                                    }

                                    purgeImageResized();
                                },
                                error: function( req, status, error ){
                                    console.log([ 'Purge error', req, image ]);
                                    retry++;

                                    if( retry >= 5 ) {
                                        console.log([`Exceeded retry count.`, image]);
                                        imageErrors.push( image );

                                        cIndex++;

                                        purgeImageResized();
                                    }
                                    else {
                                        setTimeout(function(){
                                            purgeImageResized( retry );
                                        }, 1000 );
                                    }
                                }
                            });
                        };

                        purgeImageResized();
                    },
                    error: function( req, status, error ){
                        setMessage( `[${req.status}] ${error} — ${req.responseJSON.data}`, 'error' );
                    }
                });
            }
        });
    });
}(jQuery));
</script>
