=== CodeDragon SmartCache ===
Contributors: codedragon1
Tags: smart, cache, caching, compress, speed, optimize, minify, combine, gzip, performance, fast, speedup, faster, clear, reset, compression, white lists, seo, nginx, varnish, aws, amazon web services, s3, cloudfront, apache, loadtime, compression
Requires at least: 3.0.1
Tested up to: 4.9.7
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

CodeDragon SmartCache is the first truly intelligent site performance and caching optimization facility for Wordpress.  It offers super-fast, adaptive minification, GZIP compression, browser caching, database performance improvements, and more...

== Description ==
One of the most important aspects of owning a website is having it rank well with search engines.  After all, no one creates a site intending for it to be buried on page 20 of a Google search.

CodeDragon SmartCache was designed as a powerful performance and caching system for Wordpress websites yet easy enough for the non-technical site owner to understand.  Employing several "layered" capabilities such as minifying, combining, page and browser caching and CDN support, SmartCache is able to:

1. Condense served resources to improve network bandwidth
2. Improve user experience by reducing the time it takes to offer your site content
3. Focus on the key aspects that search indexing sites like Google regard when ranking the site

After installing SmartCache it will automatically activate the most optimal settings for your site.  If you would like to further customize these settings, this introduction will walk you through some of the key steps.  A more detailed instruction is available in our [Startup Document](https://www.codedragon.ca/documentation/start-up).  Ultimately, we have built SmartCache so that getting up and running does not require making manual changes to the wp-config.php, .htaccess, or other site files.

= Minifying =

At the lowest level, SmartCache will minify Javascript, CSS stylesheets, fonts, and HTML content.  As well, it can automatically minimizes render-blocking files, and remove query strings.

= Combining =

By combining files, SmartCache helps to reduce the number of requests for files from the server, thus shortening the amount of time required to load a page.

= Page Caching =

Page caching goes further by implementing GZip Compression, preparing a separate HTTPS cache and serving a "flattened" HTML version of your site's pages.  With GZIp Compression and static HTML content pages are provided with less processing from the server.

= Browser Caching =

At this point SmartCache instructs your server with the preferred expiry periods for several file types, so client browsers can comfortably reuse cached files until they expire.

= SmartCache Features =

* Plugin is ready to go, with the most optimal setup, after activation
* Easy to configure without using techical jargon or confusing settings
* Minify Javascript and CSS stylesheet files
* Control whether logged-in users see minified content
* Removal of query (or GET) strings from static requests
* Limit removal of only 'ver=nnn' query strings
* Defer loading of render-blocking Javascript script files
* Asynchronous loading of Javascript script files
* Asynchronous loading of CSS stylesheets
* Combine script and stylesheet files
* Control whether scripts and stylesheets are combined for logged-in users
* Full page caching
* Turn page caching on/off for logged-in users
* GZip compression
* Enable/disable frontpage caching
* Provide a separate cache for mobile devices
* Browser caching
* Performance test links to GTMetrix, Pingdom and Pagespeed
* Performance reports
* All this for free!

SmartCache Premium adds the following:

* Minify HTML content
* Serve static versions of HTML pages
* Include WordPress core files in minification
* Use Google(R) Closure as the minifier
* Prevent loading of jQuery Migrate
* Script and Stylesheet File Tuning. Control the minification of each file separetely
* Specify which script files to defer and/or load asynchronously
* Specify which stylesheets to load asynchronously
* Combine font requests in stylesheets
* @import expansion in stylesheets
* Select which page or pages are not to be cached
* Select which post types are not to be cached
* Clear cache when specific post types are saved
* Specify which cookies, that if set in the browser, will cause page to not be cached
* Page caching for HTTPS pages
* Select which user-agents will not receive cached files
* Control the Last-Modified Header
* Control Javascript/XML file Expiry Max Age
* Control CSS stylesheet Expiry Max Age
* Control JPEG, GIF, and PNG image file Expiry Max Age
* Control PDF file Expiry Max Age
* Control the Vary: Accept Encoding Header
* Control the Entity Tag (ETag) Header
* Amazon CloudFront CDN support
* Determine which theme files to include in CDN fileset
* Determine which minified Javascript/CSS files to include in in CDN fileset
* Specify files to exclude from the CDN fileset
* Clear revision records
* Clear transient data
* Clear auto-drafts
* Clear trashed posts
* Clear SPAM and trashed comments
* Clear OPCache and Varnish cache
* Woocommerce compatibility
* Developer friendly with hooks and filters
* Export and import plugin settings
* Customize the location of the cache folder
* Activate the plugin's debug mode and see what is processed on each page
* Available addons
* Premium support
* Send debug info from a specific site URL with your support tickets
* Choose what post-deactivation tasks are performed: clear caches and/or delete settings
* Participate in our Premium Community forum

== Installation ==

We've made getting started with SmartCache simple.  Once installed and activated, the optimal settings are enabled by default.  There are a couple of ways to install SmartCache:

= From within your site =

1. Go to the 'Plugins' menu in Wordpress
2. Click on 'Add New'
3. Enter 'SmartCache' in the search box
4. Install and activate the plugin

= Manually =

1. Download SmartCache or purchase SmartCache Premium at [www.codedragon.ca](https://www.codedragon.ca/products/wordpress-plugins/smartcache/)
2. Upload `smart-cache` to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to the SmartCache admin menu to customize your setup

= NGINX Notes =

After activation, SmartCache will create a configuration file called 'smartcacheopt.conf' in the site's document root (folder where wp-config.php is located).  This file contains several optimizations to help NGINX serve your site faster.  This configuration fille will need to be linked to the site's main system file, normally found in the /etc/nginx/sites-available folder.  The following command will need to be added to this system file:

    include /var/www/examplesite.com/htdocs/smartcacheopt.conf;

= Uninstallation =

1. Go to the 'Tools' tab in SmartCache and choose whether to clear the caches and/or delete settings when the plugin is deactivated
2. Deactivate the plugin on the 'Plugins' page in Wordpress
3. Delete the plugin
4. Optionally remove the optimizations between the # BEGIN SmartCache Optimization and # END SmartCache Optimization tags in the .htaccess file
5. Delete the /wp-content/uploads/sc_vault and /wp-content/uploads/sc_vault_mobile folders

== Frequently Asked Questions ==

1. How do I modify or delete the SmartCache rules from the .htaccess file?
    - It is not recommended to alter the .htaccess file if you are not familiar with its construction.  However, if you need to modify it while the plugin is active, add your rules outside the # BEGIN SmartCache Optimization and # END SmartCache Optimization tags.  The rules between those tags are automatically set by the plugin.
    - Once the plugin has been deactivated, you can remove the contents of the .htaccess file between the SmartCache Optimization tags.

2. Why do pages seem to load slower after I make changes to the plugin?
    - Each time settings are saved, SmartCache clears the cache to make way for new files.  This activity takes time to process.  If you want to speed up the process of rebuilding the cached resources, select "Scan Site" from the "SmartCache" admin toolbar menu.

3. Why do pages seem to load slower after I save a post?
    - If you have the "Clear Caches When Saving Post Types" option in the Tools tab set to the same post type of the post you are saving, SmartCache will clear the caches.  While this option will ensure the cache is refreshed as posts are updated, SmartCache will have to rebuild the cache.

4. I don't see the sc_vault folder in /wp-content/uploads or I don't see any cached content within that folder.
    - Depending on which settings group you have enabled, different cache folders will be created.  For desktop access, the sc_vault folder will be created.  The sc_vault_mobile folder is generated the first time a mobile user visits the site and the "Mobile Cache" option is set on the Browser Caching tab.  Under each will be a folder for each domain of the site (the main one for standard Wordpress, and one for each of the Wordpress Mulitsite domains).  Under these domain folders there will be one or more of the following: a sc_remote folder for any remotely acquired content, a sc_combine folder for combined files, and a sc_static folder for each statically-generated HTML file.  If none of these folders appear, check the folder permissions of the /wp-content/uploads folder.  A good article on setting folder permissions is [https://codex.wordpress.org/Changing_File_Permissions] https://codex.wordpress.org/Changing_File_Permissions.

5. How do I know files are being minified or combined?
    - There are two ways to check:
        1. Go to any URL that is not excluded by either the "Do Not Cache Specific Pages", "Do Not Cache Specific Post Types", "Do Not Cache Front Page?", or "Disable Caching Where Cookies Present?" settings.  Right-click anywhere on the page and select "Inspect".  Reload the page.  Click on the "Network" tab in the browser Inspector and choose either CSS or Javascript.  If you see a list of files ending in ".css.php" or ".js.php" those files are minified.  If you see files that look like "67507b4820af342b1252a700bc38bca4-1.css.php" those files are combined.

        2. Go to the Tools tab and enable the "Enable debug mode?" option.  Place the shortcode, [sc-show-debug], in any page editor content, or
            echo do_shortcode('[sc-show-debug]');
        in a PHP theme template file (I like to add it to the footer.php file in the theme), to display SmartCache debug information to logged in administrators.  This debug log will describe what the plugin is doing on the page you are viewing.

6. Why doesn't the plugin cache files when I am logged into Wordpress?
    - Normally, administrative pages are not cached as these pages may contain dynamic content.  If you want to view frontend pages as the public while logged in, enable the "Enable Minification for Logged-in Users?" and/or "Enable Combination for Logged-in Users?" options and select your user role from the user role list.

7. How do I serve uncached content to specific mobile devices?
    - Go to the Browser Caching tab and select the mobile device(s) from the "Do Not Cache Mobile Devices" list.

8. How do I serve uncached content to specific browsers?
    - Go to the Browser Caching tab and select the mobile device(s) from the "Do Not Cache User Agents" list.

9. My Woocommerce cart page is not working
    - Go to the Page Caching tab and select the cart and checkout pages from the "Do Not Cache Specific Pages" list.  This will disable caching on those pages.
    - If your visitors are experiencing caching problems on other dynamically created Woocommerce pages, select those pages from the same list.

10. My frontpage is not displaying properly
    - Go to the Page Caching tab and enable the "Do Not Cache Front Page?" setting.

11. One of my plugins are not performing properly since setting up SmartCache, or I am getting a Javascript error in the browser Inspector
    - If you have installed SmartCache Premium, go to the Minify tab and go down to the Javascript File Tuning area.  Start entering the subject script file name in the "Search for Files" box.  When you see it appear, deselect the corresponding Minify checkbox.

12. A specific stylesheet is not displaying correctly since setting up SmartCache
    - If you have installed SmartCache Premium, go to the Minify tab and go down to the Stylesheet File Tuning area.  Start entering the subject stylesheet file name in the "Search for Files" box.  When you see it appear, deselect the corresponding Minify checkbox.

13. I am seeing a "It looks like your .htaccess file is not writable" error
    - SmartCache is trying to automatically update your site's .htaccess file after you made some changes.  If you are able locate the .htaccess file (normally in your site's root folder -- /public/domain, /var/www/domain/htdocs, etc.) and set its permissions to 666.  Resave the settings.  Reset the .htaccess file permissions back to 644 or 444.

14. Does the system report that is submitted with support tickets contain personal data?
    - No. We only need technical information.  Nothing sensitive is transmitted.

= Troubleshooting tips =

1. Check the wp-content/uploads folder to see if it is writable.
2. Look in the wp-contents/uploads folder to see if there are sc_vault, sc_vault_mobile, sc_vault/http..., sc_vault/http.../sc_remote, sc_vault/http.../sc_combine, and/or sc_vault/http.../sc_static folders.
3. Check the site and server error logs.
4. If your site is on an Apache server check that mod_mime, mod_headers and mod_expires modules are installed.  You may need to ask your server administrator if you are unsure.
5. If your site is on a NGINX server, make sure the ./smartcacheopt.conf file is included in the site's server config file (typically located in /etc/nginx/sites-available/).

== Screenshots ==

1. Minify files easily
2. Control which device or browser is served cached files
3. Finely tune the minification of each script and stylesheet file (Premium version)
4. Scan your entire site with one call
5. With the GTMetrix addon (Premium version), get performance reports from within your site
6. Send support tickets using the plugin

== Changelog ==

= 1.1.8 =
- Corrected the value check in the Smart_Cache_remove_update_notification() method that was preventing some 3rd-party plugins from updating properly
- Corrected the presentation order of dashboard news items

= 1.1.7 =
* Fixed
- Temporary combined file cleanup routine

= 1.1.6 =
* Fixed
- AJAX interface conflict
- Problem where combined files were being created for each unique site URL rather than distinct set of source files.  This generated a large set of duplicate files, one each associated with a URL.  Instead combined filenames are calculated from the hash of the filenames being combined.

= 1.1.5 =
* Fixed
- Admin Javascript error that prevents automatic scanning

= 1.1.4 =
* Changed
- Added a mechanism to run the site pre-scan upon activation
- Removed automatic Javascript deferment-by-attribute from core as it was causing some theme scripts to load improperly.  Deferred placement of the scripts is still performed.

= 1.1.2 =
* Changed
- Updated the GZip Compression section in the smartcacheopt.conf generator to handle more mime types and increase the compression level
- Tested plugin for compatibility with Wordpress v 4.9.6
- Minor typo corrections in admin module

= 1.1.0 =
* Initial version

== Upgrade Notice ==
