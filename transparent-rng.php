<?php
/**
 * Plugin Name: Transparent RNG Generator
 * Description: Transparent random number generator with provably fair seed hashing, admin history, integrity verification endpoint, and restricted access control.
 * Version: 1.6.0
 */

if (!defined('ABSPATH')) exit;

class Transparent_RNG_Plugin {

    const TABLE_NAME = 'rng_results';

    public function __construct() {

        register_activation_hook(__FILE__, array($this, 'activate'));

        add_action('plugins_loaded', array($this, 'maybe_add_user_id_column'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // Shortcodes
        add_shortcode('rng_generator', array($this, 'shortcode_generator'));
        add_shortcode('rng_verify', array($this, 'shortcode_verify'));
        add_shortcode('rng_user_history', array($this, 'shortcode_user_history'));

        // AJAX Handlers
        add_action('wp_ajax_bc_rng_generate', array($this, 'handle_generate'));
        // Note: 'nopriv' hook removed for generate to enforce login check in AJAX too

        add_action('wp_ajax_bc_rng_verify', array($this, 'handle_verify'));
        add_action('wp_ajax_nopriv_bc_rng_verify', array($this, 'handle_verify'));

        add_action('wp_ajax_bc_rng_server_time', array($this, 'handle_server_time'));
        add_action('wp_ajax_nopriv_bc_rng_server_time', array($this, 'handle_server_time'));

        // WooCommerce / Account Integrations
        add_action('init', array($this, 'add_history_endpoint'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_history_menu_item'));
        add_action('woocommerce_account_draw-history_endpoint', array($this, 'history_endpoint_content'));

        // Admin Menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Integrity Endpoint
        add_action('init', array($this, 'add_integrity_endpoint'));
        add_action('template_redirect', array($this, 'handle_integrity_endpoint'));
    }

    /* ---------------------------------------------------------
       ACTIVATION & DB SETUP
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

    public function maybe_add_user_id_column() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $column = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'user_id'));
        if (empty($column)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN user_id BIGINT(20) UNSIGNED NULL AFTER id");
        }
    }

    /* ---------------------------------------------------------
       INTEGRITY CHECK (HASH VERIFICATION)
    --------------------------------------------------------- */

    public function add_integrity_endpoint() {
        add_rewrite_endpoint('integrity', EP_ROOT);
    }

    public function handle_integrity_endpoint() {
        // FIXED: Check if the 'integrity' var is actually present in the active query
        global $wp_query;
        if ( ! isset( $wp_query->query_vars['integrity'] ) ) {
            return;
        }

        // Calculate the SHA-256 hash of THIS exact file on the server
        $file_path = __FILE__;
        $file_hash = hash_file('sha256', $file_path);

        wp_send_json_success(array(
            'file' => basename($file_path),
            'status' => 'active',
            'sha256_hash' => $file_hash,
            // Replace this URL with your actual GitHub repo URL if it changes
            'github_url' => 'https://github.com/Coolcrowe/the-rng-source', 
            'message' => 'Compare this hash with the file in our GitHub repository to verify code integrity.'
        ));
        
        exit;
    }

    /* ---------------------------------------------------------
       ADMIN MENU & PAGE
    --------------------------------------------------------- */

    public function add_admin_menu() {
        add_menu_page(
            'RNG History',
            'RNG History',
            'manage_options',
            'rng-history',
            array($this, 'render_admin_page'),
            'dashicons-randomize',
            6
        );
    }

    public function render_admin_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Handle Deletion
        if (isset($_POST['delete_rng_entry']) && check_admin_referer('delete_rng_entry_nonce')) {
            $id_to_delete = sanitize_text_field($_POST['delete_rng_entry']);
            $wpdb->delete($table_name, array('id' => $id_to_delete));
            echo '<div class="notice notice-success is-dismissible"><p>Entry deleted.</p></div>';
        }

        // Handle Clear All
        if (isset($_POST['clear_all_rng']) && check_admin_referer('clear_all_rng_nonce')) {
            $wpdb->query("TRUNCATE TABLE $table_name");
            echo '<div class="notice notice-success is-dismissible"><p>All history cleared.</p></div>';
        }

        // Pagination setup
        $per_page = 20;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;

        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_pages = ceil($total_items / $per_page);

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">RNG Draw History</h1>
            
            <form method="post" style="display:inline-block; margin-left:10px;" onsubmit="return confirm('Are you sure you want to delete ALL history? This cannot be undone.');">
                <?php wp_nonce_field('clear_all_rng_nonce'); ?>
                <input type="hidden" name="clear_all_rng" value="1">
                <button type="submit" class="button button-secondary">Clear All History</button>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Key (ID)</th>
                        <th>User</th>
                        <th>Result</th>
                        <th>Range</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($results): ?>
                        <?php foreach ($results as $row): 
                            $user_info = $row->user_id ? get_userdata($row->user_id) : false;
                            $user_display = $user_info ? $user_info->user_login . ' (ID: ' . $row->user_id . ')' : 'Guest / Unknown';
                        ?>
                        <tr>
                            <td><code><?php echo esc_html($row->id); ?></code></td>
                            <td><?php echo esc_html($user_display); ?></td>
                            <td><strong><?php echo esc_html($row->result); ?></strong></td>
                            <td><?php echo esc_html($row->min_value . ' - ' . $row->max_value); ?></td>
                            <td><?php echo esc_html($row->created_at); ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Delete this entry?');">
                                    <?php wp_nonce_field('delete_rng_entry_nonce'); ?>
                                    <input type="hidden" name="delete_rng_entry" value="<?php echo esc_attr($row->id); ?>">
                                    <button type="submit" class="button button-small button-link-delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6">No history found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="pagination-links">
                            <?php
                            echo paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total' => $total_pages,
                                'current' => $page
                            ));
                            ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ---------------------------------------------------------
       HELPER: BASE58 KEY GENERATOR
    --------------------------------------------------------- */

    protected function generate_short_key() {
        $bytes = random_bytes(9);
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $base = strlen($alphabet);
        $hex = bin2hex($bytes);
        $num = gmp_init($hex, 16);
        $output = '';
        while (gmp_cmp($num, 0) > 0) {
            $rem = gmp_mod($num, $base);
            $num = gmp_div_q($num, $base);
            $output .= $alphabet[gmp_intval($rem)];
        }
        return $output === '' ? $alphabet[0] : strrev($output);
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
       GENERATOR SHORTCODE (RESTRICTED ACCESS)
    --------------------------------------------------------- */

    public function shortcode_generator() {
        
        // 1. Check if user is logged in
        if (!is_user_logged_in()) {
            return '<div class="bc-rng-wrapper"><div class="bc-rng-card" style="text-align:center;">
                <h2 class="bc-rng-title">Access Restricted</h2>
                <p style="margin-bottom: 1.5rem; color: #555;">To maintain the integrity of our service, account registration is currently by enquiry only.</p>
                
                <p style="margin-bottom: 1.5rem;">Please email us to register your interest:</p>
                
                <a href="mailto:info@the-rng.com" class="bc-rng-button" style="display:inline-block; width:auto; text-decoration:none; padding: 0.75rem 2rem;">info@the-rng.com</a>
                
                <p style="margin-top: 1.5rem; font-size: 0.9rem; color: #888;">Already have an account? <a href="/my-account">Log in here</a>.</p>
            </div></div>';
        }

        ob_start();
        ?>
        <div class="bc-rng-wrapper">
            <div class="bc-rng-card">
                <h2 class="bc-rng-title">Transparent random number generator</h2>
                <p class="bc-rng-subtitle">Generate a cryptographically secure random result with a verifiable key.</p>

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
                        <div><div class="bc-rng-result-label">Final number</div><div id="bc-rng-final-number" class="bc-rng-result-value"></div></div>
                        <div><div class="bc-rng-result-label">Range used</div><div id="bc-rng-range" class="bc-rng-result-value"></div></div>
                        <div><div class="bc-rng-result-label">Verification key</div><div id="bc-rng-key" class="bc-rng-result-value"></div></div>
                        <div><div class="bc-rng-result-label">Seed hash</div><div id="bc-rng-seed-hash" class="bc-rng-result-value bc-rng-mono"></div></div>
                        <div><div class="bc-rng-result-label">Timestamp</div><div id="bc-rng-timestamp" class="bc-rng-result-value"></div></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------
       VERIFY SHORTCODE (OPEN TO ALL)
    --------------------------------------------------------- */

    public function shortcode_verify() {
        ob_start();
        ?>
        <div class="bc-rng-wrapper">
            <div class="bc-rng-card">
                <h2 class="bc-rng-title">Verify a draw</h2>
                <p class="bc-rng-subtitle">Enter a verification key to view the original result, range, seed and hash.</p>

                <form id="bc-rng-verify-form" class="bc-rng-form">
                    <div class="bc-rng-row">
                        <label for="bc_rng_key">Verification key</label>
                        <input type="text" id="bc_rng_key" name="key" required>
                    </div>
                    <button type="submit" class="bc-rng-button">Verify</button>
                </form>

                <div id="bc-rng-verify-status" class="bc-rng-status" aria-live="polite"></div>

                <div id="bc-rng-verify-block" class="bc-rng-result-block bc-rng-hidden">
                    <h3 class="bc-rng-section-title">Stored draw details</h3>
                    <div class="bc-rng-result-grid">
                        <div><div class="bc-rng-result-label">Final number</div><div id="bc-rng-v-final-number" class="bc-rng-result-value"></div></div>
                        <div><div class="bc-rng-result-label">Range used</div><div id="bc-rng-v-range" class="bc-rng-result-value"></div></div>
                        <div><div class="bc-rng-result-label">Seed</div><div id="bc-rng-v-seed" class="bc-rng-result-value bc-rng-mono"></div></div>
                        <div><div class="bc-rng-result-label">Seed hash (SHA-256)</div><div id="bc-rng-v-seed-hash" class="bc-rng-result-value bc-rng-mono"></div></div>
                        <div><div class="bc-rng-result-label">Hash verification</div><div id="bc-rng-v-hash-check" class="bc-rng-result-value"></div></div>
                        <div><div class="bc-rng-result-label">Calculation</div><div id="bc-rng-v-math" class="bc-rng-result-value bc-rng-mono"></div></div>
                        <div><div class="bc-rng-result-label">Timestamp</div><div id="bc-rng-v-timestamp" class="bc-rng-result-value"></div></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }


    /* ---------------------------------------------------------
       GENERATE HANDLER (PROVABLY FAIR + LOGGED IN ONLY)
    --------------------------------------------------------- */

    public function handle_generate() {

        // 1. Security Check: Nonce AND Login
        if (!isset($_POST['bc_rng_nonce']) || !wp_verify_nonce($_POST['bc_rng_nonce'], 'bc_rng_generate')) {
            wp_send_json_error(array('message' => 'Security check failed.'), 400);
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in to generate numbers.'), 403);
        }

        $min = isset($_POST['min']) ? intval($_POST['min']) : null;
        $max = isset($_POST['max']) ? intval($_POST['max']) : null;

        if ($min === null || $max === null) wp_send_json_error(array('message' => 'Both min and max are required.'), 400);
        if ($min >= $max) wp_send_json_error(array('message' => 'Min must be less than max.'), 400);
        if ($min < 0) wp_send_json_error(array('message' => 'Min must be positive.'), 400);
        if ($max - $min > 1000000000) wp_send_json_error(array('message' => 'Range too large.'), 400);

        // 2. Generate Provably Fair Result
        $seed = bin2hex(random_bytes(16));
        $seed_hash = hash('sha256', $seed);
        $hash_prefix = substr($seed_hash, 0, 15);
        $hash_int = hexdec($hash_prefix);
        $range = $max - $min + 1;
        $result = ($hash_int % $range) + $min;

        $id = $this->generate_short_key();
        $created_at = current_time('mysql');
        $user_id = get_current_user_id();

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $inserted = $wpdb->insert(
            $table_name,
            array(
                'id' => $id,
                'user_id' => $user_id,
                'min_value' => $min,
                'max_value' => $max,
                'result' => $result,
                'seed' => $seed,
                'seed_hash' => $seed_hash,
                'created_at' => $created_at,
            ),
            array('%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s')
        );

        if (!$inserted) wp_send_json_error(array('message' => 'Database error.'), 500);

        wp_send_json_success(array(
            'id' => $id,
            'min' => $min,
            'max' => $max,
            'result' => $result,
            'seed_hash' => $seed_hash,
            'timestamp' => $created_at,
        ));
    }


    /* ---------------------------------------------------------
       VERIFY HANDLER (OPEN TO ALL)
    --------------------------------------------------------- */

    public function handle_verify() {
        global $wpdb;
        $key = isset($_POST['key']) ? sanitize_text_field($_POST['key']) : '';

        if (empty($key)) wp_send_json_error(['message' => 'Key required.'], 400);

        $table = $wpdb->prefix . self::TABLE_NAME;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %s", $key), ARRAY_A);

        if (!$row) wp_send_json_error(['message' => 'Key not found.'], 404);

        wp_send_json_success(array(
            'id' => $row['id'],
            'min' => intval($row['min_value']),
            'max' => intval($row['max_value']),
            'result' => intval($row['result']),
            'seed' => $row['seed'],
            'seed_hash' => $row['seed_hash'],
            'timestamp' => $row['created_at'],
        ));
    }

    /* ---------------------------------------------------------
       SERVER TIME
    --------------------------------------------------------- */

    public function handle_server_time() {
        wp_send_json_success(array('server_time' => current_time('mysql')));
    }

    /* ---------------------------------------------------------
       USER ACCOUNT HISTORY
    --------------------------------------------------------- */

    public function shortcode_user_history() {
        if (!is_user_logged_in()) return '<p>Login to view history.</p>';

        global $wpdb;
        $uid = get_current_user_id();
        $table = $wpdb->prefix . self::TABLE_NAME;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, result, min_value, max_value, created_at FROM $table WHERE user_id = %d ORDER BY created_at DESC", $uid));

        if (!$rows) return '<p>No draws found.</p>';

        ob_start();
        echo '<table class="bc-rng-history-table">';
        echo '<tr><th>Key</th><th>Result</th><th>Range</th><th>Date</th></tr>';
        foreach ($rows as $r) {
            echo '<tr><td>' . esc_html($r->id) . '</td><td>' . esc_html($r->result) . '</td><td>' . esc_html($r->min_value . "-" . $r->max_value) . '</td><td>' . esc_html($r->created_at) . '</td></tr>';
        }
        echo '</table>';
        return ob_get_clean();
    }

    // WooCommerce Endpoints
    public function add_history_endpoint() { add_rewrite_endpoint('draw-history', EP_ROOT | EP_PAGES); }
    public function add_history_menu_item($items) {
        $new = [];
        foreach ($items as $k => $v) {
            if ($k === 'customer-logout') $new['draw-history'] = 'My Draw History';
            $new[$k] = $v;
        }
        return $new;
    }
    public function history_endpoint_content() { echo do_shortcode('[rng_user_history]'); }
}

// Global callback for server time to ensure it works for both priv/nopriv
function bc_rng_server_time_callback() {
    wp_send_json_success(['server_time' => current_time('mysql')]);
}

new Transparent_RNG_Plugin();