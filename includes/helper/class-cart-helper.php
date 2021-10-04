<?php

class MobbexCartHelper
{
    /** Cart instance ID */
    public $id;

    /** @var WC_Cart */
    public $cart;

    /** @var MobbexHelper */
    public $helper;

    /**
    * Constructor.
    * 
    * @param WC_Cart WooCommerce Cart instance.
    * @param MobbexHelper Base plugin helper.
    */
    public function __construct($cart, $helper = null)
    {
        $this->id     = $cart->get_cart_hash();
        $this->cart   = $cart;
        $this->helper = $helper ?: new MobbexHelper();
    }

    /**
     * Create a checkout using the WooCommerce Cart instance.
     * 
     * @return mixed
     */
    public function create_checkout()
    {
        $api_key      = $this->helper->settings['api-key'];
        $access_token = $this->helper->settings['access-token'];

        $api      = new MobbexApi($api_key, $access_token);
        $checkout = new MobbexCheckout($this->helper->settings, $api);

        $this->add_initial_data($checkout);
        $this->add_items($checkout);
        $this->add_installments($checkout);
        $this->add_customer($checkout);

        try {
            $response = $checkout->create();
        } catch (\Exception $e) {
            $response = null;
            $this->helper->debug('Mobbex Checkout Creation Failed: ' . $e->getMessage(), isset($e->data) ? $e->data : '');
        }

        do_action('mobbex_checkout_process', $response, $this->id);

        return $response;
    }

    /**
     * Add cart initial data to checkout.
     * 
     * @param MobbexCheckout $checkout
     */
    private function add_initial_data($checkout)
    {
        $checkout->set_reference($this->id);
        $checkout->set_total($this->cart->get_total(null));
        $checkout->set_endpoints(
            $this->helper->get_api_endpoint('mobbex_return_url', $this->id),
            $this->helper->get_api_endpoint('mobbex_webhook', $this->id),
        );
    }

    /**
     * Add cart items to checkout.
     * 
     * @param MobbexCheckout $checkout
     */
    private function add_items($checkout)
    {
        $items = $this->cart->get_cart() ?: [];

        foreach ($items as $item)
            $checkout->add_item($item['line_total'], $item['quantity'], $item['data']->get_name(), $this->helper->get_product_image($item['product_id']));

        $checkout->add_item($this->cart->get_shipping_total(), 1, __('Shipping: ', 'mobbex-for-woocommerce'));
    }

    /**
     * Add installments configured to checkout.
     * 
     * @param MobbexCheckout $checkout
     */
    private function add_installments($checkout)
    {
        $inactive_plans = $active_plans = [];
        $items = $this->cart->get_cart() ?: [];

        // Get plans from cart products
        foreach ($items as $item) {
            $inactive_plans = array_merge($inactive_plans, $this->helper::get_inactive_plans($item['product_id']));
            $active_plans   = array_merge($active_plans, $this->helper::get_active_plans($item['product_id']));
        }

        // Block inactive (common) plans from installments
        foreach ($inactive_plans as $plan_ref)
            $checkout->block_installment($plan_ref);

        // Add active (advanced) plans to installments (only if the plan is active on all products)
        foreach (array_count_values($active_plans) as $plan_uid => $reps) {
            if ($reps == count($items))
                $checkout->add_installment($plan_uid);
        }
    }

    /**
     * Add cart customer data to checkout.
     * 
     * @param MobbexCheckout $checkout
     */
    private function add_customer($checkout)
    {
        $customer = $this->cart->get_customer();

        $checkout->set_customer(
            $customer->get_display_name(),
            $customer->get_billing_email(),
            '12123123',
            $customer->get_billing_phone() ?: get_user_meta($customer->get_id(), 'phone_number', true),
            $customer->get_id(),
        );
    }
}