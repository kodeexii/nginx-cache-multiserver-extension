<?php
/**
 * Plugin Name:       Nginx Cache Multiserver Extension
 * Plugin URI:        https://github.com/kodeexii/nginx-cache-multiserver-extension
 * Description:       A smart, cluster-aware extension to synchronize Nginx cache purges across multiple servers behind a load balancer. Uses REST API. Inspired and co-designed by Hadee Roslan.
 * Version:           1.0.1
 * Author:            Al-Hadee Mohd Roslan & Mat Gem
 * Author URI:        https://hadeeroslan.my
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       nginx-cache-multiserver-extension
 *
 * @since             28 Jun 2025 - First Official Release
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =================================================================================
// BAHAGIAN PENGEMAS KINI AUTOMATIK DARI GITHUB
// =================================================================================
require dirname( __FILE__ ) . '/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/kodeexii/nginx-cache-multiserver-extension/', // DIKEMAS KINI
    __FILE__,
    'nginx-cache-multiserver-extension'
);
// (Pilihan) Jika repo private, nyahkomen baris di bawah dan masukkan token
// $myUpdateChecker->setAuthentication('ghp_YourGitHubPersonalAccessToken');


// =================================================================================
// BAHAGIAN PEMERIKSAAN KEBERGANTUNGAN (DEPENDENCY CHECK)
// =================================================================================
function ncme_check_dependencies() {
    if (!function_exists('is_plugin_active')) { include_once(ABSPATH . 'wp-admin/includes/plugin.php'); }
    $is_dependency_active = is_plugin_active('nginx-cache/nginx-cache.php') || is_plugin_active('nginx-helper/nginx-helper.php');
    if (!$is_dependency_active) { add_action('admin_notices', 'ncme_show_dependency_notice'); }
}
add_action('admin_init', 'ncme_check_dependencies');
function ncme_show_dependency_notice() {
    ?>
    <div class="notice notice-error is-dismissible"><p><strong>Amaran dari Nginx Cache Multiserver Extension:</strong> Plugin ini memerlukan sama ada plugin <strong>'Nginx Cache'</strong> atau <strong>'Nginx Helper'</strong> untuk dipasang dan diaktifkan.</p></div>
    <?php
}

// =================================================================================
// BAHAGIAN PENGHANTAR (TRIGGER VIA REST API)
// =================================================================================
function ncme_trigger_remote_purge_via_api() {
    $options = get_option('ncme_settings');
    if (empty($options['cluster_ips']) || empty($options['public_hostname']) || empty($options['secret_token'])) { return; }

    $my_ip = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : null;
    if (!$my_ip) { return; }

    $cluster_ips = array_filter(array_map('trim', explode("\n", trim($options['cluster_ips']))));
    if (empty($cluster_ips)) { return; }

    $public_hostname = $options['public_hostname'];
    $secret_token    = $options['secret_token'];
    $host_header     = parse_url($public_hostname, PHP_URL_HOST);
    $scheme          = parse_url($public_hostname, PHP_URL_SCHEME);

    foreach ($cluster_ips as $remote_ip) {
        if ($remote_ip !== $my_ip) {
            $remote_url = $scheme . '://' . $remote_ip . '/wp-json/ncme/v1/purge';
            $headers = ['Host' => $host_header, 'X-Purge-Token' => $secret_token];
            wp_remote_post($remote_url, ['headers' => $headers, 'blocking' => false, 'sslverify' => false]);
        }
    }
}
add_action('nginx_cache_zone_purged', 'ncme_trigger_remote_purge_via_api');
add_action('nginx_helper_purge_all', 'ncme_trigger_remote_purge_via_api');

// =================================================================================
// BAHAGIAN PENERIMA (CUSTOM REST API ENDPOINT)
// =================================================================================
function ncme_register_rest_routes() {
    register_rest_route('ncme/v1', '/purge', ['methods' => 'POST', 'callback' => 'ncme_handle_purge_request', 'permission_callback' => 'ncme_secret_token_permissions_check']);
}
add_action('rest_api_init', 'ncme_register_rest_routes');

function ncme_secret_token_permissions_check($request) {
    $options = get_option('ncme_settings');
    $stored_token = isset($options['secret_token']) ? $options['secret_token'] : '';
    if (empty($stored_token)) { return false; }
    $received_token = $request->get_header('x_purge_token');
    return hash_equals($stored_token, $received_token);
}

function ncme_handle_purge_request() {
    global $wp_filesystem;
    if (empty($wp_filesystem)) { require_once(ABSPATH . '/wp-admin/includes/file.php'); WP_Filesystem(); }
    $cache_path = get_option('nginx_cache_path');
    if (!$cache_path || !is_dir($cache_path)) { return new WP_Error('no_path', 'Nginx cache path not found.', ['status' => 500]); }
    $wp_filesystem->rmdir($cache_path, true);
    $wp_filesystem->mkdir($cache_path);
    return new WP_REST_Response(['success' => true, 'message' => 'Cache purged.'], 200);
}

// =================================================================================
// BAHAGIAN HALAMAN TETAPAN (SETTINGS PAGE)
// =================================================================================
const NCME_PAGE_SLUG = 'nginx-cache-multiserver-extension';
const NCME_OPTION_GROUP = 'ncme_settings_group';

function ncme_add_admin_menu() { add_options_page('Nginx Cache Multiserver', 'NC Multiserver', 'manage_options', NCME_PAGE_SLUG, 'ncme_settings_page_html'); }
add_action('admin_menu', 'ncme_add_admin_menu');

function ncme_settings_init() {
    register_setting(NCME_OPTION_GROUP, 'ncme_settings');
    add_settings_section('ncme_settings_section', 'Konfigurasi Kluster', null, NCME_PAGE_SLUG);
    add_settings_field('ncme_public_hostname', 'URL / Nama Hos Awam', 'ncme_render_field', NCME_PAGE_SLUG, 'ncme_settings_section', ['id' => 'public_hostname', 'type' => 'text']);
    add_settings_field('ncme_cluster_ips', 'Senarai IP Server Kluster', 'ncme_render_field', NCME_PAGE_SLUG, 'ncme_settings_section', ['id' => 'cluster_ips', 'type' => 'textarea']);
    add_settings_field('ncme_secret_token', 'Kunci Rahsia Penyegerakan', 'ncme_render_field', NCME_PAGE_SLUG, 'ncme_settings_section', ['id' => 'secret_token', 'type' => 'text']);
}
add_action('admin_init', 'ncme_settings_init');

function ncme_render_field($args) {
    $options = get_option('ncme_settings');
    $value = isset($options[$args['id']]) ? $options[$args['id']] : '';
    if ($args['type'] === 'textarea') {
        echo '<textarea id="' . esc_attr($args['id']) . '" name="ncme_settings[' . esc_attr($args['id']) . ']" rows="5" class="large-text">' . esc_textarea($value) . '</textarea>';
        echo '<p class="description">Masukkan setiap IP server dalam kluster pada baris yang baru.</p>';
    } else {
        echo '<input type="text" id="' . esc_attr($args['id']) . '" name="ncme_settings[' . esc_attr($args['id']) . ']" value="' . esc_attr($value) . '" class="regular-text">';
    }
    if ($args['id'] === 'secret_token') {
        echo '<p class="description">Cipta satu rantaian rawak yang panjang dan gunakan kunci yang <strong>sama</strong> di semua server.</p>';
    }
}

function ncme_settings_page_html() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?> Settings</h1>
        <p>Masukkan konfigurasi kluster. Tetapan ini sepatutnya <strong>sama di semua server</strong>.</p>
        <form action="options.php" method="post">
            <?php settings_fields(NCME_OPTION_GROUP); do_settings_sections(NCME_PAGE_SLUG); submit_button('Save Settings'); ?>
        </form>
        <hr>
        <h2>Setup Instructions</h2>
        <ol>
            <li>Cipta satu kunci rahsia yang panjang dan rawak (cth: guna <a href="https://www.random.org/strings/?num=1&len=40&digits=on&upperalpha=on&loweralpha=on&unique=on&format=html&rnd=new" target="_blank">penjana rentetan rawak</a>). <strong>Salin kunci ini.</strong></li>
            <li>Di <strong>setiap server</strong> dalam kluster:
                <ol type="a">
                    <li>Pastikan plugin 'Nginx Cache' atau 'Nginx Helper' telah dipasang dan diaktifkan.</li>
                    <li>Pasang dan aktifkan plugin 'Nginx Cache Multiserver Extension' ini.</li>
                    <li>Pergi ke Settings -> NC Multiserver.</li>
                    <li>Isikan semua medan. Pastikan <strong>semua tetapan adalah sama</strong> di setiap server, termasuk Kunci Rahsia yang Tuan salin tadi.</li>
                    <li>Klik 'Save Settings'.</li>
                </ol>
            </li>
        </ol>
    </div>
    <?php
}