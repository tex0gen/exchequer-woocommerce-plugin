<?php
/**
* Xcheq Class
*/
define("TESTPREPEND", "tst-");

class Xcheq
{
  private $debug = true;

  function __construct() {
    // Add all the actions, hooks, filters, etc
    register_deactivation_hook(__FILE__, array($this, 'xcheq_remove_scheduled'));

    $this->xcheq_activation_hook();

    add_action( 'admin_notices', array( $this, 'xcheq_do_test_notice') );

    add_action('xcheq_stock_check', array( $this, 'xcheq_stock_check') );
    add_action('xcheq_price_syncronisation', array( $this, 'xcheq_price_sync') );
    add_action('xcheq_order_status_check', array( $this, 'xcheq_order_status_check') );
    add_action('xcheq_fallback_for_missing_ids', array( $this, 'xcheq_fallback_for_missing_ids') );
    
    add_action( 'woocommerce_thankyou', array( $this, 'can_add_order_to_db' ), 10, 1 );

    // TODO: Test this hook
    // add_action( 'wplister_after_create_order_with_nonexisting_items', array( $this, 'xcheq_add_order_to_db' ) );

    add_action( 'admin_menu', array( $this, 'xcheq_admin_menu' ) );
    add_action( 'admin_init', array( $this, 'xcheq_register_settings' ) );
  }

  function xcheq_activation_hook() {
    if (! wp_next_scheduled ( 'xcheq_stock_check' )) {
      wp_schedule_event(time(), 'daily', 'xcheq_stock_check');
    }

    if (! wp_next_scheduled ( 'xcheq_price_syncronisation' )) {
      wp_schedule_event(time(), 'daily', 'xcheq_price_syncronisation');
    }

    if (! wp_next_scheduled ( 'xcheq_order_status_check' )) {
      wp_schedule_event(time(), 'hourly', 'xcheq_order_status_check');
    }

    if (! wp_next_scheduled ( 'xcheq_fallback_for_missing_ids' )) {
      wp_schedule_event(time(), 'hourly', 'xcheq_fallback_for_missing_ids');
    }
  }

  function xcheq_remove_scheduled() {
    wp_clear_scheduled_hook('xcheq_stock_check');
    wp_clear_scheduled_hook('xcheq_price_syncronisation');
    wp_clear_scheduled_hook('xcheq_order_status_check');
    wp_clear_scheduled_hook('xcheq_fallback_for_missing_ids');
  }

  function xcheq_admin_menu() {
    add_options_page( 'XChequer', 'XChequer', 'manage_options', 'xchequer-connect', array( $this, 'xcheq_admin_page') );
  }

  // Admin Setting Page
  function xcheq_admin_page() {
    ?>
    <div class="wrap">
      <h1>XChequer DB Settings</h1>
      <form method="post" action="options.php">
        <?php settings_fields( 'xcheq-sets' ); ?>
        <?php do_settings_sections( 'xcheq-sets' ); ?>
        <p><?= self::xcheq_check_db_connect(); ?></p>
        <table class="form-table">
          <tr valign="top">
            <th scope="row">DB Username</th>
            <td>
              <input type="text" name="dbusername" value="<?= esc_attr( get_option('dbusername') ); ?>" />
            </td>
          </tr>

          <tr valign="top">
            <th scope="row">DB Password</th>
            <td>
              <input type="password" name="dbpassword" value="<?= esc_attr( get_option('dbpassword') ); ?>" />
            </td>
          </tr>

          <tr valign="top">
            <th scope="row">DB Name</th>
            <td>
              <input type="text" name="dbname" value="<?= esc_attr( get_option('dbname') ); ?>" />
            </td>
          </tr>

          <tr valign="top">
            <th scope="row">DB Host</th>
            <td>
              <input type="text" name="dbhost" value="<?= esc_attr( get_option('dbhost') ); ?>" />
            </td>
          </tr>

          <tr valign="top">
            <th scope="row">Enable Test Mode:<br/><small>This will enable orders to go to a test account and will also not affect the stock data.</small></th>
            <td>
              <input type="checkbox" name="testmode" value="1" <?= (get_option('testmode') === "1") ? 'checked':''; ?> />
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>

      <hr/>

      <table class="form-table">
        <thead>
          <tr>
            <td>
              <h2>Manual Operations</h2>
              <?php
              if ( isset( $_POST['stock_check'] ) ) {
                self::xcheq_stock_check(true);
              }

              if ( isset( $_POST['price_sync'] ) ) {
                self::xcheq_price_sync(true);
              }

              if ( isset( $_POST['order_updates'] ) ) {
                self::xcheq_order_status_check(true);
              }

              if ( isset( $_POST['fallback'] ) ) {
                self::xcheq_fallback_for_missing_ids(true);
              }
              ?>
            </td>
          </tr>
        </thead>
        <tr valign="top">
          <td>
            <form action="" method="post">
              <table>
                <tr>
                  <th scope="row">
                    <p>Use this button to manually check the stock levels and update the associated products.</p>
                  </th>
                  <td>
                    <?php submit_button('Manually Check Stock', 'primary', 'stock_check'); ?>
                  </td>
                </tr>
              </table>
            </form>
          </td>

          <td>
            <form action="" method="post">
              <table>
                <tr>
                  <th scope="row">
                    <p>Use this button to manually sync prices from XChequer to the associated products.</p>
                  </th>
                  <td>
                    <?php submit_button('Manually Sync Prices', 'primary', 'price_sync'); ?>
                  </td>
                </tr>
              </table>
            </form>
          </td>

          <td>
            <form action="" method="post">
              <table>
                <tr>
                  <th scope="row">
                    <p>Use this button to manually check order statuses and update woocommmerce if needed.</p>
                  </th>
                  <td>
                    <?php submit_button('Manually Sync Order Status', 'primary', 'order_updates'); ?>
                  </td>
                </tr>
              </table>
            </form>
          </td>

          <td>
            <form action="" method="post">
              <table>
                <tr>
                  <th scope="row">
                    <p>Use this button to check the database for missing processing orders and automatically add them.</p>
                  </th>
                  <td>
                    <?php submit_button('Manually Add Missing Orders', 'primary', 'fallback'); ?>
                  </td>
                </tr>
              </table>
            </form>
          </td>
        </tr>
      </table>

      <!--<h3>Order Debugger</h3>
      <table>
        <tr>
          <td>
            <tr>
              <th scope="row">
                <p>Select the order ID to debug. This should show the data being parsed to xchequer. (Last 5 orders).</p>
              </th>
            </tr>
          </td>
        </tr>
        <tr>
          <td>
            <form action="" method="post">
              <table>
                <tr>
                  <td>
                    <select name="debug">-->
                      <?php
                      // $args = array(
                      //   'post_type' => 'shop_order',
                      //   'numberposts' => 5,
                      //   'post_status'    => 'any'
                      // );
                      // $posts = get_posts( $args );
                      // if ($posts) {
                      //   foreach ($posts as $key => $order) {
                      //     echo '<option value="'.$order->ID.'">'.$order->ID.'</option>';
                      //   }
                      // }
                      ?>
                    <!--</select>
                  </td>
                  <td>
                    <?php //submit_button('Debug Order', 'primary', 'debug_submit'); ?>
                  </td>
                </tr>
              </table>
            </form>
          </td>
        </tr>
      </table>-->

      <?php
      if ( isset( $_POST['debug_submit'] ) ) {
        self::xcheq_debugger($_POST['debug']);
      }
      ?>
    </div>
    <?php

  }

