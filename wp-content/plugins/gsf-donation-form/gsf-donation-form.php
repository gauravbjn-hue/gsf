<?php
/**
 * Plugin Name: GSF Donation Form
 * Description: Donation form with Razorpay payments, donor storage, and receipt emails.
 * Version: 1.0.0
 * Author: GSF
 */

if (!defined('ABSPATH')) {
    exit;
}

class GSF_Donation_Form {
    private const OPTION_KEY = 'gsf_donation_form_options';
    private const NONCE_ACTION = 'gsf_donation_form_nonce';
    private const AJAX_ACTION_CREATE_ORDER = 'gsf_create_razorpay_order';
    private const AJAX_ACTION_CONFIRM_PAYMENT = 'gsf_confirm_razorpay_payment';

    public function __construct() {
        add_action('init', [$this, 'register_donation_post_type']);
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_shortcode('gsf_donation_form', [$this, 'render_donation_form']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);

        add_action('wp_ajax_' . self::AJAX_ACTION_CREATE_ORDER, [$this, 'handle_create_order']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION_CREATE_ORDER, [$this, 'handle_create_order']);
        add_action('wp_ajax_' . self::AJAX_ACTION_CONFIRM_PAYMENT, [$this, 'handle_confirm_payment']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION_CONFIRM_PAYMENT, [$this, 'handle_confirm_payment']);
    }

    public function register_donation_post_type(): void {
        register_post_type('gsf_donation', [
            'labels' => [
                'name' => 'Donations',
                'singular_name' => 'Donation',
            ],
            'public' => false,
            'show_ui' => true,
            'menu_icon' => 'dashicons-heart',
            'supports' => ['title', 'custom-fields'],
        ]);
    }

