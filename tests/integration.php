<?php
// Run only against an isolated WordPress test installation.
$wordpress = getenv('WP_LOAD_PATH');
if (!$wordpress || !is_file($wordpress)) {
    fwrite(STDERR, "Set WP_LOAD_PATH to an isolated WordPress installation's wp-load.php.\n");
    exit(1);
}
define('DISABLE_WP_CRON', true);
if (getenv('ROXXCOMP_TEST_PROXY')) {
    define('WP_PROXY_HOST', '127.0.0.1');
    define('WP_PROXY_PORT', '1');
}
require $wordpress;
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once dirname(__DIR__) . '/roxxcomp-results.php';

function expect($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$uuid = '11111111-1111-4111-8111-111111111111';
$stage = '22222222-2222-4222-8222-222222222222';
$key = str_repeat('A', 43);
$mode = 'normal';
$requests = array();
$settings = array('site_url' => 'https://competition.example', 'api_key' => $key);
$settings_filter = function () use (&$settings) {
    return $settings;
};
add_filter('pre_option_roxxcomp_results_settings', $settings_filter);
add_filter('pre_http_request', function ($pre, $args, $url) use (&$requests, &$mode, $uuid, $stage, $key) {
    if ($mode === 'loopback_real') {
        return $pre;
    }
    $requests[] = array($url, $args);
    expect($args['headers']['Authorization'] === 'Token ' . $key, 'Missing server-side authorization.');
    expect($args['redirection'] === 0, 'API redirects must be disabled.');
    expect($args['reject_unsafe_urls'] === true, 'External requests must use WordPress safe HTTP.');
    expect(strpos($url, 'https://competition.example/api/v1/') === 0, 'Unexpected API destination.');
    $path = wp_parse_url($url, PHP_URL_PATH);
    $query = wp_parse_url($url, PHP_URL_QUERY);
    parse_str(is_string($query) ? $query : '', $params);
    $category = function ($id, $name) use ($uuid, $stage, &$mode) {
        $host = $mode === 'foreign_link' ? 'https://untrusted.example' : 'https://competition.example';
        return array(
            'id' => $id,
            'name' => $name,
            'ranking_url' => $mode === 'no_stage' ? null :
                "$host/api/v1/competitions/$uuid/stages/$stage/categories/$id/ranking/",
        );
    };
    if ($mode === 'http_error') {
        return array('response' => array('code' => 403), 'body' => '', 'headers' => array(), 'cookies' => array());
    }
    if ($path === '/api/v1/competitions/') {
        $data = ($params['page'] ?? '1') === '1'
            ? array('results' => array(array('uuid' => $uuid, 'name' => 'Competition', 'start_date' => '2026-01-01',
                'public' => $mode === 'invalid_public' ? 'false' : $mode === 'public')), 'next' => 'https://untrusted.example/page2')
            : array('results' => array(), 'next' => null);
    } elseif ($path === "/api/v1/competitions/$uuid/") {
        $data = array('name' => 'Competition <unsafe>', 'categories' => array(
            $category(1, 'Open'), $category(2, 'Youth'),
        ));
    } elseif (preg_match('#/categories/([12])/ranking/$#', $path, $match)) {
        $data = array(
            'stage' => array('name' => 'Final'),
            'ranking' => array(
                array('rank' => 1, 'name' => $match[1] === '2' ? 'Youth climber' : '<script>climber</script>', 'score' => 12.5),
                array('rank' => 2, 'name' => 'No score', 'score' => null),
            ),
        );
    } else {
        throw new RuntimeException('Unexpected API path: ' . $path);
    }
    return array('response' => array('code' => 200), 'body' => wp_json_encode($data), 'headers' => array(), 'cookies' => array());
}, 10, 3);

roxxcomp_results_register();
expect(WP_Block_Type_Registry::get_instance()->is_registered('roxxcomp/results'), 'Block not registered.');
$block_type = WP_Block_Type_Registry::get_instance()->get_registered('roxxcomp/results');
expect(in_array('roxxcomp-results-view', $block_type->view_script_handles, true), 'View script not registered.');
$server = rest_get_server();
roxxcomp_results_rest_routes();
wp_set_current_user(0);
$unauthorized = $server->dispatch(new WP_REST_Request('GET', '/roxxcomp/v1/competitions'));
expect($unauthorized->get_status() === 401, 'Anonymous competition list must be denied.');
$admins = get_users(array('role' => 'administrator', 'number' => 1));
expect(count($admins) === 1, 'Test installation needs an administrator.');
wp_set_current_user($admins[0]->ID);
$listing = $server->dispatch(new WP_REST_Request('GET', '/roxxcomp/v1/competitions'));
expect($listing->get_status() === 200 && count($listing->get_data()) === 1, 'Paginated competition list failed.');
expect($listing->get_data()[0]['public'] === false, 'Competition visibility was not returned to the editor.');
expect(strpos(wp_json_encode($listing->get_data()), $key) === false, 'Key exposed in REST response.');
expect(strpos(end($requests)[0], 'competition.example/api/v1/competitions/') !== false, 'Pagination followed an external link.');
$mode = 'public';
$public_listing = roxxcomp_results_competitions();
expect(!is_wp_error($public_listing) && $public_listing->get_data()[0]['public'] === true, 'Public competition visibility changed.');
$mode = 'invalid_public';
expect(is_wp_error(roxxcomp_results_competitions()), 'Invalid competition visibility was accepted.');
$mode = 'normal';

$post_id = wp_insert_post(array('post_title' => 'Test results', 'post_status' => 'publish', 'post_type' => 'page'));
expect(!is_wp_error($post_id) && $post_id > 0, 'Could not create test page.');
global $post;
$post = get_post($post_id);
try {
    $_GET = array();
    $html = roxxcomp_results_render(array('competitionId' => $uuid, 'scope' => 'combined'));
    expect(strpos($html, "https://competition.example/event/events/$uuid/") !== false, 'Competition link missing.');
    expect(strpos($html, 'Competition &lt;unsafe&gt;') !== false, 'Competition name not escaped.');
    expect(strpos($html, '&lt;script&gt;climber&lt;/script&gt;') !== false, 'Participant name not escaped.');
    expect(strpos($html, '<table') !== false && strpos($html, '12.5') !== false, 'Ranking table missing.');
    expect(strpos($html, '<select') !== false && strpos($html, 'Youth') !== false, 'Category selector missing.');
    expect(strpos($html, 'type="submit"') !== false, 'No-JavaScript submit button missing.');
    expect(strpos($html, 'roxxcomp-results__content') !== false, 'Dynamic results region missing.');
    expect(strpos($html, 'role="status"') !== false, 'Dynamic status announcement missing.');
    expect(strpos($html, 'page_id') !== false, 'Plain permalink query was not preserved.');
    expect(strpos($html, $key) === false, 'Key exposed in page HTML.');
    expect(strpos(end($requests)[0], 'results=combined') !== false, 'Combined scope missing.');

    $_GET = array('roxxcomp_category_2' => '2');
    $html = roxxcomp_results_render(array('competitionId' => $uuid, 'scope' => 'stage'));
    expect(strpos($html, 'Youth climber') !== false, 'Selected category was not rendered.');
    expect(strpos(end($requests)[0], 'results=stage') !== false, 'Stage-only scope missing.');
    $html = roxxcomp_results_render(array('competitionId' => $uuid));
    expect(strpos($html, 'name="roxxcomp_category_2" value="2"') !== false, 'Another block selection was not preserved.');

    $mode = 'foreign_link';
    $_GET = array();
    $html = roxxcomp_results_render(array('competitionId' => $uuid, 'scope' => 'combined'));
    expect(strpos($html, '<table') !== false, 'Valid ranking path from foreign link was not reconstructed.');
    expect(strpos(end($requests)[0], 'https://competition.example/') === 0, 'Authorization sent to foreign host.');

    $mode = 'no_stage';
    $html = roxxcomp_results_render(array('competitionId' => $uuid));
    expect(strpos($html, 'Noch keine Ergebnisse') !== false, 'Missing current stage not reported.');
    $mode = 'http_error';
    $html = roxxcomp_results_render(array('competitionId' => $uuid));
    expect(strpos($html, 'nicht verfügbar') !== false, 'API error not reported.');
    expect(strpos($html, $key) === false, 'Key exposed in error.');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    roxxcomp_results_settings_page();
    $admin_html = ob_get_clean();
    expect(strpos($admin_html, '_wpnonce') !== false, 'Settings form is missing a nonce.');
    expect(strpos($admin_html, $key) === false, 'Key exposed in settings form.');
    expect(!roxxcomp_results_valid_base_url('http://competition.example'), 'Insecure server URL accepted.');
    expect(!roxxcomp_results_valid_base_url('https://user:pass@competition.example'), 'URL credentials accepted.');
    foreach (array('http://localhost:8000', 'http://127.0.0.1:8000', 'http://[::1]:8000',
        'http://host.docker.internal:8000') as $url) {
        expect(roxxcomp_results_valid_base_url($url), 'Local development URL rejected: ' . $url);
    }
    foreach (array('http://localhost.example', 'http://localhost.evil.test', 'http://127.0.0.2',
        'http://192.168.1.1', 'http://host.docker.internal.evil.test', 'http://host.docker.internal@evil.example',
        'http://evil.example@host.docker.internal', 'http://localhost@evil.example',
        'http://localhost/?next=evil', 'http://localhost/#fragment') as $url) {
        expect(!roxxcomp_results_valid_base_url($url), 'Unsafe HTTP URL accepted: ' . $url);
    }
    $docker_port = getenv('ROXXCOMP_DOCKER_TEST_PORT');
    if ($docker_port) {
        expect(ctype_digit($docker_port), 'Invalid Docker test port.');
        $mode = 'loopback_real';
        $settings['site_url'] = "http://host.docker.internal:$docker_port";
        $result = roxxcomp_results_fetch('test/');
        expect(!is_wp_error($result) && $result['ok'] === true, 'Docker host request failed.');
        expect($result['host'] === "host.docker.internal:$docker_port", 'Docker host header changed.');
        expect(is_wp_error(roxxcomp_results_fetch('redirect/')), 'Docker host redirect was followed.');
        $settings['site_url'] = 'https://competition.example';
    }
    $port = getenv('ROXXCOMP_LOOPBACK_TEST_PORT');
    if ($port) {
        expect(ctype_digit($port), 'Invalid loopback test port.');
        $mode = 'loopback_real';
        $hosts = array('localhost', '127.0.0.1');
        if (getenv('ROXXCOMP_TEST_IPV6')) {
            $hosts[] = '[::1]';
        }
        foreach ($hosts as $host) {
            $settings['site_url'] = "http://$host:$port";
            $result = roxxcomp_results_fetch('test/');
            expect(!is_wp_error($result) && $result['ok'] === true, 'Actual loopback request failed: ' . $host);
            expect($result['host'] === "$host:$port", 'Loopback Host header not preserved.');
            expect(is_wp_error(roxxcomp_results_fetch('redirect/')), 'Loopback redirect was followed.');
        }
        $settings['site_url'] = 'http://localhost.example';
        expect(is_wp_error(roxxcomp_results_fetch('test/')), 'Remote HTTP request was not rejected.');
        $settings['site_url'] = 'https://competition.example';
    }
    remove_filter('pre_option_roxxcomp_results_settings', $settings_filter);
    $original_settings = get_option(ROXXCOMP_RESULTS_OPTION, false);
    try {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array('site_url' => 'https://competition.example', 'api_key' => $key);
        $_REQUEST['_wpnonce'] = wp_create_nonce('roxxcomp_results_save');
        ob_start();
        roxxcomp_results_settings_page();
        $admin_html = ob_get_clean();
        expect(get_option(ROXXCOMP_RESULTS_OPTION) === $settings, 'Settings were not saved.');
        expect(strpos($admin_html, $key) === false, 'Saved key exposed in settings response.');

        $_POST = array('site_url' => 'https://other.example', 'api_key' => '');
        ob_start();
        roxxcomp_results_settings_page();
        $admin_html = ob_get_clean();
        expect(get_option(ROXXCOMP_RESULTS_OPTION) === $settings, 'Key retained after changing server.');
        expect(strpos($admin_html, 'notice-error') !== false, 'Server change without key was not reported.');

        $_POST = array('site_url' => 'http://localhost:8000', 'api_key' => $key);
        ob_start();
        roxxcomp_results_settings_page();
        $admin_html = ob_get_clean();
        expect(get_option(ROXXCOMP_RESULTS_OPTION) === array(
            'site_url' => 'http://localhost:8000', 'api_key' => $key,
        ), 'Local HTTP settings were not saved.');
        expect(strpos($admin_html, 'notice-success') !== false, 'Local HTTP save was not confirmed.');

        $_POST = array('site_url' => 'http://host.docker.internal:8000', 'api_key' => $key);
        ob_start();
        roxxcomp_results_settings_page();
        $admin_html = ob_get_clean();
        expect(get_option(ROXXCOMP_RESULTS_OPTION) === array(
            'site_url' => 'http://host.docker.internal:8000', 'api_key' => $key,
        ), 'Docker host HTTP settings were not saved.');
        expect(strpos($admin_html, 'notice-success') !== false, 'Docker host HTTP save was not confirmed.');
    } finally {
        if ($original_settings === false) {
            delete_option(ROXXCOMP_RESULTS_OPTION);
        } else {
            update_option(ROXXCOMP_RESULTS_OPTION, $original_settings);
        }
    }
    echo "WordPress integration checks passed.\n";
} finally {
    wp_delete_post($post_id, true);
}