  // Register settings
  function xcheq_register_settings() {
    register_setting( 'xcheq-sets', 'dbusername' );
    register_setting( 'xcheq-sets', 'dbpassword' );
    register_setting( 'xcheq-sets', 'dbname' );
    register_setting( 'xcheq-sets', 'dbhost' );
    register_setting( 'xcheq-sets', 'testmode' );
  }

  // Connect to le database
  private function xcheq_db_connect() {
    $mydb = new wpdb(
      get_option('dbusername'),
      get_option('dbpassword'),
      get_option('dbname'),
      get_option('dbhost')
    );

    if ( $mydb->error ) {
      // TODO: Make this log the actual error from wpdb class
      self::xcheq_logger('ERROR', 'xcheq_db_connect', 'Database Connection Error');
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    if ($ip === '86.63.2.186') {
      $mydb->show_errors();
    }

    return $mydb;
  }

  // Check database connection
  private function xcheq_check_db_connect() {
    $mydb = new wpdb(
      get_option('dbusername'),
      get_option('dbpassword'),
      get_option('dbname'),
      get_option('dbhost')
    );

    if ( !$mydb->error ) {
      echo '<p>Success: A successful connection was made to the intermediate database</p>';
    } else {
      echo '<p>Error: Could not connect to intermediate database</p>';
    }
  }

  // Test Mode Enabled
  private function xcheq_test_mode() {
    if (get_option('testmode') === "1") {
      return true;
    } else {
      return false;
    }
  }

  // Test mode on display message
  function xcheq_do_test_notice() {
    if ( self::xcheq_test_mode() ) {
      ?>
      <div class="notice notice-error">
        <p>XChequer database sync is in <strong>TEST</strong> mode. <a href="<?= get_admin_url(); ?>options-general.php?page=xchequer-connect">View Settings</a></p>
      </div>
      <?php
    }
  }

  // This function syncronises prices for each product
  function xcheq_price_sync( $echo = false ) {
    $mydb = self::xcheq_db_connect();
    $results = $mydb->get_results("SELECT * FROM stock_records");

    if ($results) {
      $counter = 0;
      foreach ($results as $key => $res) {
        $product_code = $res->stock_code;
        $product_price = (float)str_replace('£', '', $res->user_field_2);
        $product_id_by_sku = wc_get_product_id_by_sku( $product_code );

        if ($product_id_by_sku > 0 && $product_price > 0) {
          /*
          I know this looks counter intuitive, but failing to set price to 0 before hand seems
          to cause the variable products to be invisible.
          */
          update_post_meta( $product_id_by_sku, '_price', '0.00' );
          update_post_meta( $product_id_by_sku, '_regular_price', '0.00' );
          update_post_meta( $product_id_by_sku, '_price', $product_price );
          update_post_meta( $product_id_by_sku, '_regular_price', $product_price );
          wc_delete_product_transients( $product_id_by_sku );
          $counter++;
        }
      }

      if ($echo === true) {
        echo 'Updated <strong>'.$counter.'</strong> product prices';
      }
    }
  }

  // order_statuses
  function xcheq_order_status_check( $echo = false ) {
    $mydb = self::xcheq_db_connect();
    $results = $mydb->get_results("SELECT * FROM order_headers_statuses WHERE record_updated = 1 AND order_status = 'Fully Delivered'");

    if ($results) {
      $counter = 0;
      foreach ($results as $key => $res) {
        $status = $res->order_status;
        $order_id = $res->web_order_number;
        // self::xcheq_logger('NOTICE', 'xcheq_stock_check', 'Found Order with Fully Delivered Status. ID: ' . $order_id);
        $order = wc_get_order($order_id);

        if ($order) {
          // self::xcheq_logger('NOTICE', 'xcheq_stock_check', 'Doing Fully Delivered Status. ID: ' . $order_id);
          // $tracking_data = get_post_meta( $order_id, '_wc_shipment_tracking_items', true );
          $update_mark = ( self::xcheq_test_mode() ) ? 1:0;

          $updated = $mydb->update(
            'order_headers_statuses',
            array(
              'record_updated' => $update_mark
            ),
            array( 'web_order_number' => $order_id ),
            array(
              '%s'
            ),
            array( '%d' )
          );

          if ($updated === false) {
            self::xcheq_logger('NOTICE', 'xcheq_stock_check', 'order_headers_statuses row not updated. ID: ' . $order_id);
          }
          $order->update_status( 'completed' );
          $counter++;
        }
        else {
           self::xcheq_logger('NOTICE', 'xcheq_order_status_check', 'Order not found. ID: ' . $order_number);
        }
      }

      if ($echo === true) {
        echo 'Updated <strong>'.$counter.'</strong> order statuses';
      }
    }
  }

  // stock_quantities
  function xcheq_stock_check( $echo = false ) {
    $mydb = self::xcheq_db_connect();

    if (self::xcheq_test_mode() !== true) {
      $results = $mydb->get_results("SELECT * FROM stock_quantities WHERE location_code = 'UK1' AND record_updated = 1");
    } else {
      $results = $mydb->get_results("SELECT * FROM stock_quantities WHERE location_code = 'UK1'");
    }

    if ($results) {
      $counter = 0;
      foreach ($results as $key => $res) {
        $stock_id = $res->stock_quantity_id;
        $product_code = $res->stock_code;
        $remaining_stock = ($res->free_stock_quantity <= 0) ? 0:$res->free_stock_quantity;
        $product_id_by_sku = wc_get_product_id_by_sku( $product_code );

        if ($product_id_by_sku > 0) {
          $manage_stock_option = get_post_meta( $product_id_by_sku, '_manage_stock', true );

          if ( $manage_stock_option !== 'yes' ) {
            update_post_meta($product_id_by_sku, '_manage_stock', 'yes');
          }

          $update_stock = wc_update_product_stock( $product_id_by_sku, $remaining_stock, 'set' );

          // Update database row to mark as complete
          $update_mark = ( self::xcheq_test_mode() ) ? 1:0;

          if (self::xcheq_test_mode() !== true) {
            $updated = $mydb->update(
              'stock_quantities',
              array(
                'record_updated' => $update_mark
              ),
              array( 'stock_quantity_id' => $stock_id ),
              array(
                '%s'
              ),
              array( '%d' )
            );

            if ($updated === false) {
              self::xcheq_logger('NOTICE', 'xcheq_stock_check', 'stock_quantities row not updated. ID: ' . $stock_id);
            } else {
              $counter++;
            }
          } else {
            $counter++;
          }
        }
      }

      if ($echo === true) {
        echo 'Updated <strong>'.$counter.'</strong> product stock levels';
      }
    }
  }

  // Look for missing orders in the db and try to re-add them
  function xcheq_fallback_for_missing_ids( $echo = false ) {

    $mydb = $this->xcheq_db_connect();
    $query = new WC_Order_Query( array(
      'limit' => 200,
      'orderby' => 'date',
      'order' => 'DESC',
      'status' => 'processing',
      'return' => 'ids',
    ) );
    $orders = $query->get_orders();

    if($orders) {
      foreach ($orders as $key => $order_id) {
        $exists = $mydb->get_results("SELECT 1 FROM order_headers WHERE order_number = '$order_id'");
        if (!$exists) {
          if ($echo === true) {
            echo '<p>Order #' . $order_id . ' did not exist</p>';  
          }
          // add the order
          self::xcheq_logger('ERROR', 'xcheq_fallback_for_missing_ids', 'Adding missing order #' . $order_id);
          self::can_add_order_to_db( $order_id );
        }
      } // end for each
    }
  }

  function check_delivery_date($first, $last, $step = '+1 day', $output_format = 'N') {
    $dates = 0;
    $current = strtotime($first);
    $last = strtotime($last);

    while( $current <= $last ) {
      if (date($output_format, $current) === '7' ) {
        $dates++;
      } else if (date($output_format, $current) === '6') {
        $dates++;
      }

      $current = strtotime($step, $current);
    }

    return $dates;
  }

  function is_between_times( $start = null, $end = null ) {
    $current_time = date('H:i');
    $date1 = DateTime::createFromFormat('H:i', $current_time);
    $date2 = DateTime::createFromFormat('H:i', $start);
    $date3 = DateTime::createFromFormat('H:i', $end);

    if ($date1 > $date2 && $date1 < $date3) {
      return true;
    } else {
      return false;
    }
  }

  public function can_add_order_to_db( $order_id ) {
    if (!$order_id) {
      return;
    }

    $order = new WC_Order( $order_id );

    if ($this->isDebugOn()) {
      $is_paid = $order->is_paid()?'true':'false';
      $needs_processing = $order->needs_processing()?'true':'false';

      $message = "\n"
        ."\tOrder ID: {$order->get_id()}\n"
        ."\tOrder is paid: $is_paid\n"
        ."\tOrder needs processing: $needs_processing\n"
        ."\tOrder type: {$order->get_meta('order_type', true)}\n"
        ."\tOrder status: {$order->get_status()}\n"
        ."\tOrder payment method: {$order->get_payment_method()}\n";

      $this->xcheq_logger('NOTICE', 'can_add_order_to_db', $message);
    }

    if ($order->is_paid()) {
      $order_number = ($this->xcheq_test_mode()) ? TESTPREPEND . $order->get_id() : $order->get_id();
      $mydb = $this->xcheq_db_connect();
      $exists = $mydb->get_results("SELECT 1 FROM order_headers WHERE order_number = '$order_number'");

      if (!$exists) {
        $this->xcheq_add_order_to_db( $order );
      } else if ($this->isDebugOn()) {
        $this->xcheq_logger('WARNING', 'can_add_order_to_db', "Database already has order number: $order_number");
      }
    } else {
      $this->xcheq_logger('WARNING', 'can_add_order_to_db', "Order number $order_number is not paid");
    }
  }

  public function xcheq_add_order_to_db(WC_Order $order) {
    $order_number = (self::xcheq_test_mode()) ? TESTPREPEND . $order->get_id():$order->get_id();
    $record_downloaded = 0;

    $delID = array(
      '3'  => 'RMTR48',       // Free shipping (Within 5 working days)                             |  United Kingdom (UK)
      '11' => 'RMTR48',       // Free shipping (Within 5 working days)                             |  UK Limited
      '4'  => 'RMTR24',       // Next Working Day - Signed For (Orders placed Mon-Fri before 11am) |  United Kingdom (UK)
      '6'  => 'RMTR24S',      // Next Day (Tracked 24 Hour Signed For)                             |  United Kingdom (UK)
      '7'  => 'RMSD1AM',      // Special Delivery (Next Day Before 1pm)                            |  United Kingdom (UK)
      '8'  => 'RMTR48',       // Standard                                                          |  United Kingdom (UK)
      '9'  => 'LOCAL PICKUP', // Local pickup                                                      |  United Kingdom (UK)
      '21' => 'RMTR48',       // Standard                                                          |  UK Limited
      '5'  => 'RMTR48',       // Standard                                                          |  Channel Islands
      '12' => 'RMTR48'        // Free shipping (Within 5 working days)                             |  Channel Islands
    );
    // 12 = Free shipping (Within 5 working days) | Channel Islands

    if ( $order->get_items( 'shipping' ) ) {
      foreach( $order->get_items( 'shipping' ) as $item_id => $shipping_item_obj ) {
        $shipping_data = $shipping_item_obj->get_data();
        $shipping_data_method_title = $shipping_data['instance_id'];
        $delivery_code = ($delID[$shipping_data_method_title]) ? $delID[$shipping_data_method_title]:'PICKUP';

        self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'Delivery Code '.$delivery_code);

        if ($shipping_data_method_title == 4) {
          self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'Order time: ' .date('H:i a'));
          self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'Shipping data method title = 4');

          if ( self::is_between_times( '11:00', '24:00' ) ) {
            $plus_days = date('d-m-Y', strtotime(date('d-m-Y') . ' +2 days'));
            $add_days = self::check_delivery_date(date('d-m-Y'), $plus_days);
            $num_delivery_days = 2;
            $total_delivery_days = $num_delivery_days + $add_days;
            $order_date = date('Y-m-d', strtotime($order->order_date . " +".$total_delivery_days." days"));
            self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'Order date: meth 4: '.$order_date);
          } else {
            $plus_days = date('d-m-Y', strtotime(date('d-m-Y') . ' +1 days'));
            $add_days = self::check_delivery_date(date('d-m-Y'), $plus_days);
            $num_delivery_days = 1;
            $total_delivery_days = $add_days + $num_delivery_days;
            $order_date = date('Y-m-d', strtotime($order->order_date . " +".$total_delivery_days." days"));
            // echo 'Current time is not between 9:00 am and 8:00 pm.<br>';
            self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'Order date: bet meth 4: '.$order_date);
          }
        } else if ($shipping_data_method_title == 6) {
          self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'Shipping data method title = 6');
          if ( self::is_between_times( '11:00', '24:00' ) ) {
            $plus_days = date('d-m-Y', strtotime(date('d-m-Y') . ' +2 days'));
            $add_days = self::check_delivery_date(date('d-m-Y'), $plus_days);
            $num_delivery_days = 2;
            $total_delivery_days = $num_delivery_days + $add_days;
            $order_date = date('Y-m-d', strtotime($order->order_date . " +".$total_delivery_days." days"));
            self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'Order date: meth 6: '.$order_date);
          } else {
            $plus_days = date('d-m-Y', strtotime(date('d-m-Y') . ' +1 days'));
            $add_days = self::check_delivery_date(date('d-m-Y'), $plus_days);
            $num_delivery_days = 1;
            $total_delivery_days = $add_days + $num_delivery_days;
            $order_date = date('Y-m-d', strtotime($order->order_date . " +".$total_delivery_days." days"));
            // echo 'Current time is not between 9:00 am and 8:00 pm.<br>';
            self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'Order date: meth 6: '.$order_date);
          }
        } else if ($shipping_data_method_title == 7) {
          self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'Shipping data method title = 7');
          // if past 11am add another day
          if ( self::is_between_times( '13:00', '24:00' ) ) {
            $plus_days = date('d-m-Y', strtotime(date('d-m-Y') . ' +2 days'));
            $add_days = self::check_delivery_date(date('d-m-Y'), $plus_days);
            $num_delivery_days = 2;
            $total_delivery_days = $num_delivery_days + $add_days;
            $order_date = date('Y-m-d', strtotime(date('d-m-Y') . " +".$total_delivery_days." days"));
            self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'Order date: '.$order_date);
          } else {
            $plus_days = date('d-m-Y', strtotime(date('d-m-Y') . ' +1 days'));
            $add_days = self::check_delivery_date(date('d-m-Y'), $plus_days);
            $num_delivery_days = 1;
            $total_delivery_days = $add_days + $num_delivery_days;
            $order_date = date('Y-m-d', strtotime(date('d-m-Y') . " +".$total_delivery_days." days"));
            // echo 'Current time is not between 9:00 am and 8:00 pm.<br>';
            self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'Order date: '.$order_date);
          }
        } else {
          // if past 11am add another day
          if ( self::is_between_times( '11:00', '24:00' ) ) {
            $plus_days = date('d-m-Y', strtotime(date('d-m-Y') . ' +6 days'));
            $add_days = self::check_delivery_date(date('d-m-Y'), $plus_days);
            $num_delivery_days = 6;
            $total_delivery_days = $num_delivery_days + $add_days;
            $order_date = date('Y-m-d', strtotime(date('d-m-Y') . " +".$total_delivery_days." days"));
            self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'Order date: '.$order_date);
          } else {
            $plus_days = date('d-m-Y', strtotime(date('d-m-Y') . ' +5 days'));
            $add_days = self::check_delivery_date(date('d-m-Y'), $plus_days);
            $num_delivery_days = 5;
            $total_delivery_days = $add_days + $num_delivery_days;
            $order_date = date('Y-m-d', strtotime(date('d-m-Y') . " +".$total_delivery_days." days"));
            // echo 'Current time is not between 9:00 am and 8:00 pm.<br>';
            self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'Order date: '.$order_date);
          }
        }
      }
    } else {
      self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'There are no shipping methods on this order: ' . $order->get_id() );
      /*
      need to manually set
      $order_date       // order_date
      $shipping_method  // shipping_method
      $delivery_code    // user_field_1
      */

      $shipping_method = 'Free shipping (Within 5 working days)';
      $delivery_code   = 'RMTR48';
      
      // if past 11am add another day
      if ( self::is_between_times( '11:00', '24:00' ) ) {
        $plus_days = date('d-m-Y', strtotime(date('d-m-Y') . ' +6 days'));
        $add_days = self::check_delivery_date(date('d-m-Y'), $plus_days);
        $num_delivery_days = 6;
        $total_delivery_days = $num_delivery_days + $add_days;
        $order_date = date('Y-m-d', strtotime(date('d-m-Y') . " +".$total_delivery_days." days"));
        self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'Order date: '.$order_date);
      } else {
        $plus_days = date('d-m-Y', strtotime(date('d-m-Y') . ' +5 days'));
        $add_days = self::check_delivery_date(date('d-m-Y'), $plus_days);
        $num_delivery_days = 5;
        $total_delivery_days = $add_days + $num_delivery_days;
        $order_date = date('Y-m-d', strtotime(date('d-m-Y') . " +".$total_delivery_days." days"));
        // echo 'Current time is not between 9:00 am and 8:00 pm.<br>';
        self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'Order date: '.$order_date);
      }
    }

    // Get order type (ebay/online/telephone) - set in functions.php
    $order_type = $order->get_meta('order_type', true);

    // This is set just in case orders DONT have an order type.
    // It mostly seems like the odd telephone order misses it for some reason.
    $attr_user = 'W073';

    if ($order_type) {
      if ($order_type == "online") {
        $attr_user = 'W073';
      } else if ( $order_type == 'telephone') {
        $attr_user = 'F001';
      } else {
        $attr_user = 'E071';
      }
    }

    // if (!$attr_user) {
    //   self::xcheq_logger('FATAL', 'xcheq_add_order_to_db', 'Failed to get an account number to parse data to. Order no:'.$order_id);
    //   return; // Kill the function or it may add untrackable data.
    // }

    $sales_ledger_account_code = $attr_user;
    $customer_reference_number = $attr_user;

    // $order_date = date('Y-m-d', strtotime($order->order_date));

    $order_status = $order->status;
    $currency_code = $order->currency;
    $customer_id = (self::xcheq_test_mode()) ? 'z904':$attr_user; // Z904 is the test account
    $shipping_method = $order->shipping_method;
    $payment_method = $order->get_payment_method;
    $payment_with_order = 0;

    // Shipping Details
    $delivery_name = $order->shipping_first_name . ' ' . $order->shipping_last_name;
    $delivery_company   = $order->shipping_company;
    $delivery_address_1 = $order->shipping_address_1;
    $delivery_address_2 = $order->shipping_address_2;
    $delivery_address_3 = $order->shipping_city;
    $delivery_address_4 = $order->shipping_state;
    $delivery_address_5 = $order->shipping_country;
    $delivery_postcode = $order->shipping_postcode;

    // Billing Details
    $invoice_name = $order->shipping_first_name . ' ' . $order->shipping_last_name;
    $invoice_address_1 = $order->billing_address_1;
    $invoice_address_2 = $order->billing_address_2;
    $invoice_address_3 = $order->billing_city;
    $invoice_address_4 = $order->billing_state;
    $invoice_address_5 = $order->billing_country;
    $invoice_postcode = $order->billing_postcode;
    $email_address = $order->billing_email;
    $delivery_telephone_number = $order->billing_phone;

    $comments = $order->customer_message;
    $order_gross_total = $order->total;

    $data = array(
      'order_header_id' => '',
      'order_number' => $order_number,
      'order_date' => $order_date,
      'invoice_name' => $invoice_name,
      'invoice_address_1' => $invoice_address_1,
      'invoice_address_2' => $invoice_address_2,
      'invoice_address_3' => "",
      'invoice_address_4' => $invoice_address_3,
      'invoice_address_5' => $invoice_address_4,
      'invoice_postcode' => $invoice_postcode,
      'delivery_name' => $delivery_name,
      'delivery_address_1' => $delivery_name,
      'delivery_address_2' => $delivery_company,
      'delivery_address_3' => $delivery_address_1,
      'delivery_address_4' => $delivery_address_2,
      'delivery_address_5' => $delivery_address_3, // should be town (county has been dropped)
      'delivery_postcode' => $delivery_postcode,
      'delivery_telephone_number' => $delivery_telephone_number,
      'delivery_fax_number' => '',
      'email_address' => $email_address,
      'sales_ledger_account_code' => $customer_id,
      'comments' => $comments,
      'customer_reference_number' => $customer_id,
      'shipping_method' => $shipping_method,
      'payment_with_order' => $payment_with_order,
      'payment_method' => $payment_method,
      'location_code' => '',
      'currency_code' => $currency_code,
      'user_field_1' => $delivery_code,
      'user_field_9' => $email_address,
      'user_field_10' => $delivery_telephone_number,
      'exchange_rate' => '',
      'order_gross_total' => $order_gross_total,
      'record_downloaded' => $record_downloaded
    );

    $data_types = array(
      '%d',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%d',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%s',
      '%f',
      '%f',
      '%d'
    );

    $order_header_dump = "\n"
    ."\t order_number: {$order_number}\n"
    ."\t order_date: {$order_date}\n"
    ."\t invoice_name: {$invoice_name}\n"
    ."\t invoice_address_1: {$invoice_address_1}\n"
    ."\t invoice_address_2: {$invoice_address_2}\n"
    ."\t invoice_address_4: {$invoice_address_3}\n"
    ."\t invoice_address_5: {$invoice_address_4}\n"
    ."\t invoice_postcode: {$invoice_postcode}\n"
    ."\t delivery_name: {$delivery_name}\n"
    ."\t delivery_address_1: {$delivery_name}\n"
    ."\t delivery_address_2: {$delivery_company}\n"
    ."\t delivery_address_3: {$delivery_address_1}\n"
    ."\t delivery_address_4: {$delivery_address_2}\n"
    ."\t delivery_address_5: {$delivery_address_3}\n"
    ."\t delivery_postcode: {$delivery_postcode}\n"
    ."\t delivery_telephone_number: {$delivery_telephone_number}\n"
    ."\t email_address: {$email_address}\n"
    ."\t sales_ledger_account_code: {$customer_id}\n"
    ."\t comments: {$comments}\n"
    ."\t customer_reference_number: {$customer_id}\n"
    ."\t shipping_method: {$shipping_method}\n"
    ."\t payment_with_order: {$payment_with_order}\n"
    ."\t payment_method: {$payment_method}\n"
    ."\t currency_code: {$currency_code}\n"
    ."\t user_field_1: {$delivery_code}\n"
    ."\t user_field_9: {$email_address}\n"
    ."\t user_field_10: {$delivery_telephone_number}\n"
    ."\t order_gross_total: {$order_gross_total}\n"
    ."\t record_downloaded: {$record_downloaded}\n";

    $this->xcheq_logger('NOTICE', 'xcheq_add_order_to_db', $order_header_dump);

    $mydb = self::xcheq_db_connect();

    $mydb->insert(
      'order_headers',
      $data,
      $data_types
    );

    /*if ( $mydb->last_error ) {
      self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'Last Error: ' . $mydb->last_error);
    }*/

    if ( $mydb->error ) {
      self::xcheq_logger('FATAL', 'xcheq_add_order_to_db', 'Failed to parse order data into database. ' . $mydb->error);
    }

    // Proceed to add the order items
    self::xcheq_add_order_details_to_db($order);
  }

  // order_details
  private function xcheq_add_order_details_to_db(WC_Order $order) {
    $order_number = ( self::xcheq_test_mode() ) ? TESTPREPEND . $order->get_id() : $order->get_id();

    $mydb = self::xcheq_db_connect();
    $results = $mydb->get_results("SELECT order_header_id FROM order_headers WHERE order_number = '$order_number'");

    if ($results) {
      foreach ($results as $key => $res) {
        $order_header_id = $res->order_header_id;
      }
    } else {
      self::xcheq_logger('FATAL', 'xcheq_add_order_details_to_db', 'No database results for ' . $order_number);
    }

    if ($order) {
      $items = $order->get_items();
      $coupons = self::xcheq_get_coupons( $order );
      $overall_discount_applied = false;
      $coupon_inserted = false;

      if ($items) {
        foreach($items as $item) {
          $product = $item->get_product();

          $stock_code = $product->get_sku();
          $quantity_sold = $item['quantity'];
          $line_price = $product->get_price(); // Full Product Price
          $total_price = money_format('%i', round($item->get_total(), 2));
          // $total_price = money_format('%i', $item->get_total());
          $product_id = $item->get_product_id();
          $product_name = str_replace('/', '', $item['name']);

          // Tax
          $item_tax_class = $product->get_tax_class(); // Tax class
          $item_subtotal_tax = $item->get_subtotal_tax(); // Line item name
          $item_total_tax = money_format('%i', $item->get_total_tax()); // Tax rate code
          $item_taxes_array = $item->get_taxes(); // Tax detailed Array

          $the_total = $total_price + $item_total_tax; // Actual line price

          $percentage = abs(round(self::xcheq_calc_discount( $the_total, $total_price )));

          if ($coupons) {
            foreach ($coupons as $key => $coupon) {
              if ($coupon['type'] === 'percent') {
                // PERCENTAGE COUPON AMOUNT
                $type = 'percent';
                if (in_array($product_id, $coupon['products'])) {
                  $discount_total = money_format('%i', round($line_price * ((100 - $coupon['amount']) / 100), 2));
                  $discount_total = abs(round(($discount_total - $line_price) * $quantity_sold, 2));
                  $the_total = money_format('%i', round($the_total, 2));
                  $discount_text = ' (inc discount of ' . $discount_total . ')';
                  $tax_calc = money_format('%i', round($coupon['amount'] * ((100 - $percentage) / 100), 2));
                  $line_price = $line_price - $tax_calc;
                } else {
                  // Insert coupon to new item in database
                  // $overall_discount_applied = true;
                  if ($coupon_inserted === false) {
                    $coupon_inserted = self::xcheq_insert_coupon($order, $coupon, $type, $order_header_id, $order_number, $total_price, $percentage);
                  }
                }
              } else {
                // FIXED COUPON AMOUNT
                $type = 'fixed';
                if (in_array($product_id, $coupon['products'])) {
                  $discount_total = $coupon['amount'] * $quantity_sold;
                  $the_total = money_format('%i', round($the_total, 2));
                  $discount_text = ' (inc discount of ' . $discount_total . ')';
                  $tax_calc = money_format('%i', round($coupon['amount'] * ((100 - $percentage) / 100), 2));
                  $line_price = $line_price - $tax_calc;
                } else {
                  // Insert coupon to new item in database
                  $overall_discount_applied = true;
                  if ($coupon_inserted === false) {
                    $coupon_inserted = self::xcheq_insert_coupon($order, $coupon, $type, $order_header_id, $order_number, $total_price, $percentage);
                  }
                }
              }
            }
          }

          //$ex_vat = round($product->get_price_excluding_tax(), 2); // old method
          $ex_vat = round($item['subtotal'] / $item['quantity'], 2); // new method
          $line_price = round($item->get_total(), 2);

          if ($overall_discount_applied === true) {
            // This should get the total of the original product because if an overall discount is applied,
            // the value should be removed via new exchequer line. Not on a per product basis.

            // I think this is all correct...
            // $line_price = $_product->get_price_excluding_tax();
            $p_line_price = round($product->get_price_excluding_tax(), 2);
            $full_price = $product->get_price();
            // $ex_vat = $p_line_price * $quantity_sold;
            $item_total_tax_almost = $full_price - $p_line_price;
            $item_total_tax = money_format('%i', round($item_total_tax_almost, 2));
            $line_price = money_format('%i', round($ex_vat * $quantity_sold, 2));
          }

          // self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'ORDER LINE PRICE '.$line_price);
          // self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'ORDER TOTAL PRICE '.$ex_vat);

          $data = array(
            'order_details_id' => '',
            'order_header_id' => $order_header_id,
            'stock_code' => $stock_code,
            'description' => str_replace(':', '', esc_sql( $product_name )),
            'unit_nett_price' => $ex_vat,
            'quantity_sold' => $quantity_sold,
            'line_nett_value' => $line_price,
            'line_vat_value' => $item_total_tax,
            'vat_code' => 'S',
            'vat_rate' => $percentage,
            'original_web_order_line_id' => $order_number,
            'location_code' => 'UK1'
          );

          self::xcheq_logger('NOTICE', 'xcheq_add_order_to_db', 'ORDER TOTAL PRICE ' . print_r($data, true) );

          $data_types = array(
            '%d',
            '%d',
            '%s',
            '%s',
            '%f',
            '%f',
            '%f',
            '%f',
            '%s',
            '%f',
            '%f',
            '%s'
          );

          if ($stock_code) {
            self::xcheq_logger('NOTICE', 'xcheq_add_order_details_to_db', 'INSERTING:  ' . $stock_code);
            $mydb->insert(
              'order_details',
              $data,
              $data_types
            );
          } else {
            self::xcheq_logger('FATAL', 'xcheq_add_order_details_to_db', 'Failed to parse product data into database. Missing SKU. Product ID: ' . $product_id);
          }

          if ( $mydb->error ) {
            self::xcheq_logger('FATAL', 'xcheq_add_order_details_to_db', 'Failed to parse product data into database. Product SKU: ' . $stock_code);
          }
        }

        self::xcheq_insert_shipping( $order, $order_header_id, $percentage, $order_number );

      } else {
        self::xcheq_logger('FATAL', 'xcheq_add_order_details_to_db', 'No order items found ' . $order->get_id());
      }
    } else {
      self::xcheq_logger('FATAL', 'xcheq_add_order_details_to_db', 'No order found ' . $order->get_id());
    }
  }

  public function xcheq_calc_discount($num1, $num2) {
    $oldFigure = $num1;
    $newFigure = $num2;

    $percentChange = (1 - $oldFigure / $newFigure) * 100;

    if (is_nan($percentChange)) {
      return 0;
    } else {
      return $percentChange;
    }
  }

  private function xcheq_insert_coupon( $order, $coupon, $type, $order_header_id, $order_number, $price, $percentage ) {

    if ($coupon) {
      $mydb = self::xcheq_db_connect();

      if ($type === 'percent') {
        $new_price = -$order->get_discount_total();
        //$tax = -$order->get_discount_tax();
        $tax = 0;
      } else {
        //$new_price = -$coupon['amount'];
        $new_price = -$order->get_discount_total();
        $tax = -$order->get_discount_tax();
      }

      $data = array(
        'order_details_id' => '',
        'order_header_id' => $order_header_id,
        'stock_code' => '0065',
        'description' => $coupon['code'],
        'unit_nett_price' => $new_price,
        'quantity_sold' => 1,
        'line_nett_value' => $new_price,
        'line_vat_value' => $tax,
        'vat_code' => 'S',
        'vat_rate' => $percentage,
        'original_web_order_line_id' => $order_number,
        'location_code' => 'UK1'
      );

      $data_types = array(
        '%d',
        '%d',
        '%s',
        '%s',
        '%f',
        '%f',
        '%f',
        '%f',
        '%s',
        '%f',
        '%f',
        '%s'
      );

      $mydb->insert(
        'order_details',
        $data,
        $data_types
      );

      if ( $mydb->error ) {
        self::xcheq_logger('FATAL', 'xcheq_add_order_details_to_db', 'Failed to parse coupons into database: ' . $mydb->error);
        return false;
      } else {
        return true;
      }
    }
  }

  private function xcheq_insert_shipping( $order, $order_header_id, $tax_value, $order_number ) {
    $mydb = self::xcheq_db_connect();

    foreach( $order->get_items( 'shipping' ) as $item_id => $shipping_item_obj ) {
      // Get the data in an unprotected array
      $shipping_data = $shipping_item_obj->get_data();

      $shipping_data_method_title = $shipping_data['method_title'];
      $shipping_data_total        = money_format('%i', $shipping_data['total']);
      $shipping_data_total_tax    = money_format('%i', $shipping_data['total_tax']);
      $shipping_data_taxes        = $shipping_data['taxes'];
      // $shipping_data_exvat = money_format('%i', $shipping_data_total - $shipping_data_total_tax);
      $shipping_data_exvat = money_format('%i', $shipping_data_total);
      // $stock_code = ( $shipping_data_method_title === 'Next Day Delivery') ? 0064:''; // TODO Find out other stock codes for delivery
      // self::xcheq_logger('FATAL', 'xcheq_add_order_details_to_db', 'Failed to parse shipping data into database. Order: ' . $shipping_data_method_title);

      if ( $shipping_data_method_title !== 'Free shipping' ) {
        $data = array(
          'order_details_id' => '',
          'order_header_id' => $order_header_id,
          'stock_code' => '0064',
          'description' => $shipping_data_method_title,
          'unit_nett_price' => $shipping_data_exvat,
          'quantity_sold' => 1,
          'line_nett_value' => $shipping_data_exvat,
          'line_vat_value' => $shipping_data_total_tax,
          'vat_code' => 'S',
          'vat_rate' => $tax_value,
          'original_web_order_line_id' => $order_number,
          'location_code' => 'UK1'
        );

        $data_types = array(
          '%d',
          '%d',
          '%s',
          '%s',
          '%f',
          '%f',
          '%f',
          '%f',
          '%s',
          '%f',
          '%f',
          '%s'
        );

        $mydb->insert(
          'order_details',
          $data,
          $data_types
        );

        if ( $mydb->error ) {
          self::xcheq_logger('FATAL', 'xcheq_add_order_details_to_db', 'Failed to parse shipping data into database. Order: ' . $order_number);
        }
      }
    }
  }

  public function xcheq_get_coupons($order) {
    $coupons = $order->get_used_coupons();

    if ( $coupons ) {
      foreach ( $coupons as $key => $coupon_name ) {
        $coupon_post_obj = get_page_by_title( $coupon_name, OBJECT, 'shop_coupon' );
        $coupon_id = $coupon_post_obj->ID;

        $coupons_obj = new WC_Coupon( $coupon_id );

        $products = $coupons_obj->get_product_ids();

        if ( $products ) {
          // Line discount
          $coupon_amount[$key]['code'] = $coupons_obj->get_code();
          $coupon_amount[$key]['type'] = $coupons_obj->get_discount_type();
          $coupon_amount[$key]['amount'] = $coupons_obj->get_amount();

          foreach ($coupons_obj->get_product_ids() as $key => $value) {
            $coupon_amount[$key]['products'][] = $value;
          }

        } else {
          // Order discount
          $coupon_amount[$key]['code'] = $coupons_obj->get_code();
          $coupon_amount[$key]['type'] = $coupons_obj->get_discount_type();
          $coupon_amount[$key]['amount'] = $coupons_obj->get_amount();
        }
      }

      return $coupon_amount;
    }
  }

  private function xcheq_debugger( $order_id ) {
    // Order
    $order = new WC_Order( 21003  );

    if ($order) {
      // Coupons
      $coupons = self::xcheq_get_coupons( $order );

      // Items
      $items = $order->get_items();
      // var_dump($items);
      $overall_discount_applied = false;

      if ($items) {
        foreach($items as $item) {
          // Apologies for the number of echos. It was very rushed to get this function up and running
          echo '<table width="100%" style="text-align:left">';
            echo '<tr>';
            echo '<th width="17.5%">Product ID/SKU/Name</td>';
            echo '<th width="17.5%">Quantity</td>';
            echo '<th width="17.5%">Unit Price (ex.VAT)</td>';
            echo '<th width="17.5%">Line Price (ex.VAT)</td>';
            echo '<th width="17.5%">Total</td>';
            echo '</tr>';

            $product = $item->get_product();

            $stock_code = $product->get_sku();
            $quantity_sold = $item['quantity'];
            $line_price = $product->get_price(); // Full Product Price

            $total_price = money_format('%i', round($item->get_total(), 2));
            $product_id = $item->get_product_id();
            $product_name = esc_sql( $item['name'] );

            // Tax
            $item_tax_class = $product->get_tax_class(); // Tax class
            $item_subtotal_tax = $item->get_subtotal_tax(); // Line item name
            $item_total_tax = money_format('%i', $item->get_total_tax()); // Tax rate code
            $item_taxes_array = $item->get_taxes(); // Tax detailed Array

            $the_total = $total_price + $item_total_tax; // Actual line price
            $percentage = abs(round(self::xcheq_calc_discount( $the_total, $total_price )));

            if ($coupons) {
              foreach ($coupons as $key => $coupon) {
                if ($coupon['type'] === 'percent') {
                  // PERCENTAGE COUPON AMOUNT
                  if (in_array($product_id, $coupon['products'])) {
                    $discount_total = money_format('%i', round($line_price * ((100 - $coupon['amount']) / 100), 2)); // Dont forget to do * by quantity sold
                    $discount_total = abs(round(($discount_total - $line_price) * $quantity_sold, 2));
                    $the_total = money_format('%i', round($the_total, 2));
                    $discount_text = ' (inc discount of ' . $discount_total . ')';
                    $tax_calc = money_format('%i', round($coupon['amount'] * ((100 - $percentage) / 100), 2));
                    $line_price = $line_price - $tax_calc;
                  } else {
                    //$overall_discount_applied = true;
                    // Insert coupon to new item in database
                    $discount_total = $order->get_discount_total();
                    $discount_text = ' (ex discount of ' . $discount_total . ')';
                  }
                } else {
                  // FIXED COUPON AMOUNT
                  if (in_array($product_id, $coupon['products'])) {
                    $discount_total = $coupon['amount'] * $quantity_sold;
                    $the_total = money_format('%i', round($the_total, 2));
                    $discount_text = ' (inc discount of ' . $discount_total . ')';
                    $tax_calc = money_format('%i', round($coupon['amount'] * ((100 - $percentage) / 100), 2));
                    $line_price = $line_price - $tax_calc;
                  } else {
                    $overall_discount_applied = true;
                    // Insert coupon to new item in database
                    $discount_total = $order->get_discount_total();
                    $discount_text = ' (ex discount of ' . $discount_total . ')';
                  }
                }
              }
            }

            $ex_vat = round($product->get_price_excluding_tax(), 2);
            $line_price = round($item->get_total(), 2);

            if ($overall_discount_applied === true) {
              // This should get the total of the original product because if an overall discount is applied,
              // the value should be removed via new exchequer line. Not on a per product basis.

              // I think this is all correct...
              $_product = wc_get_product( $product_id );
              // if ()
              // var_dump($item->get_product());
              //$_produ
              $p_line_price = round($product->get_price_excluding_tax(), 2);
              $full_price = $product->get_price();
              $total_price = $p_line_price * $quantity_sold;
              $item_total_tax_almost = $full_price - $p_line_price;
              $item_total_tax = money_format('%i', round($item_total_tax_almost, 2));
            }

            echo '<tr>';
            echo '<td>'.$product_id.'/'.$stock_code.'/<strong>'.$product_name.'</strong></td>';
            echo '<td>'.$quantity_sold.'</td>';
            echo '<td>'.$total_price.'</td>';
            echo '<td>'.$line_price.'</td>';
            echo '<td>'.$the_total.$discount_text.'</td>';
            echo '</tr>';
          echo '</table>';

          // Tax Data
          echo '<table width="100%" style="text-align:left;">';
            echo '<tr>';
            echo '<td width="17.5%"></td>';
            echo '<td width="17.5%"></td>';
            echo '<td width="17.5%"></td>';
            echo '<td width="17.5%"></td>';
            echo '<td width="17.5%"><strong>Total Tax: </strong>'.$item_total_tax.'</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<td></td>';
            echo '<td></td>';
            echo '<td></td>';
            echo '<td></td>';
            echo '<td><strong>Tax %: </strong>'.$percentage.'</td>';
            echo '</tr>';
          echo '</table>';
        }
      }

      // Delivery
      foreach( $order->get_items( 'shipping' ) as $item_id => $shipping_item_obj ){
        // Get the data in an unprotected array
        $shipping_data = $shipping_item_obj->get_data();

        $shipping_data_method_title = $shipping_data['method_title'];
        $shipping_data_total = money_format('%i', $shipping_data['total']);
        $shipping_data_total_tax = money_format('%i', $shipping_data['total_tax']);
        $shipping_data_taxes = $shipping_data['taxes'];
        $shipping_data_exvat = money_format('%i', $shipping_data_total - $shipping_data_total_tax);

        echo '<hr/>';

        echo '<table width="100%">';
        echo '<tr>';
        echo '<td width="17.5%"></td>';
        echo '<td width="17.5%"></td>';
        echo '<td width="17.5%"></td>';
        echo '<td width="17.5%"></td>';
        echo '<td width="17.5%"><strong>Shipping Method: </strong>'.$shipping_data_method_title.'</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td width="17.5%"></td>';
        echo '<td width="17.5%"></td>';
        echo '<td width="17.5%"></td>';
        echo '<td width="17.5%"></td>';
        echo '<td width="17.5%"><strong>Shipping Total: </strong>'.$shipping_data_total.'</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td width="17.5%"></td>';
        echo '<td width="17.5%"></td>';
        echo '<td width="17.5%"></td>';
        echo '<td width="17.5%"></td>';
        echo '<td width="17.5%"><strong>Shipping Tax: </strong>'.$shipping_data_total_tax.'</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td width="17.5%"></td>';
        echo '<td width="17.5%"></td>';
        echo '<td width="17.5%"></td>';
        echo '<td width="17.5%"></td>';
        echo '<td width="17.5%"><strong>Shipping (ex.VAT): </strong>'.$shipping_data_exvat.'</td>';
        echo '</tr>';
        echo '</table>';
      }
    }
  }

  // Logger to help debug issues if/when they arise.
  private function xcheq_logger( $error_type, $func, $error ) {
    $date = date('d-m-Y H:i:s').' ';
    $type = '- '.$error_type.' in ';
    $function = 'Function '.$func.': ';
    $error = "$error; \n";

    $error_output = $date . $type . $function . $error;

    // Save string to log, use FILE_APPEND to append.
    file_put_contents(__DIR__ . '/log_xcheq.log', $error_output, FILE_APPEND);
  }

  private function isDebugOn()
  {
    return $this->debug;
  }

}

new Xcheq;