<?php
require_once 'utils.php';

class MobbexHelper
{
    /**
     * All 'ahora' plans.
     */
    public static $ahora = ['ahora_3', 'ahora_6', 'ahora_12', 'ahora_18'];

    public function __construct()
    {
        // Init settings (Full List in WC_Gateway_Mobbex::init_form_fields)
        $option_key = 'woocommerce_' . MOBBEX_WC_GATEWAY_ID . '_settings';
        $settings = get_option($option_key, null) ?: [];
        foreach ($settings as $key => $value) {
            $key = str_replace('-', '_', $key);
            $this->$key = $value;
        }
    }

    public function isReady()
    {
        return ($this->enabled === 'yes' && !empty($this->api_key) && !empty($this->access_token));
    }

    /**
     * Get sources with common plans and advanced plans from mobbex.
     * @param integer|null $total
     * @param array|null $inactivePlans
     * @param array|null $activePlans
     */
    public function get_sources($total = null, $inactivePlans = null, $activePlans = null)
    {
        // If plugin is not ready
        if (!$this->isReady()) {
            return [];
        }

        $data = $total ? '?total=' . $total : null;

        //Get installments
        $data .= '&' . self::get_installments_query($inactivePlans, $activePlans);

        $response = wp_remote_get(MOBBEX_SOURCES . $data, [

            'headers' => [
                'cache-control' => 'no-cache',
                'content-type' => 'application/json',
                'x-api-key' => $this->api_key,
                'x-access-token' => $this->access_token,
            ],

        ]);

        if (!is_wp_error($response)) {
            $response = json_decode($response['body'], true);
            $data = $response['data'];
            if ($data) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Returns a query param with the installments of the product.
     * @param array $inactivePlans
     * @param array $activePlans
     */
    public static function get_installments_query($inactivePlans = null, $activePlans = null ) {
        
        $installments = [];
        
        //get plans
        if($inactivePlans) {
            foreach ($inactivePlans as $plan) {
                $installments[] = "-$plan";
            }
        }

        if($activePlans) {
            foreach ($activePlans as $plan) {
                $installments[] = "+uid:$plan";
            } 
        }

        //Build query param
        $query = http_build_query(['installments' => $installments]);
        $query = preg_replace('/%5B[0-9]+%5D/simU', '%5B%5D', $query);
        
        return $query;
    }

    /**
     * Retrive inactive common plans from a product and its categories.
     * 
     * @param int $product_id
     * 
     * @return array
     */
    public static function get_inactive_plans($product_id)
    {
        $categories     = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        $inactive_plans = [];

        // Get inactive 'ahora' plans (previus save method)
        foreach (self::$ahora as $plan) {
            // Get from product
            if (get_post_meta($product_id, $plan, true) === 'yes') {
                $inactive_plans[] = $plan;
                continue;
            }

            // Get from product categories
            foreach ($categories as $cat_id) {
                if (get_term_meta($cat_id, $plan, true) === 'yes') {
                    $inactive_plans[] = $plan;
                    break;
                }
            }
        }

        // Get plans from product and product categories
        $inactive_plans = array_merge($inactive_plans, self::unserialize_array(get_post_meta($product_id, 'common_plans', true)));

        foreach ($categories as $cat_id)
            $inactive_plans = array_merge($inactive_plans, self::unserialize_array(get_term_meta($cat_id, 'common_plans', true)));

        // Remove duplicated and return
        return array_unique($inactive_plans);
    }

    /**
     * Retrive active advanced plans from a product and its categories.
     * 
     * @param int $product_id
     * 
     * @return array
     */
    public static function get_active_plans($product_id)
    {
        $categories     = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        $active_plans = [];

        // Get plans from product and product categories
        $active_plans = array_merge($active_plans, self::unserialize_array(get_post_meta($product_id, 'advanced_plans', true)));

        foreach ($categories as $cat_id)
            $active_plans = array_merge($active_plans, self::unserialize_array(get_term_meta($cat_id, 'advanced_plans', true)));

        // Remove duplicated and return
        return array_unique($active_plans);
    }

    /**
     * Get all product IDs from Order.
     * 
     * @param WP_Order $order
     * @return array $products
     */
    public static function get_product_ids($order)
    {
        $products = [];

        foreach ($order->get_items() as $item)
            $products[] = $item->get_product_id();

        return $products;
    }

    /**
     * Get all category IDs from Order.
     * Duplicates are removed.
     * 
     * @param WP_Order $order
     * @return array $categories
     */
    public static function get_category_ids($order)
    {
        $categories = [];

        // Get Products Ids
        $products = self::get_product_ids($order);

        foreach($products as $product)
            $categories = array_merge($categories, wp_get_post_terms($product, 'product_cat', ['fields' => 'ids']));

        // Remove duplicated IDs and return
        return array_unique($categories);
    }

    /**
     * Get payment mode.
     * 
     * @return string|null $payment_mode
     */
    public function get_payment_mode()
    {
        if (defined('MOBBEX_CHECKOUT_INTENT') && !empty(MOBBEX_CHECKOUT_INTENT)) {
            // Try to get from constant first
            return MOBBEX_CHECKOUT_INTENT;
        } else if (!empty($this->payment_mode) && $this->payment_mode === 'yes') {
            return 'payment.2-step';
        }
    }

    /**
     * Get Store configured by product/category using Multisite options.
     * 
     * @param WP_Order $order
     * 
     * @return array|null $store
     */
    public static function get_store($order)
    {
        $stores   = get_option('mbbx_stores') ?: [];
        $products = self::get_product_ids($order);

        // Search store configured
        foreach ($products as $product_id) {
            $store_configured = self::get_store_from_product($product_id);

            if (!empty($store_configured) && !empty($stores[$store_configured]))
                return $stores[$store_configured];
        }
    }

    /**
     * Get Store ID from product and its categories.
     * 
     * @param int|string $product_id
     * 
     * @return string|null $store_id
     */
    public static function get_store_from_product($product_id)
    {
        $stores     = get_option('mbbx_stores') ?: [];
        $store      = get_post_meta($product_id, 'mbbx_store', true);
        $ms_enabled = get_post_meta($product_id, 'mbbx_enable_multisite', true);

        if ($ms_enabled && !empty($store) && !empty($stores[$store]) )
            return $store;

        // Get stores from product categories
        $categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);

        foreach ($categories as $cat_id) {
            $store      = get_term_meta($cat_id, 'mbbx_store', true);
            $ms_enabled = get_term_meta($cat_id, 'mbbx_enable_multisite', true);

            if ($ms_enabled && !empty($store) && !empty($stores[$store]))
                return $store;
        }
    }

    /**
     * Capture 'authorized' payment using Mobbex API.
     * 
     * @param string|int $payment_id
     * @param string|int $total
     * 
     * @return bool $result
     */
    public function capture_payment($payment_id, $total)
    {
        if (!$this->isReady())
            throw new Exception(__('Plugin is not ready', 'mobbex-for-woocommerce'));

        if (empty($payment_id) || empty($total))
            throw new Exception(__('Empty Payment UID or params', 'mobbex-for-woocommerce'));

        // Capture payment
        $response = wp_remote_post(str_replace('{id}', $payment_id, MOBBEX_CAPTURE_PAYMENT), [
            'headers' => [
                'cache-control'  => 'no-cache',
                'content-type'   => 'application/json',
                'x-api-key'      => $this->api_key,
                'x-access-token' => $this->access_token,
            ],

            'body'        => json_encode(compact('total')),
            'data_format' => 'body',
        ]);

        if (!is_wp_error($response)) {
            $response = json_decode($response['body'], true);

            if (!empty($response['result']))
                return true;
        }

        throw new Exception(__('An error occurred in the execution', 'mobbex-for-woocommerce'));
    }

    /**
     * Try unserialize a string forcing an array as return.
     * 
     * @param mixed $var
     * 
     * @return array
     */
    public static function unserialize_array($var)
    {
        if (is_string($var))
            $var = unserialize($var);

        return is_array($var) ? $var : [];
    }
}