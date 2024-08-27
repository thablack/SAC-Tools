<?php
/**
 * Plugin Name:       Woo Order Report
 * Description:       Sends a monthly report of WooCommerce orders.
 * Version:           1.0.0
 * Author:            Rick Laguerre
 * Text Domain:       woo-order-report
 * Domain Path:       /languages
 *
 * WC requires at least: 4.0
 * WC tested up to: 8.2
 *
 * License:           GNU General Public License v3.0
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace YodaBlack\WooOrderReport;

defined('ABSPATH') || exit;

class MonthlyOrderReportEmailer
{
    private static $initialized = false;

    public function __construct()
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        add_filter('cron_schedules', array($this, 'add_monthly_schedule'));
        add_action('init', array($this, 'schedule_monthly_report'));
        add_action('yodablack_send_monthly_order_report', array($this, 'send_monthly_order_report'));
        add_action('woocommerce_loaded', array($this, 'init_woocommerce_classes'), 20);
    }

    public function add_monthly_schedule($schedules)
    {
        $schedules['monthly'] = array(
            'interval' => 2592000, // 30 days in seconds
            'display'  => __('Once a month', 'woo-order-report')
        );
        return $schedules;
    }

    public function schedule_monthly_report()
    {
        if (!wp_next_scheduled('yodablack_send_monthly_order_report')) {
            wp_schedule_event(time(), 'monthly', 'yodablack_send_monthly_order_report');
        }
    }

    public function send_monthly_order_report()
    {
        error_log('send_monthly_order_report: Start');

        if (!function_exists('WC')) {
            error_log('WooCommerce is not fully loaded. Aborting report generation.');
            return;
        }

        $date_after = date('Y-m-d', strtotime('first day of last month'));
        $date_before = date('Y-m-d', strtotime('last day of last month'));
    
        $orders = wc_get_orders(array(
            'date_created' => $date_after . '...' . $date_before,
            'status' => 'completed',
            'limit' => -1
        ));

        $report = $this->generate_report($orders);
        $csv_file = $this->create_csv_report($report);
        $this->send_email_report($csv_file);

        error_log('send_monthly_order_report: End');
    }

    private function generate_report($orders)
    {
        $report = array();
        foreach ($orders as $order) {
            $items = $order->get_items();
            foreach ($items as $item) {
                $product = $item->get_product();
                $report[] = array(
                    'order_id' => $order->get_id(),
                    'date'     => $order->get_date_created()->format('Y-m-d'),
                    'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'product'  => $product->get_name(),
                    'price'    => $product->get_price(),
                    'tax'      => $order->get_total_tax(),
                    'total'    => $order->get_total()
                );
            }
        }
        return $report;
    }

    private function create_csv_report($report)
    {
        $upload_dir = wp_upload_dir();
        $report_dir = $upload_dir['basedir'] . '/order-reports/';
        if (!file_exists($report_dir)) {
            mkdir($report_dir, 0755, true);
        }
        $report_file = $report_dir . 'order-report-' . date('Y-m', strtotime('last month')) . '.csv';

        $file = fopen($report_file, 'w');
        fputcsv($file, array('Order ID', 'Date', 'Customer', 'Product', 'Price', 'Tax', 'Total'));
        foreach ($report as $line) {
            fputcsv($file, $line);
        }
        fclose($file);

        return $report_file;
    }

    private function send_email_report($csv_file)
    {
        $to = get_option('admin_email');
        $subject = __('Monthly Order Report', 'woo-order-report');
        $message = __('Please find the attached order report for the past month.', 'woo-order-report');
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $attachments = array($csv_file);

        wp_mail($to, $subject, $message, $headers, $attachments);
    }

    public function init_woocommerce_classes()
    {
        require_once ABSPATH . 'wp-content/plugins/woocommerce/includes/class-wc-order.php';
    }
}

new MonthlyOrderReportEmailer();