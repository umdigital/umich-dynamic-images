U-M Dynamic ImagesDynamic Images
===================================
[![GitHub release](https://img.shields.io/github/release/umdigital/umich-dynamic-images.svg)](https://github.com/umdigital/umich-dynamic-images/releases/latest)
[![GitHub issues](https://img.shields.io/github/issues/umdigital/umich-dynamic-images.svg)](https://github.com/umdigital/umich-dynamic-images/issues)

Replaces wordpress built-in pre-built image thumbnails with a dymamic system that creates them on the fly.  Greate for larger sites with a large upload set.  Reduces on-disk needs by only storing full res images and thumbnail cache which is purged regularly.

**NOTE: This has only been tested with apache using .htaccess It would likely need adjustments to work in other environments**

## Install
### WP Admin/Dashboard Method
*This requires that your site has write access to the plugins folder.*
1. Download the [latest package](https://github.com/umdigital/umich-dynamic-images/releases/latest) *(e.g. umich-dynamic-images-x.x.x.zip)*
2. Go to WP Admin/Dashboard -> Plugins -> Add New -> Upload Plugin
3. Select the downloaded zip file and Upload
4. Activate Plugin
5. Configure plugin settings (WP Admin/Dashboard -> Settings -> U-M: Dynamic Images
### Manual Method
1. Download the [latest package](https://github.com/umdigital/umich-dynamic-images/releases/latest) *(e.g. umich-dynamic-images-x.x.x.zip)*
2. Extract zip
3. Upload the *umich-dynamic-images* folder to *wp-content/plugins/* folder in your site
4. Activate Plugin
5. Configure plugin settings (WP Admin/Dashboard -> Settings -> U-M: Dynamic Images

## Custom Integrations
### Filters
**umich-dynamic-images_url-rewrites**

Override plugin custom rewrite rules locating uploaded assets to be handled by the plugin.
```
add_filter( 'umich-dynamic-images_url-rewrites', function( $rules ){
    // your code here to modify rules

    return $rules;
});
```

**umich-dynamic-images_url-rewrite-conditions**

Override plugin custom rewrite conditions locating uploaded assets to be handled by the plugin.
```
add_filter( 'umich-dynamic-images_url-rewrites', function( $rules ){
    // your code here to modify rules

    return $rules;
});
```

**umich-dynamic-images_url-patterns**

Request regex rules to identify uploaded image thumbnail requests.  Note that these regex rules require "Named Capture Groups".
- path
- file
- suffix
- extension
- width
- height
- crop
```
add_filter( 'umich-dynamic-images_url-rewrites', function( $rules ){
    // your code here to modify rules

    return $rules;
});
```

**umich-dynamic-images_extensions**

Alter the list of extensions that are to be managed by this plugin rather than wordpress.
```
add_filter('umich-dynamic-images_extensions', function( $extensions ){
    // your code here to modify extensions list

    return $extensions;
});
```


### Actions
**umich_dynamic_images_admin_settings_save**

Called before dynamic images settings are saved
```
$settings: the settings stored in the options table
$hasErrors: boolean if the form had errors
add_action( 'umich_dynamic_images_admin_settings_save', function( $settings, $hasErrors ){
    // do something
});
```
