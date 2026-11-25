<?php
/**
 * Plugin Name: Uzumba Instant Load
 * Description: Removes the page loading animation site-wide and refreshes WooCommerce prices live after a cached page has loaded.
 * Version: 1.0.0
 * Author: Mohlaloga Boroto
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------------
 * 1. Hide the theme's loading animation (preloader) on every page
 * ------------------------------------------------------------------ */
add_action( 'wp_head', function () {
    echo '<style>
        #preloader, .preloader, [id*="preloader"], [class*="preloader"] { display: none !important; }
        body { overflow: visible !important; }
    </style>';
}, 1 );

/* ------------------------------------------------------------------
 * 2. Tag every product price so the browser can refresh it after load
 * ------------------------------------------------------------------ */
function uzumba_il_wrap_price( $html, $product ) {
    if ( is_admin() || '' === $html ) {
        return $html;
    }
    return '<span class="uz-live-price" data-pid="' . esc_attr( $product->get_id() ) . '">' . $html . '</span>';
}
add_filter( 'woocommerce_get_price_html', 'uzumba_il_wrap_price', 999, 2 );

/* ------------------------------------------------------------------
 * 3. REST endpoint: /wp-json/uzumba/v1/live-prices?ids=1,2,3 (never cached)
 * ------------------------------------------------------------------ */
add_action( 'rest_api_init', function () {
    register_rest_route( 'uzumba/v1', '/live-prices', array(
        'methods'             => 'GET',
        'callback'            => 'uzumba_il_live_prices',
        'permission_callback' => '__return_true',
    ) );
});

function uzumba_il_live_prices( WP_REST_Request $request ) {
    if ( ! function_exists( 'wc_get_product' ) ) {
        return new WP_REST_Response( array(), 200 );
    }

    $ids = array_slice(
        array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) ),
        0,
        100
    );

    // Return the plain price, without the wrapper from step 2.
    remove_filter( 'woocommerce_get_price_html', 'uzumba_il_wrap_price', 999 );

    $out = array();
    foreach ( $ids as $id ) {
        $product = wc_get_product( $id );
        if ( $product && 'publish' === $product->get_status() ) {
            $out[ $id ] = $product->get_price_html();
        }
    }

    $response = new WP_REST_Response( $out );
    $response->header( 'Cache-Control', 'no-store, max-age=0' );
    return $response;
}

/* ------------------------------------------------------------------
 * 4. After the page has loaded, swap in live prices (and refresh every 60s)
 * ------------------------------------------------------------------ */
add_action( 'wp_footer', function () {
    if ( is_admin() ) {
        return;
    }
    ?>
<script>
(function () {
    var url = <?php echo wp_json_encode( rest_url( 'uzumba/v1/live-prices' ) ); ?>;

    function refreshPrices() {
        var els = document.querySelectorAll('.uz-live-price[data-pid]');
        if (!els.length) { return; }

        var ids = [], seen = {};
        els.forEach(function (el) {
            var id = el.getAttribute('data-pid');
            if (!seen[id]) { seen[id] = true; ids.push(id); }
        });

        fetch(url + '?ids=' + ids.join(',') + '&_=' + Date.now(), { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                els.forEach(function (el) {
                    var html = data[el.getAttribute('data-pid')];
                    if (html && el.innerHTML !== html) { el.innerHTML = html; }
                });
            })
            .catch(function () { /* keep the cached price if the request fails */ });
    }

    window.addEventListener('load', refreshPrices);
    setInterval(refreshPrices, 60000);
})();
</script>
    <?php
}, 99 );
