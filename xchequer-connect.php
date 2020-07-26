<?php
/**
 * Plugin Name: Woocommerce to XChequer
 * Description: KC Link intermediate database actions
 * Version: 1.0.0
 * Author: Tex0gen
 * Author URI: https://apexdevs.io
 * Text Domain: xchequer-connect
 *
 * @package Xcheq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Include the main class.
if ( ! class_exists( 'Xcheq' ) ) {
	include_once dirname( __FILE__ ) . '/includes/class.xcheq.php';
}