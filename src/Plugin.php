<?php

namespace WcInstallmentPayments;

use WcInstallmentPayments\Core\Scheduler;
use WcInstallmentPayments\Core\WebhookDispatcher;
use WcInstallmentPayments\Admin\AdminMenu;
use WcInstallmentPayments\Integration\StripeService;
use WcInstallmentPayments\Payments\CheckoutHandler;
use WcInstallmentPayments\Payments\PaymentManager;
use WcInstallmentPayments\Payments\PlanManager;

class Plugin {

    private static ?Plugin $instance = null;

    // Services
    public ?PlanManager $plan_manager = null;
    public ?PaymentManager $payment_manager = null;
    public ?StripeService $stripe_service = null;
    public ?CheckoutHandler $checkout_handler = null;
    public ?Scheduler $scheduler = null;
    public ?WebhookDispatcher $webhook_dispatcher = null;
    public ?AdminMenu $admin_menu = null;

    private bool $services_initialized = false;
    private bool $hooks_registered = false;
    private bool $admin_initialized = false;

    public static function get_instance(): Plugin {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init' ] );
    }

    public function init(): void {
        error_log( 'WCIP Debug: Plugin init() called' );
        
        // Security: WooCommerce required
        if ( ! class_exists( 'WooCommerce' ) ) {
            error_log( 'WCIP Debug: WooCommerce not loaded, adding hook' );
            add_action( 'woocommerce_loaded', [ $this, 'init' ] );
            return;
        }

        error_log( 'WCIP Debug: WooCommerce is loaded, initializing services' );

        if ( ! $this->services_initialized ) {
            $this->plan_manager    = new PlanManager();
            $this->payment_manager = new PaymentManager();
            $this->stripe_service  = new StripeService();
            $this->services_initialized = true;
        }

        if ( ! $this->hooks_registered ) {
            error_log( 'WCIP Debug: Registering checkout handler hooks' );
            $this->checkout_handler = new CheckoutHandler( $this->plan_manager, $this->payment_manager );
            $this->checkout_handler->register();

            $this->scheduler = new Scheduler( $this->payment_manager, $this->plan_manager, $this->stripe_service );
            $this->scheduler->register();

            $this->webhook_dispatcher = new WebhookDispatcher();
            $this->webhook_dispatcher->register();

            $this->hooks_registered = true;
            error_log( 'WCIP Debug: All hooks registered successfully' );
        }

        if ( is_admin() && ! $this->admin_initialized ) {
            $this->admin_menu = new AdminMenu();
            $this->admin_menu->init();
            $this->admin_initialized = true;
        }

        // Here, we will load WooCommerce Hooks later
        // $this->load_hooks();
    }
}