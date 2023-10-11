<?php
/**
 * Migrate template - Main
 */

?>

<!--<div class="flex border-b justify-between mb-4 border-grayCust-100">-->
<div class="flex border-b justify-between shadow-md rounded-tl-lg rounded-tr-lg border-grayCust-100">
    <div class="flex items-center nav-items">
        <?php foreach ( InstaWP_Setting::get_plugin_nav_items() as $item_key => $item ) {
            $icon  = isset( $item['icon'] ) ? $item['icon'] : '';
            $label = isset( $item['label'] ) ? $item['label'] : '';

            printf( '<div id="%s" class="nav-item"><a class="flex items-center px-4 py-5 border-b-2 border-transparent hover:text-primary-900 text-sm font-medium">%s<span>%s</span></a></div>', $item_key, $icon, esc_html( $label ) );
        } ?>
    </div>
    <div class="flex items-center text-sm font-medium">
		<?php if ( empty( InstaWP_Setting::get_api_key() ) ) : ?>
            <div class="flex items-center text-grayCust-1300"><?php echo esc_html__( 'Please connect InstaWP account', 'instawp-connect' ); ?></div>
            <button type="button" class="instawp-button-connect px-4 rounded-lg py-2 border border-primary-900 text-primary-900 text-sm font-medium ml-3 mr-3">
                <span><?php echo esc_html__( 'Connect', 'instawp-connect' ); ?></span>
            </button>
		<?php else: ?>
            <span class="w-1 h-1 bg-primary-700 rounded-full mr-2"></span>
            <span class="text-primary-700 mr-4"><?php echo esc_html__( 'Your account is connected', 'instawp-connect' ); ?></span>
		<?php endif; ?>
    </div>
</div>