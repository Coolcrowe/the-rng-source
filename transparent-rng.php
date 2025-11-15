<?php
/**
 * Plugin Name: Transparent RNG Generator
 * Description: Transparent random number generator with seed hashing, base58 verification keys, draw verification, WooCommerce history and server time display.
 * Version: 1.3.0
 */

if (!defined('ABSPATH')) exit;

class Transparent_RNG_Plugin {

    const TABLE_NAME = 'rng_results';

    public function __construct() {

        register_activation_hook(__FILE__, array($this, 'activate'));

        add_action('plugins_loaded', array($this, 'maybe_add_user_id_column'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        add_shortcode('rng_generator', array($this, 'shortcode_generator'));
        add_shortcode('rng_verify', array($this, 'shortcode_verify'));
        add_shortcode('rng_user_history', array($this, 'shortcode_user_history'));

        add_action('wp_ajax_bc_rng_generate', array($this, 'handle_generate'));
        add_action('wp_ajax_nopriv_bc_rng_generate', array($this, 'handle_generate'));

        add_action('wp_ajax_bc_rng_verify', array($this, 'handle_verify'));
        add_action('wp_ajax_nopriv_bc_rng_verify', array($this, 'handle_verify'));

        // Server time AJAX (for real-time clock)
        add_action('wp_ajax_bc_rng_server_time', array($this, 'handle_server_time'));
        add_action('wp_ajax_nopriv_bc_rng_server_time', array($this, 'handle_server_time'));

        add_action('init', array($this, 'add_history_endpoint'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_history_menu_item'));
        add_action('woocommerce_account_draw-history_endpoint', array($this, 'history_endpoint_content'));
    }

    /* ---------------------------------------------------------
       ACTIVATION: CREATE TABLE
    --------------------------------------------------------- */

    public function activate() {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id VARCHAR(64) NOT NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            min_value INT NOT NULL,
            max_value INT NOT NULL,
            result INT NOT NULL,
            seed VARCHAR(64) NOT NULL,
            seed_hash VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /* ---------------------------------------------------------
       AUTO ADD user_id COLUMN IF MISSING
    --------------------------------------------------------- */

    public function maybe_add_user_id_column() {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        $column = $wpdb->get_results(
            $wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'user_id')
        );

        if (empty($column)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN user_id BIGINT(20) UNSIGNED NULL AFTER id");
        }
    }

    /* ---------------------------------------------------------
       BASE58 KEY GENERATOR
    --------------------------------------------------------- */

    protected function generate_short_key() {
        // 72 bits entropy
        $bytes = random_bytes(9);
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $base = strlen($alphabet);

        // Convert binary to a big integer
        $hex = bin2hex($bytes);
        $num = gmp_init($hex, 16);

        $output = '';
        while (gmp_cmp($num, 0) > 0) {
            $rem = gmp_mod($num, $base);
            $num = gmp_div_q($num, $base);
            $output .= $alphabet[gmp_intval($rem)];
        }

        if ($output === '') {
            $output = $alphabet[0];
        }

        return strrev($output);
    }

    /* ---------------------------------------------------------
       ASSETS
    --------------------------------------------------------- */

    public function enqueue_assets() {
        $url = plugin_dir_url(__FILE__);

        wp_enqueue_style('rng-css', $url . 'assets/css/rng-frontend.css');
        wp_enqueue_script('rng-js', $url . 'assets/js/rng-frontend.js', array(), time(), true);

        wp_localize_script('rng-js', 'bc_rng_config', array(
            'ajax_url'          => admin_url('admin-ajax.php'),
            'generate_action'   => 'bc_rng_generate',
            'verify_action'     => 'bc_rng_verify',
            'server_time_action'=> 'bc_rng_server_time',
        ));
    }

    /* ---------------------------------------------------------
       GENERATOR SHORTCODE
    --------------------------------------------------------- */

    public function shortcode_generator() {
        ob_start();
        ?>

        <div class="bc-rng-wrapper">
            <div class="bc-rng-card">

                <h2 class="bc-rng-title">Transparent random number generator</h2>
                <p class="bc-rng-subtitle">
                    Generate a cryptographically secure random result with a verifiable key.
                </p>

                <div class="bc-rng-clock-row">
                    <span class="bc-rng-clock-label">Server time:</span>
                    <span id="bc-rng-server-clock" class="bc-rng-clock-value">Loading…</span>
                </div>

                <form id="bc-rng-form" class="bc-rng-form">
                    <?php wp_nonce_field('bc_rng_generate', 'bc_rng_nonce'); ?>

                    <div class="bc-rng-row">
                        <label for="bc_rng_min">Minimum</label>
                        <input type="number" id="bc_rng_min" name="min" required>
                    </div>

                    <div class="bc-rng-row">
                        <label for="bc_rng_max">Maximum</label>
                        <input type="number" id="bc_rng_max" name="max" required>
                    </div>

                    <button type="submit" class="bc-rng-button">Generate</button>
                </form>

                <div id="bc-rng-status" class="bc-rng-status"></div>

                <div id="bc-rng-animation" class="bc-rng-animation">--</div>

                <div id="bc-rng-result-block" class="bc-rng-result-block bc-rng-hidden">

                    <h3>Result details</h3>

                    <div class="bc-rng-result-grid">
                        <div>
                            <div class="bc-rng-result-label">Final number</div>
                            <div id="bc-rng-final-number" class="bc-rng-result-value"></div>
                        </div>
                        <div>
                            <div class="bc-rng-result-label">Range used</div>
                            <div id="bc-rng-range" class="bc-rng-result-value"></div>
                        </div>
                        <div>
                            <div class="bc-rng-result-label">Verification key</div>
                            <div id="bc-rng-key" class="bc-rng-result-value"></div>
                        </div>
                        <div>
                            <div class="bc-rng-result-label">Seed hash</div>
                            <div id="bc-rng-seed-hash" class="bc-rng-result-value bc-rng-mono"></div>
                        </div>
                        <div>
                            <div class="bc-rng-result-label">Timestamp</div>
                            <div id="bc-rng-timestamp" class="bc-rng-result-value"></div>
                        </div>
                    </div>

                </div>

            </div>
        </div>

        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------
       VERIFY SHORTCODE
    --------------------------------------------------------- */

    public function shortcode_verify() {
        ob_start();
        ?>

        <div class="bc-rng-wrapper">
            <div class="bc-rng-card">

                <h2 class="bc-rng-title">Verify a draw</h2>
                <p class="bc-rng-subtitle">
                    Enter a verification key to view the original result, range, seed and hash.
                </p>

                <form id="bc-rng-verify-form" class="bc-rng-form">
                    <div class="bc-rng-row">
                        <label for="bc_rng_key">Verification key</label>
                        <input type="text" id="bc_rng_key" name="key" required>
                    </div>
                    <button type="submit" class="bc-rng-button">Verify</button>
                </form>

                <div id="bc-rng-verify-status" class="bc-rng-status"></div>

                <div id="bc-rng-verify-block" class="bc-rng-result-block bc-rng-hidden">

                    <h3>Stored draw details</h3>

                    <div class="bc-rng-result-grid">
                        <div>
                            <div class="bc-rng-result-label">Final number</div>
                            <div id="bc-rng-v-final-number" class="bc-rng-result-value"></div>
                        </div>
                        <div>
                            <div class="bc-rng-result-label">Range used</div>
                            <div id="bc-rng-v-range" class="bc-rng-result-value"></div>
                        </div>
                        <div>
                            <div class="bc-rng-result-label">Seed</div>
                            <div id="bc-rng-v-seed" class="bc-rng-result-value bc-rng-mono"></div>
                        </div>
                        <div>
                            <div class="bc-rng-result-label">Seed hash</div>
                            <div id="bc-rng-v-seed-hash" class="bc-rng-result-value bc-rng-mono"></div>
                        </div>
                        <div>
                            <div class="bc-rng-result-label">Hash verification</div>
                            <div id="bc-rng-v-hash-check" class="bc-rng-result-value"></div>
                        </div>
                        <div>
                            <div class="bc-rng-result-label">Timestamp</div>
                            <div id="bc-rng-v-timestamp" class="bc-rng-result-value"></div>
                        </div>
                    </div>

                </div>

            </div>
        </div>

        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------
       GENERATE HANDLER
    --------------------------------------------------------- */

    public function handle_generate() {

        if (!isset($_POST['bc_rng_nonce']) || !wp_verify_nonce($_POST['bc_rng_nonce'], 'bc_rng_generate')) {
            wp_send_json_error(['message' => 'Security error, please refresh.'], 400);
        }

        $min = isset($_POST['min']) ? intval($_POST['min']) : null;
        $max = isset($_POST['max']) ? intval($_POST['max']) : null;

        if ($min === null || $max === null) {
            wp_send_json_error(['message' => 'Both minimum and maximum are required.'], 400);
        }

        if ($min >= $max) {
            wp_send_json_error(['message' => 'Minimum must be less than maximum.'], 400);
        }

        if ($min < 0) {
            wp_send_json_error(['message' => 'Minimum must be at least 0.'], 400);
        }

        if ($max - $min > 1000000000) {
            wp_send_json_error(['message' => 'Range too large. Please choose a smaller gap.'], 400);
        }

        try {
            $result = random_int($min, $max);
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Random number generation failed.'], 500);
        }

        $seed      = bin2hex(random_bytes(16));
        $seed_hash = hash('sha256', $seed);
        $id        = $this->generate_short_key();
        $created_at = current_time('mysql');
        $user_id   = get_current_user_id();

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $wpdb->insert($table, array(
            'id'         => $id,
            'user_id'    => $user_id ? $user_id : 0,
            'min_value'  => $min,
            'max_value'  => $max,
            'result'     => $result,
            'seed'       => $seed,
            'seed_hash'  => $seed_hash,
            'created_at' => $created_at,
        ));

        wp_send_json_success(array(
            'id'         => $id,
            'result'     => $result,
            'min'        => $min,
            'max'        => $max,
            'seed_hash'  => $seed_hash,
            'timestamp'  => $created_at,
        ));
    }

    /* ---------------------------------------------------------
       VERIFY HANDLER
    --------------------------------------------------------- */

    public function handle_verify() {
        global $wpdb;

        $key = isset($_POST['key']) ? sanitize_text_field($_POST['key']) : '';

        if (empty($key)) {
            wp_send_json_error(['message' => 'Verification key is required.'], 400);
        }

        $table = $wpdb->prefix . self::TABLE_NAME;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %s", $key),
            ARRAY_A
        );

        if (!$row) {
            wp_send_json_error(['message' => 'No result found for that key.'], 404);
        }

        wp_send_json_success(array(
            'id'        => $row['id'],
            'min'       => intval($row['min_value']),
            'max'       => intval($row['max_value']),
            'result'    => intval($row['result']),
            'seed'      => $row['seed'],
            'seed_hash' => $row['seed_hash'],
            'timestamp' => $row['created_at'],
        ));
    }

    /* ---------------------------------------------------------
       SERVER TIME HANDLER (for front-end clock)
    --------------------------------------------------------- */

    public function handle_server_time() {
        wp_send_json_success(array(
            'server_time' => current_time('mysql'),
        ));
    }

    /* ---------------------------------------------------------
       DRAW HISTORY SHORTCODE
    --------------------------------------------------------- */

    public function shortcode_user_history() {

        if (!is_user_logged_in()) {
            return '<p>You must be logged in to view your draw history.</p>';
        }

        global $wpdb;

        $uid = get_current_user_id();
        $table = $wpdb->prefix . self::TABLE_NAME;

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id, result, min_value, max_value, created_at
                FROM $table
                WHERE user_id = %d
                ORDER BY created_at DESC", $uid)
        );

        if (!$rows) {
            return '<p>No previous draws found.</p>';
        }

        ob_start();
        echo '<table class="bc-rng-history-table">';
        echo '<tr><th>Key</th><th>Result</th><th>Range</th><th>Date</th></tr>';

        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td>' . esc_html($r->id) . '</td>';
            echo '<td>' . esc_html($r->result) . '</td>';
            echo '<td>' . esc_html($r->min_value . " to " . $r->max_value) . '</td>';
            echo '<td>' . esc_html($r->created_at) . '</td>';
            echo '</tr>';
        }

        echo '</table>';
        return ob_get_clean();
    }

    /* ---------------------------------------------------------
       WOO ENDPOINT
    --------------------------------------------------------- */

    public function add_history_endpoint() {
        add_rewrite_endpoint('draw-history', EP_ROOT | EP_PAGES);
    }

    public function add_history_menu_item($items) {
        $new = array();
        foreach ($items as $key => $label) {
            if ($key === 'customer-logout') {
                $new['draw-history'] = 'My Draw History';
            }
            $new[$key] = $label;
        }
        return $new;
    }

    public function history_endpoint_content() {
        echo do_shortcode('[rng_user_history]');
    }
}
add_action('wp_ajax_bc_rng_server_time', 'bc_rng_server_time_callback');
add_action('wp_ajax_nopriv_bc_rng_server_time', 'bc_rng_server_time_callback');
function bc_rng_server_time_callback() {
    wp_send_json_success([
        'server_time' => current_time('mysql')
    ]);
}

new Transparent_RNG_Plugin();