    public function register_settings_page(): void {
        add_options_page(
            'GSF Donation Form',
            'GSF Donation Form',
            'manage_options',
            'gsf-donation-form',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void {
        register_setting('gsf_donation_form', self::OPTION_KEY);

        add_settings_section('gsf_donation_form_main', 'Razorpay Settings', '__return_null', 'gsf-donation-form');

        $fields = [
            'key_id' => 'Razorpay Key ID',
            'key_secret' => 'Razorpay Key Secret',
            'admin_email' => 'Admin Receipt Email',
            'currency' => 'Currency (e.g. INR)',
            'company_name' => 'Company/Trust Name',
        ];

        foreach ($fields as $field => $label) {
            add_settings_field(
                $field,
                $label,
                [$this, 'render_text_field'],
                'gsf-donation-form',
                'gsf_donation_form_main',
                ['label_for' => $field]
            );
        }
    }

    public function render_text_field(array $args): void {
        $options = $this->get_options();
        $field = $args['label_for'];
        $value = isset($options[$field]) ? esc_attr($options[$field]) : '';
        $type = $field === 'key_secret' ? 'password' : 'text';

        printf(
            '<input type="%s" id="%s" name="%s[%s]" value="%s" class="regular-text" />',
            esc_attr($type),
            esc_attr($field),
            esc_attr(self::OPTION_KEY),
            esc_attr($field),
            $value
        );
    }

    public function render_settings_page(): void {
        echo '<div class="wrap">';
        echo '<h1>GSF Donation Form</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('gsf_donation_form');
        do_settings_sections('gsf-donation-form');
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public function register_assets(): void {
        wp_register_script(
            'gsf-razorpay-checkout',
            'https://checkout.razorpay.com/v1/checkout.js',
            [],
            null,
            true
        );
        wp_register_script(
            'gsf-donation-form',
            plugins_url('assets/donation-form.js', __FILE__),
            ['gsf-razorpay-checkout'],
            '1.0.0',
            true
        );
        wp_register_style(
            'gsf-donation-form',
            plugins_url('assets/donation-form.css', __FILE__),
            [],
            '1.0.0'
        );
    }

    public function render_donation_form(): string {
        $options = $this->get_options();
        $key_id = $options['key_id'] ?? '';
        $currency = $options['currency'] ?? 'INR';
        $company_name = $options['company_name'] ?? 'Donation';

        wp_enqueue_script('gsf-donation-form');
        wp_enqueue_style('gsf-donation-form');

        wp_localize_script('gsf-donation-form', 'GSFDonationForm', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'keyId' => $key_id,
            'currency' => $currency,
            'companyName' => $company_name,
        ]);

        ob_start();
        ?>
        <form class="gsf-donation-form" data-currency="<?php echo esc_attr($currency); ?>">
            <div class="gsf-field">
                <label for="gsf-donor-name">Full Name</label>
                <input type="text" id="gsf-donor-name" name="donor_name" required />
            </div>
            <div class="gsf-field">
                <label for="gsf-donor-email">Email</label>
                <input type="email" id="gsf-donor-email" name="donor_email" required />
            </div>
            <div class="gsf-field">
                <label for="gsf-donor-phone">Phone</label>
                <input type="tel" id="gsf-donor-phone" name="donor_phone" required />
            </div>
            <div class="gsf-field">
                <label for="gsf-donation-amount">Donation Amount</label>
                <input type="number" id="gsf-donation-amount" name="donation_amount" min="1" step="1" required />
            </div>
            <div class="gsf-field">
                <label for="gsf-donor-message">Message (optional)</label>
                <textarea id="gsf-donor-message" name="donor_message" rows="3"></textarea>
            </div>
            <button type="submit" class="gsf-submit">Donate Now</button>
            <div class="gsf-status" role="status" aria-live="polite"></div>
        </form>
        <?php
        return ob_get_clean();
    }

    public function handle_create_order(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $amount = isset($_POST['amount']) ? absint($_POST['amount']) : 0;
        $currency = isset($_POST['currency']) ? sanitize_text_field($_POST['currency']) : 'INR';
        $donor_name = isset($_POST['donor_name']) ? sanitize_text_field($_POST['donor_name']) : '';
        $donor_email = isset($_POST['donor_email']) ? sanitize_email($_POST['donor_email']) : '';
        $donor_phone = isset($_POST['donor_phone']) ? sanitize_text_field($_POST['donor_phone']) : '';
        $donor_message = isset($_POST['donor_message']) ? sanitize_textarea_field($_POST['donor_message']) : '';

        if ($amount <= 0 || empty($donor_name) || empty($donor_email) || empty($donor_phone)) {
            wp_send_json_error(['message' => 'Please complete all required fields.']);
        }

        $options = $this->get_options();
        $key_id = $options['key_id'] ?? '';
        $key_secret = $options['key_secret'] ?? '';

        if (empty($key_id) || empty($key_secret)) {
            wp_send_json_error(['message' => 'Razorpay settings are missing.']);
        }

        $order_payload = [
            'amount' => $amount * 100,
            'currency' => $currency,
            'receipt' => 'gsf-' . wp_generate_uuid4(),
            'payment_capture' => 1,
        ];

        $response = wp_remote_post('https://api.razorpay.com/v1/orders', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($key_id . ':' . $key_secret),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($order_payload),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200 || empty($body['id'])) {
            wp_send_json_error(['message' => 'Unable to create Razorpay order.']);
        }

        $pending_data = [
            'donor_name' => $donor_name,
            'donor_email' => $donor_email,
            'donor_phone' => $donor_phone,
            'donor_message' => $donor_message,
            'amount' => $amount,
            'currency' => $currency,
            'order_id' => $body['id'],
        ];

        wp_send_json_success([
            'order_id' => $body['id'],
            'amount' => $amount,
            'currency' => $currency,
            'donor' => $pending_data,
        ]);
    }

    public function handle_confirm_payment(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $order_id = isset($_POST['order_id']) ? sanitize_text_field($_POST['order_id']) : '';
        $payment_id = isset($_POST['payment_id']) ? sanitize_text_field($_POST['payment_id']) : '';
        $signature = isset($_POST['signature']) ? sanitize_text_field($_POST['signature']) : '';
        $donor_name = isset($_POST['donor_name']) ? sanitize_text_field($_POST['donor_name']) : '';
        $donor_email = isset($_POST['donor_email']) ? sanitize_email($_POST['donor_email']) : '';
        $donor_phone = isset($_POST['donor_phone']) ? sanitize_text_field($_POST['donor_phone']) : '';
        $donor_message = isset($_POST['donor_message']) ? sanitize_textarea_field($_POST['donor_message']) : '';
        $amount = isset($_POST['amount']) ? absint($_POST['amount']) : 0;
        $currency = isset($_POST['currency']) ? sanitize_text_field($_POST['currency']) : 'INR';

        if (empty($order_id) || empty($payment_id) || empty($signature)) {
            wp_send_json_error(['message' => 'Missing payment details.']);
        }

        $options = $this->get_options();
        $key_secret = $options['key_secret'] ?? '';

        if (empty($key_secret)) {
            wp_send_json_error(['message' => 'Razorpay key secret not configured.']);
        }

        $expected_signature = hash_hmac('sha256', $order_id . '|' . $payment_id, $key_secret);
        if (!hash_equals($expected_signature, $signature)) {
            wp_send_json_error(['message' => 'Payment verification failed.']);
        }

        $post_id = wp_insert_post([
            'post_type' => 'gsf_donation',
            'post_title' => $donor_name . ' - ' . current_time('mysql'),
            'post_status' => 'publish',
        ]);

        if ($post_id) {
            update_post_meta($post_id, 'donor_name', $donor_name);
            update_post_meta($post_id, 'donor_email', $donor_email);
            update_post_meta($post_id, 'donor_phone', $donor_phone);
            update_post_meta($post_id, 'donor_message', $donor_message);
            update_post_meta($post_id, 'amount', $amount);
            update_post_meta($post_id, 'currency', $currency);
            update_post_meta($post_id, 'razorpay_order_id', $order_id);
            update_post_meta($post_id, 'razorpay_payment_id', $payment_id);
        }

        $this->send_receipt_emails([
            'donor_name' => $donor_name,
            'donor_email' => $donor_email,
            'donor_phone' => $donor_phone,
            'donor_message' => $donor_message,
            'amount' => $amount,
            'currency' => $currency,
            'payment_id' => $payment_id,
            'order_id' => $order_id,
        ]);

        wp_send_json_success(['message' => 'Donation confirmed.']);
    }

    private function send_receipt_emails(array $data): void {
        $options = $this->get_options();
        $admin_email = !empty($options['admin_email']) ? $options['admin_email'] : get_option('admin_email');
        $company_name = $options['company_name'] ?? 'Donation';

        $subject = sprintf('Thank you for your donation to %s', $company_name);
        $message_lines = [
            'Hi ' . $data['donor_name'] . ',',
            '',
            'Thank you for your donation! Here is your receipt:',
            'Amount: ' . $data['amount'] . ' ' . $data['currency'],
            'Payment ID: ' . $data['payment_id'],
            'Order ID: ' . $data['order_id'],
        ];

        if (!empty($data['donor_message'])) {
            $message_lines[] = 'Message: ' . $data['donor_message'];
        }

        $message_lines[] = '';
        $message_lines[] = 'Regards,';
        $message_lines[] = $company_name;

        $pdf_attachment = $this->generate_pdf_receipt($data, $company_name);
        $attachments = $pdf_attachment ? [$pdf_attachment] : [];

        wp_mail(
            $data['donor_email'],
            $subject,
            implode("\n", $message_lines),
            [],
            $attachments
        );

        $admin_subject = sprintf('New donation received via %s', $company_name);
        $admin_message = implode("\n", [
            'A new donation has been received.',
            'Donor: ' . $data['donor_name'],
            'Email: ' . $data['donor_email'],
            'Phone: ' . $data['donor_phone'],
            'Amount: ' . $data['amount'] . ' ' . $data['currency'],
            'Payment ID: ' . $data['payment_id'],
            'Order ID: ' . $data['order_id'],
        ]);

        if (!empty($data['donor_message'])) {
            $admin_message .= "\nMessage: " . $data['donor_message'];
        }

        wp_mail($admin_email, $admin_subject, $admin_message, [], $attachments);
    }

    private function get_options(): array {
        $options = get_option(self::OPTION_KEY, []);
        return is_array($options) ? $options : [];
    }

    private function generate_pdf_receipt(array $data, string $company_name): ?string {
        $upload_dir = wp_upload_dir();
        if (empty($upload_dir['path']) || !is_dir($upload_dir['path'])) {
            return null;
        }

        $filename = sprintf(
            'donation-receipt-%s.pdf',
            preg_replace('/[^a-zA-Z0-9_-]/', '', $data['payment_id'])
        );
        $file_path = trailingslashit($upload_dir['path']) . $filename;

        $lines = [
            $company_name . ' Donation Receipt',
            'Donor: ' . $data['donor_name'],
            'Email: ' . $data['donor_email'],
            'Phone: ' . $data['donor_phone'],
            'Amount: ' . $data['amount'] . ' ' . $data['currency'],
            'Payment ID: ' . $data['payment_id'],
            'Order ID: ' . $data['order_id'],
            'Date: ' . current_time('mysql'),
        ];

        if (!empty($data['donor_message'])) {
            $lines[] = 'Message: ' . $data['donor_message'];
        }

        $pdf_content = $this->build_simple_pdf($lines);

        if (false === file_put_contents($file_path, $pdf_content)) {
            return null;
        }

        return $file_path;
    }

    private function build_simple_pdf(array $lines): string {
        $escaped_lines = array_map(
            function ($line) {
                return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            },
            $lines
        );

        $y = 760;
        $text_blocks = [];
        foreach ($escaped_lines as $line) {
            $text_blocks[] = sprintf('1 0 0 1 50 %d Tm (%s) Tj', $y, $line);
            $y -= 18;
        }

        $stream = "BT\n/F1 12 Tf\n" . implode("\n", $text_blocks) . "\nET";
        $stream_length = strlen($stream);

        $objects = [];
        $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj";
        $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj";
        $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj";
        $objects[] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj";
        $objects[] = "5 0 obj\n<< /Length $stream_length >>\nstream\n$stream\nendstream\nendobj";

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object . "\n";
        }

        $xref_position = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref_position\n%%EOF";

        return $pdf;
    }
}

new GSF_Donation_Form();
