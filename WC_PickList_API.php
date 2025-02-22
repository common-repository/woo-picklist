<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists('WC_PickList_API')) :

    class WC_PickList_API extends WC_Integration
    {

        public function __construct()
        {

            add_action('rest_api_init', function () {
                register_rest_route('wc-picklist/v1', 'isAuthenticated', array(
                    'methods' => 'GET',
                    'callback' => array($this, 'isAuthenticatedAPI'),
                ));
            });

            add_action('rest_api_init', function () {
                register_rest_route('wc-picklist/v1', 'getOpenOrders', array(
                    'methods' => 'GET',
                    'callback' => array($this, 'getOpenOrdersAPI'),
                    'permission_callback' => function () {
                        return $this->isAuthenticatedCheck();

                    },
                ));
            });

            add_action('rest_api_init', function () {
                register_rest_route('wc-picklist/v1', '/getOpenOrderByID/(?P<id>\d+)', array(
                    'methods' => 'GET',
                    'callback' => array($this, 'getOpenOrderByIDAPI'),
                    'args' => array(
                        'id' => array(
                            'validate_callback' => function ($param, $request, $key) {
                                return is_numeric($param);
                            }
                        ),
                    ),
                    'permission_callback' => function () {
                        return $this->isAuthenticatedCheck();
                    },
                ));
            });

            add_action('rest_api_init', function () {
                register_rest_route('wc-picklist/v1', 'getImageByID/(?P<id>\d+)', array(
                    'methods' => 'GET',
                    'callback' => array($this, 'getImageByIDAPI'),
                    'args' => array(
                        'id' => array(
                            'validate_callback' => function ($param, $request, $key) {
                                return is_numeric($param);
                            }
                        ),
                    ),
                ));
            });


            add_action('rest_api_init', function () {
                register_rest_route('wc-picklist/v1', 'setShipmentForOrderID', array(
                    'methods' => 'POST',
                    'callback' => array($this, 'setShipmentForOrderIDAPI'),
                    'permission_callback' => function () {
                        return $this->isAuthenticatedCheck();
                    },
                ));
            });

        }


        //// AUTH


        public function isAuthenticatedAPI()
        {

            $picklist_setting = $this->getSettings();
            $picklist_version =  get_option('picklist_version');

            if ($this->isAuthenticatedCheck()) {
                wp_send_json(array('success' => true, 'isAuthenticated' => $this->isAuthenticatedCheck(), 'picklist_version' => $picklist_version, 'settings' => $picklist_setting));
            } else {
                wp_send_json(array('success' => true, 'isAuthenticated' => $this->isAuthenticatedCheck(), 'picklist_version' => $picklist_version));
            }

            die();
        }

        private function isAuthenticatedCheck()
        {

            do_action('woo_picklist_before_authenticated_check');

            $WC_REST_Authentication = new WC_REST_Authentication();
            if ($WC_REST_Authentication->authenticate(false)) {
                return true;
            }

            return false;

        }


        //// GET OPEN ORDERS

        public function getOpenOrdersCount(){

            $openOrders = 0;

            $args = array(
                'post_type' => 'shop_order',
                'post_status' => 'wc-processing',
                'posts_per_page' => -1,
            );

            $wp_query = new WP_Query($args);

            while ($wp_query->have_posts()) {
                $wp_query->the_post();

                $order = $this->getOrder(get_the_ID());

                if ($order["qty_open"] > 0) {
                    $openOrders++;
                }

            }

            return $openOrders;

        }

        public function getOpenOrdersAPI()
        {

            $orders = array();

            // limit it to 50 orders at once
            $max_count = 50;
            $i = 0;

            while (count($orders) < $max_count) {

                $args = array(
                    'post_type' => 'shop_order',
                    'post_status' => 'wc-processing',
                    'posts_per_page' => $max_count,
                    'orderby' => 'date',
                    'order' => 'ASC',
                    'page' => 1,
                    'offset' => $max_count * $i
                );

                $i++;

                $wp_query = new WP_Query($args);

                if ($wp_query->have_posts()) {
                    while ($wp_query->have_posts()) {
                        $wp_query->the_post();

                        $order = $this->getOrder(get_the_ID());

                        if ($order["qty_open"] > 0) {
                            $orders[] = $order;
                        }

                        if (count($orders) >= $max_count) {
                            break;
                        }
                    }
                } else {
                    break;
                }
            }

            foreach ($orders as $key => $row) {
                //$types[$key] = $row['type'];
                $timestamps[$key] = $row['timestamp'];

            }

            if(count($orders)>0){
                //array_multisort($types, SORT_ASC, $timestamps, SORT_ASC, $orders);
                array_multisort($timestamps, SORT_ASC, $orders);

            }

            $openOrdersCount = $this->getOpenOrdersCount();

            wp_send_json(array('success' => true, 'orders' => $orders, 'count' => count($orders), 'total' => $openOrdersCount));

            die();

        }


        //// GET ORDER


        public function getOrder($order_id)
        {

            try {
                $WC_Order = new WC_Order($order_id);
            } catch (Exception $e) {
                return false;
            }

            if (!isset($WC_Order->post)) {
                return false;
            }

            $order = array();

            $order['status'] = $WC_Order->post_status;
            $order['id'] = $order_id;
            $order['title'] = $this->getFormattedTitleForOrderID($order_id);
            $order['admin_link'] = admin_url('post.php?post=' . $order_id . '&action=edit');
            $order['date'] = date('d.m - H:i', strtotime($WC_Order->order_date));
            $order['timestamp'] = strtotime($WC_Order->order_date);

            $order['amount'] = $this->getAmountOpenForOrderID($order_id) . ' ' . $WC_Order->order_currency;
            $order['qty_ordered_actual'] = $this->getQtyOrderedActualForOrderID($order_id);
            $order['qty_open'] = $this->getQtyOpenForOrderID($order_id);
            $order['qty_shipped'] = $this->getQtyShippedForOrderID($order_id);

            $order['items'] = $this->getItemsForOrder($WC_Order);

            $order['shipping_name'] = $WC_Order->shipping_first_name . ' ' . $WC_Order->shipping_last_name;
            $order['shipping_city'] = $WC_Order->shipping_city;
            $order['formatted_shipping_address'] = str_ireplace(array("<br />", "<br>", "<br/>"), "\n", $WC_Order->get_formatted_shipping_address());
            $order['formatted_shipping_address_url'] = $WC_Order->get_shipping_address_map_url();

            $order['billing_phone'] = $WC_Order->billing_phone;
            $order['billing_email'] = $WC_Order->billing_email;


            if ($order['qty_shipped'] > 0) {
                $order['type'] = 'bpart';
            } else {
                $order['type'] = 'anew';
            }

            return $order;

        }


        private function getFormattedTitleForOrderID($id)
        {

            if (count(get_post_meta($id, '_picklist_shipment')) == 0) {
                return "#" . $id;

            } else {
                return "#" . $id . "-" . (count(get_post_meta($id, '_picklist_shipment')) + 1);
            }

        }


        public function getItemsForOrder($WC_Order, $indexed = false)
        {

            if (!isset($WC_Order->post)) {
                $WC_Order = new WC_Order($WC_Order);
            }

            $items = array();
            foreach ($WC_Order->get_items() as $itemID => $lineItem) {

                $item = array();


                if ($lineItem['variation_id'] > 0) {
                    $item['id'] = $lineItem['variation_id'];
                } else {
                    $item['id'] = $lineItem['product_id'];
                }

                if ($item['sku'] == "" && !($lineItem['variation_id'] > 0)) {
                    if ($WC_Product = new WC_Product($lineItem['product_id'])) {
                        $item['sku'] = $WC_Product->get_sku();
                    }
                }

                if ($item['sku'] == "" && $lineItem['variation_id'] > 0) {
                    if ($WC_Product = new WC_Product_Variation($lineItem['variation_id'])) {
                        $item['sku'] = $WC_Product->get_sku();
                    }
                }

                if ($item['sku'] == "") {
                    $item['sku'] = $item['id'];
                }

                $item['sku'] = (string)$item['sku'];

                // ITEM FORCE CONFIRM

                $picklist_forceconfirm = get_option('picklist_forceconfirm');

                if($picklist_forceconfirm !== "yes"){
                    $item["item_unlocked"] = true;
                }

                // END

                $item["order_item_id"] = $itemID;

                $item['name'] = html_entity_decode($lineItem['name']);

                $item['formatted_attributes'] = $this->extractVariableProductAttributes($lineItem);

                if ($item['formatted_attributes'] == "") {
                    //$item['formatted_attributes'] = $this->extractProductCategories($item['id']);
                }

                $item['price'] = round(abs($lineItem['line_total'] / max(1, $lineItem['qty'])), 2);


                $item['qty_ordered'] = abs($lineItem['qty']);
                $item['qty_refunded'] = abs($WC_Order->get_qty_refunded_for_item($itemID));
                $item['qty_shipped'] = array_sum(wc_get_order_item_meta($itemID, '_picklist_shipped', false));
                $item['qty_open'] = ($item['qty_ordered'] - $item['qty_refunded'] - $item['qty_shipped']);

                $item['qty_picked'] = 0;

                if ($indexed) {
                    $items[$itemID] = $item;
                } else {
                    $items[] = $item;
                }


            }

            // sort by sku or id

            foreach ($items as $key => $row) {
                $skus[$key] = $row['sku'];
            }

            uasort($items, function ($a, $b) {
                return strnatcmp($a['sku'], $b['sku']);
            });

            return $items;
        }

        private function extractVariableProductAttributes($product)
        {

            $attributes = "";

            foreach ($product as $productMetaKey => $productMetaValue) {

                if (0 === strpos($productMetaKey, 'pa_')) {
                    $key = str_replace("pa_", "", $productMetaKey);
                    if ($attributes != "") {
                        $attributes .= ' ';
                    }
                    $attributes .= strtoupper($key) . ': ' . $productMetaValue;
                }
            }


            return $attributes;
        }

        private function extractProductCategories($id)
        {

            $categories = "";

            $parent_id = wp_get_post_parent_id($id);
            if ($parent_id) {
                $id = $parent_id;
            }

            $terms = get_the_terms($id, 'product_cat');

            if (is_array($terms))
                foreach ($terms as $term) {
                    if (strlen($categories) > 0) {
                        $categories .= ' > ';
                    }
                    $categories .= $term->name;
                }


            return $categories;
        }

        private function getAmountOpenForOrderID($order_id)
        {

            $WC_Order = new WC_Order($order_id);
            if (!isset($WC_Order->post)) {
                return 0;
            }

            $items = $this->getItemsForOrder($WC_Order);


            $price = 0;

            foreach ($items as $item) {
                $price = $price + $item["qty_open"] * $item["price"];
            }

            return round($price);
        }

        private function getQtyOpenForOrderID($order_id)
        {

            $WC_Order = new WC_Order($order_id);
            if (!isset($WC_Order->post)) {
                return 0;
            }

            $items = $this->getItemsForOrder($WC_Order);


            $qty = 0;

            foreach ($items as $item) {
                $qty = $qty + $item["qty_open"];
            }

            return $qty;
        }


        private function getQtyShippedForOrderID($order_id)
        {

            $WC_Order = new WC_Order($order_id);
            if (!isset($WC_Order->post)) {
                return 0;
            }

            $items = $this->getItemsForOrder($WC_Order);


            $qty = 0;

            foreach ($items as $item) {
                $qty = $qty + $item["qty_shipped"];
            }

            return $qty;
        }

        private function getQtyOrderedActualForOrderID($order_id)
        {

            $WC_Order = new WC_Order($order_id);
            if (!isset($WC_Order->post)) {
                return 0;
            }

            $items = $this->getItemsForOrder($WC_Order);


            $qty = 0;

            foreach ($items as $item) {
                $singleQty =  $item["qty_ordered"] -  $item["qty_refunded"];
                $qty = $qty + $singleQty;
            }

            return $qty;
        }


        public function getOpenOrderByIDAPI($data)
        {

            $order_id = $data['id'];

            $order = $this->getOrder($order_id);

            if ($order["status"] != "wc-processing") {
                if($order["status"] == ""){
                    $order["status"] = "not existing anymore.";
                }
                wp_send_json(array('success' => false, 'message' => "Order #$order_id can't be shipped, because it is " . $order["status"]));
                die();
            }

            if ($order) {

                if (isset($order["items"])) {
                    $order["items"] = $this->filterOpenItemsFromOrder($order["items"]);

                    if (count($order["items"]) > 0) {
                        wp_send_json(array('success' => true, 'order' => $order));
                        die();
                    } else {
                        wp_send_json(array('success' => false, 'message' => "There are no open items for this order!"));
                        die();
                    }


                }
            }

            wp_send_json(array('success' => false, 'message' => "Order #$order_id doesn't need to be shipped anymore"));
            die();

        }


        private function filterOpenItemsFromOrder($items)
        {

            $openItems = array();

            foreach ($items as $item) {
                if ($item["qty_open"] > 0) {
                    $openItems[] = $item;
                }
            }

            return $openItems;

        }

        public function getImageByIDAPI($data)
        {

            $product_id = $data['id'];

            $size = 'single-postthumbnail';
            if (isset($_REQUEST['size'])) {
                $size = $_REQUEST['size'];
            }

            $image_url = "";
            $images = wp_get_attachment_image_src(get_post_thumbnail_id($product_id), $size);
            if (isset($images[0])) {
                $image_url = $images[0];
            } else {
                $parent = wp_get_post_parent_id($product_id);
                if ($parent > 0) {
                    $images = wp_get_attachment_image_src(get_post_thumbnail_id($parent), $size);
                    if (isset($images[0])) {
                        $image_url = $images[0];
                    }
                }
            }

            if ($image_url != "") {
                wp_redirect($image_url);
            } else {
                wp_redirect(plugin_dir_url(__FILE__) . 'images/placeholder.png');


            }

            die();

        }

        public function setShipmentForOrderIDAPI($data)
        {

            $inputJSON = file_get_contents('php://input');
            $parameters = json_decode($inputJSON, true);
            if (!isset($parameters["id"]) || !isset($parameters["processed_items"])) {
                wp_send_json(array('success' => false, 'message' => "There is nothing to be shipped!"));
                die();
            } else {
                $order_id = $parameters["id"];
                $processed_items = $parameters["processed_items"];
            }
            $WC_Order = new WC_Order($order_id);
            if (!isset($WC_Order->post)) {
                wp_send_json(array('success' => false, 'message' => "Can't find order with ID " . $order_id));
                die();
            }
            if ($WC_Order->post_status != "wc-processing") {
                wp_send_json(array('success' => false, 'message' => "We can't ship that order, because order is " . $WC_Order->post_status));
                die();
            }

            $picklist_shipped_items = array();
            $order_items = $this->getItemsForOrder($WC_Order, true);


            foreach ($processed_items as $processed_item) {

                if (isset($order_items[$processed_item["order_item_id"]])) {

                    $qty_open = $order_items[$processed_item["order_item_id"]]["qty_open"];
                    $qty_picked = $processed_item["qty_picked"];

                    if ($qty_picked <= $qty_open && $qty_picked > 0) {
                        wc_add_order_item_meta($processed_item["order_item_id"], '_picklist_shipped', $qty_picked, false);
                        $picklist_shipped_items[] = $processed_item;
                        continue;
                    }else if ($qty_picked == 0) {
                        continue;
                    }

                }

                wp_send_json(array('success' => false, 'message' => $processed_item["name"] . " (" . $processed_item["sku"] . ") not needed anymore. Please check the order manually."));
                die();

            }

            if (count($picklist_shipped_items) > 0) {
                $picklist_shipment = array("time" => time(), "items" => $picklist_shipped_items);
                add_post_meta($order_id, '_picklist_shipment', $picklist_shipment);


                $comment = "SHIPMENT #" . count(get_post_meta($order_id, '_picklist_shipment')) . "\r\n";
                foreach ($picklist_shipped_items as $item) {
                    $comment .= $item["qty_picked"] . ' x ' . $item["name"] . ' (' . $item["sku"] . ')' . "\r\n";

                }
                $this->addCommentToOrder($order_id, $comment);
            }


            $picklist_autocomplete = get_option('picklist_autocomplete');

            if ($picklist_autocomplete == "yes") {
                $autocomplete_order = true;
            } else {
                $autocomplete_order = false;
            }

            $qty_open = $this->getQtyOpenForOrderID($order_id);


            if ($WC_Order->post_status == "wc-processing" && $autocomplete_order && $qty_open == 0) {
                $WC_Order->update_status('completed', 'PickList: ');
            }

            if ($qty_open == 0) {
                $isComplete = 1;
            } else {
                $isComplete = 0;
            }

            wp_send_json(array('success' => true, 'orderState' => array('isComplete' => (string)$isComplete)));

        }


        private function addCommentToOrder($id, $comment)
        {

            $commentdata = array(
                'comment_post_ID' => $id,
                'comment_author' => 'PickList',
                'comment_author_email' => 'info@picklist.pro',
                'comment_author_url' => 'http://picklist.pro',
                'comment_content' => $comment,
                'comment_agent' => 'PickList',
                'comment_type' => 'order_note',
                'comment_parent' => 0,
                'comment_approved' => 1,
            );

            $comment_id = wp_insert_comment($commentdata);

        }

        private function getSettings(){

            $picklist_setting['picklist_autocomplete'] = get_option('picklist_autocomplete');
            $picklist_setting['picklist_partial'] = get_option('picklist_partial');

            foreach ($picklist_setting as $key => $setting){

                if(!is_string($setting)){
                    unset($picklist_setting[$key]);
                }
            }

            return $picklist_setting;
        }



    }

endif;