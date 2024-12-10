Dashboard.
    - Display overview of cache status js and css minified. image optimise.

    cache status
        - Display current cache size and clear cache button.
    JavaScript & CSS Optimization
        - Display javascript and css minified count.
    Image optimisation
        - Display webp and avif converted count like this.
        WebP
            Completed: 0
            Pending: 0
            Failed: 0
        AVIF
            Completed: 0
            Pending: 0
            Failed: 0

        and add two button Optimise now and remove optimised.
    Recent Activities
        In this section i display recent activity like this.
            Plugin activated on 2024-12-08 05:28:15
            Plugin deactivated on 2024-12-08 05:28:09
            Clear all cache on 2024-11-28 12:30:09
            Clear all cache on 2024-11-28 12:29:33
            Clear all cache on 2024-11-28 12:25:51
            Plugin activated on 2024-11-28 12:24:35
            Plugin deactivated on 2024-11-28 12:12:29
            Clear all cache on 2024-11-28 12:10:41
            Clear all cache on 2024-11-28 11:35:13
            Clear all cache on 2024-11-28 11:34:58

File Optimization Settings
    In this tab i add many options like.

    checkbox: Minify JavaScript
    textarea: Exclude specific javascript files.

    checkbox: Minify css.
    textarea: Exclude specific css files.

    checkbox: Combine CSS
    textarea: Exclude css file to combine.

    checkbox: Remove woocommerce css and js from other page
    textarea: Exclude URL to keep woocommerce css and js.
    default value: shop/(.*)
                product/(.*)
                my-account/(.*)
                cart/(.*)
                checkout/(.*)

    checkbox: Minify HTML

    checkbox: Defer Loading JavaScript
    textarea: Exclude specific JavaScript files.

    checkbox: Delay Loading JavaScript
    textarea: Exclude specific Javascript files.

    button: Save Settings.

Preload Settings
    checkbox: Enable Preloading Cache
        This checkbox create static html and gzip file to serve direcly this.
    textarea:Exclude specific url to exclude preloading cache.

    checkbox: Preconnect
    textarea: Add preconnect origins, one per line (eg: https://fonts.gstatic.com)

    checkbox: Prefetch DNS
    textarea: Enter domains for DNS Prefetching, one per line (eg: https:example.com)

    checkbox: Preload Fonts
    textarea: Enter fonts for preloading, one per line (e.g., https://example.com/fonts/font.woff2)
            /your-theme/fonts/font.woff2

    checkbox: Preload CSS
    textarea: Enter CSS for preloading, one per line (e.g., https://example.com/style.css)
            /your-theme/css/style.css

Image Optimization Settings
    checkbox: Lazy Load Images
    number: Enter number you want to exclude first.
    textarea: Exclude specific image urls.
    checkbox: Use SVG placeholders for images that are being lazy-loaded to improve page rendering performance.

    checkbox: Enable Image Conversion
    select: Conversion Format:
        options: WebP, AVIF, Both.
    textarea: Exclude specific images from Conversion

    checkbox: Preload image on Front page.
    textarea: Enter Image url(full/partial) to preload this image in front page
    example: desktop: uploads/2024/11/home-hero-banner-large.webp
            mobile: uploads/2024/11/home-hero-banner-small-1.webp

    checkbox: Preload Feature Images for Post Types
    checkboxes: available post Types.
    textarea: Exclude specific image to preload.
    number: Set max width so it can't load bigger img than it. 0 default.
    textarea: Exclude specific size to preload

Tools
    import export plugin settings functionality