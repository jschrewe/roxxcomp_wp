<?php
/**
 * Plugin Name: RoXx Competition Results
 * Description: Displays competition results from the organization results API.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: roxxcomp-results
 */

defined('ABSPATH') || exit;

const ROXXCOMP_RESULTS_OPTION = 'roxxcomp_results_settings';
const ROXXCOMP_RESULTS_UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

function roxxcomp_results_settings() {
    $settings = get_option(ROXXCOMP_RESULTS_OPTION, array());
    return is_array($settings) ? $settings : array();
}

function roxxcomp_results_register() {
    wp_register_script(
        'roxxcomp-results-editor',
        plugins_url('assets/editor.js', __FILE__),
        array('wp-api-fetch', 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element'),
        '1.0.1',
        true
    );
    wp_localize_script('roxxcomp-results-editor', 'roxxcompResultsLabels', array(
        'title' => __('Wettkampfergebnisse', 'roxxcomp-results'),
        'competition' => __('Wettkampf', 'roxxcomp-results'),
        'private' => __('private', 'roxxcomp-results'),
        'select' => __('Wettkampf auswählen', 'roxxcomp-results'),
        'loading' => __('Wettkämpfe werden geladen …', 'roxxcomp-results'),
        'loadError' => __('Wettkämpfe konnten nicht geladen werden. Prüfe die Plugin-Einstellungen.', 'roxxcomp-results'),
        'missing' => __('Der gespeicherte Wettkampf ist nicht verfügbar.', 'roxxcomp-results'),
        'notice' => __('Die Ergebnisse des ausgewählten Wettkampfs werden für Besucher dieser Seite veröffentlicht, auch wenn der Wettkampf privat ist.', 'roxxcomp-results'),
        'scope' => __('Wertung', 'roxxcomp-results'),
        'combined' => __('Aktuelle Runde und verbundene Runden', 'roxxcomp-results'),
        'stage' => __('Nur aktuelle Runde', 'roxxcomp-results'),
        'preview' => __('Die aktuelle Runde und ihre Ergebnisse werden auf der veröffentlichten Seite angezeigt.', 'roxxcomp-results'),
    ));
    wp_register_style(
        'roxxcomp-results',
        plugins_url('assets/style.css', __FILE__),
        array(),
        '1.0.0'
    );
    wp_register_script(
        'roxxcomp-results-view',
        plugins_url('assets/view.js', __FILE__),
        array(),
        '1.0.0',
        true
    );
    register_block_type('roxxcomp/results', array(
        'api_version' => 2,
        'editor_script' => 'roxxcomp-results-editor',
        'view_script' => 'roxxcomp-results-view',
        'style' => 'roxxcomp-results',
        'attributes' => array(
            'competitionId' => array('type' => 'string', 'default' => ''),
            'scope' => array('type' => 'string', 'default' => 'combined'),
        ),
        'render_callback' => 'roxxcomp_results_render',
    ));
}
add_action('init', 'roxxcomp_results_register');

function roxxcomp_results_admin_menu() {
    add_options_page(
        __('RoXx Ergebnisse', 'roxxcomp-results'),
        __('RoXx Ergebnisse', 'roxxcomp-results'),
        'manage_options',
        'roxxcomp-results',
        'roxxcomp_results_settings_page'
    );
}
add_action('admin_menu', 'roxxcomp_results_admin_menu');

function roxxcomp_results_local_http_url($url) {
    $parts = wp_parse_url($url);
    return is_array($parts) && isset($parts['scheme'], $parts['host'])
        && strtolower($parts['scheme']) === 'http'
        && in_array(strtolower($parts['host']), array('localhost', '127.0.0.1', '[::1]', 'host.docker.internal'), true);
}

function roxxcomp_results_valid_base_url($url) {
    $parts = wp_parse_url($url);
    return is_array($parts)
        && isset($parts['scheme'], $parts['host'])
        && (strtolower($parts['scheme']) === 'https' || roxxcomp_results_local_http_url($url))
        && !empty($parts['host'])
        && !isset($parts['user']) && !isset($parts['pass'])
        && !isset($parts['query']) && !isset($parts['fragment'])
        && esc_url_raw($url, array('http', 'https')) === $url;
}

function roxxcomp_results_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Keine Berechtigung.', 'roxxcomp-results'));
    }
    $settings = roxxcomp_results_settings();
    $error = '';
    $saved = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('roxxcomp_results_save');
        $url = isset($_POST['site_url']) && is_string($_POST['site_url'])
            ? trim(wp_unslash($_POST['site_url'])) : '';
        $key = isset($_POST['api_key']) && is_string($_POST['api_key'])
            ? trim(wp_unslash($_POST['api_key'])) : '';
        $clear_key = isset($_POST['clear_key']);
        if (!roxxcomp_results_valid_base_url($url)) {
            $error = __('Gib eine gültige HTTPS-Adresse (HTTP nur für lokale Entwicklung) ohne Zugangsdaten, Abfrage oder Fragment ein.', 'roxxcomp-results');
        } elseif ($key !== '' && !preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $key)) {
            $error = __('Der API-Schlüssel hat ein ungültiges Format.', 'roxxcomp-results');
        } elseif ($key === '' && !$clear_key && !empty($settings['api_key'])
            && rtrim($url, '/') !== rtrim($settings['site_url'] ?? '', '/')) {
            $error = __('Beim Ändern der Serveradresse muss der API-Schlüssel erneut eingegeben werden.', 'roxxcomp-results');
        } elseif ($clear_key && $key !== '') {
            $error = __('Gib entweder einen neuen Schlüssel ein oder entferne den vorhandenen.', 'roxxcomp-results');
        } else {
            $settings = array(
                'site_url' => untrailingslashit($url),
                'api_key' => $clear_key ? '' : ($key !== '' ? $key : ($settings['api_key'] ?? '')),
            );
            update_option(ROXXCOMP_RESULTS_OPTION, $settings, false);
            if (get_option(ROXXCOMP_RESULTS_OPTION) !== $settings) {
                $error = __('Die Einstellungen konnten nicht gespeichert werden.', 'roxxcomp-results');
            } else {
                $saved = true;
            }
        }
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('RoXx Ergebnisse', 'roxxcomp-results'); ?></h1>
        <?php if ($error !== '') : ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php elseif ($saved) : ?>
            <div class="notice notice-success"><p><?php esc_html_e('Einstellungen gespeichert.', 'roxxcomp-results'); ?></p></div>
        <?php endif; ?>
        <p><?php esc_html_e('Der Schlüssel gewährt Zugriff auf die Ergebnisse der gesamten Organisation, einschließlich privater Wettkämpfe. Veröffentliche nur Ergebnisse, deren Veröffentlichung beabsichtigt ist.', 'roxxcomp-results'); ?></p>
        <form method="post">
            <?php wp_nonce_field('roxxcomp_results_save'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="roxxcomp-site-url"><?php esc_html_e('Wettkampfserver', 'roxxcomp-results'); ?></label></th>
                    <td><input id="roxxcomp-site-url" class="regular-text" type="url" name="site_url" required value="<?php echo esc_attr($settings['site_url'] ?? ''); ?>">
                    <p class="description"><?php esc_html_e('Basisadresse der Wettkampfplattform, ohne /api/v1/. HTTPS ist erforderlich; HTTP ist nur für localhost, 127.0.0.1, [::1] und host.docker.internal zur lokalen Entwicklung erlaubt. Dabei wird der API-Schlüssel unverschlüsselt übertragen.', 'roxxcomp-results'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="roxxcomp-api-key"><?php esc_html_e('Organisations-API-Schlüssel', 'roxxcomp-results'); ?></label></th>
                    <td><input id="roxxcomp-api-key" class="regular-text" type="password" name="api_key" autocomplete="new-password" value="">
                    <p class="description"><?php echo esc_html(!empty($settings['api_key'])
                        ? __('Ein Schlüssel ist gespeichert. Leer lassen, um ihn beizubehalten.', 'roxxcomp-results')
                        : __('Noch kein Schlüssel gespeichert.', 'roxxcomp-results')); ?></p>
                    <?php if (!empty($settings['api_key'])) : ?>
                    <label><input type="checkbox" name="clear_key" value="1"> <?php esc_html_e('Gespeicherten Schlüssel entfernen', 'roxxcomp-results'); ?></label>
                    <?php endif; ?></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function roxxcomp_results_fetch($path, $query = array()) {
    $settings = roxxcomp_results_settings();
    $base = $settings['site_url'] ?? '';
    $key = $settings['api_key'] ?? '';
    if (!is_string($base) || !roxxcomp_results_valid_base_url($base)
        || !is_string($key) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $key)) {
        return new WP_Error('roxxcomp_config', __('Der Wettkampfserver oder API-Schlüssel ist nicht konfiguriert.', 'roxxcomp-results'));
    }
    $local_http = roxxcomp_results_local_http_url($base);
    $headers = array('Authorization' => 'Token ' . $key, 'Accept' => 'application/json');
    if ($local_http && strtolower(wp_parse_url($base, PHP_URL_HOST)) === 'localhost') {
        $port = wp_parse_url($base, PHP_URL_PORT);
        $headers['Host'] = 'localhost' . ($port ? ':' . $port : '');
        $base = preg_replace('#\Ahttp://localhost(?=[:/]|$)#i', 'http://127.0.0.1', $base);
    }
    $url = trailingslashit($base) . 'api/v1/' . ltrim($path, '/');
    if ($query) {
        $url = add_query_arg($query, $url);
    }
    $args = array(
        'headers' => $headers,
        'timeout' => 10,
        'redirection' => 0,
    );
    if ($local_http) {
        // Never send a local development request or its key through a configured proxy.
        $bypass_proxy = function ($proxy, $uri) use ($url) {
            return $uri === $url ? false : $proxy;
        };
        add_filter('pre_http_send_through_proxy', $bypass_proxy, 10, 2);
        try {
            $response = wp_remote_get($url, $args);
        } finally {
            remove_filter('pre_http_send_through_proxy', $bypass_proxy, 10);
        }
    } else {
        $response = wp_safe_remote_get($url, $args);
    }
    if (is_wp_error($response)) {
        return new WP_Error('roxxcomp_request', __('Der Wettkampfserver ist nicht erreichbar.', 'roxxcomp-results'));
    }
    if (wp_remote_retrieve_response_code($response) !== 200) {
        return new WP_Error('roxxcomp_response', __('Der Wettkampfserver hat die Anfrage abgelehnt oder Ergebnisse sind nicht verfügbar.', 'roxxcomp-results'));
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return new WP_Error('roxxcomp_json', __('Ungültige Antwort vom Wettkampfserver.', 'roxxcomp-results'));
    }
    return $data;
}

function roxxcomp_results_valid_uuid($uuid) {
    return is_string($uuid) && preg_match('/\A' . ROXXCOMP_RESULTS_UUID . '\z/iD', $uuid);
}

function roxxcomp_results_competitions() {
    $competitions = array();
    for ($page = 1; $page <= 100; $page++) {
        $data = roxxcomp_results_fetch('competitions/', array('page' => $page));
        if (is_wp_error($data)) {
            return $data;
        }
        if (!isset($data['results']) || !is_array($data['results']) || !array_key_exists('next', $data)
            || ($data['next'] !== null && !is_string($data['next']))) {
            return new WP_Error('roxxcomp_json', __('Ungültige Wettkampfliste.', 'roxxcomp-results'));
        }
        foreach ($data['results'] as $competition) {
            if (!is_array($competition) || !roxxcomp_results_valid_uuid($competition['uuid'] ?? null)
                || !isset($competition['name']) || !is_string($competition['name'])
                || !isset($competition['public']) || !is_bool($competition['public'])) {
                return new WP_Error('roxxcomp_json', __('Ungültiger Wettkampf in der Liste.', 'roxxcomp-results'));
            }
            $competitions[] = array(
                'uuid' => $competition['uuid'],
                'name' => $competition['name'],
                'public' => $competition['public'],
                'start_date' => isset($competition['start_date']) && is_string($competition['start_date'])
                    ? $competition['start_date'] : '',
            );
        }
        if ($data['next'] === null) {
            return rest_ensure_response($competitions);
        }
    }
    return new WP_Error('roxxcomp_pages', __('Die Wettkampfliste ist zu umfangreich.', 'roxxcomp-results'));
}

function roxxcomp_results_rest_routes() {
    register_rest_route('roxxcomp/v1', '/competitions', array(
        'methods' => 'GET',
        'callback' => 'roxxcomp_results_competitions',
        'permission_callback' => function () {
            return current_user_can('edit_posts') || current_user_can('edit_pages');
        },
    ));
}
add_action('rest_api_init', 'roxxcomp_results_rest_routes');

function roxxcomp_results_error($message) {
    return '<div class="wp-block-roxxcomp-results roxxcomp-results"><p class="roxxcomp-results__notice">'
        . esc_html($message) . '</p></div>';
}

function roxxcomp_results_render($attributes) {
    static $block_number = 0;
    $block_number++;
    $uuid = $attributes['competitionId'] ?? '';
    $scope = $attributes['scope'] ?? 'combined';
    if (!roxxcomp_results_valid_uuid($uuid) || !in_array($scope, array('combined', 'stage'), true)) {
        return roxxcomp_results_error(__('Bitte wähle einen gültigen Wettkampf und eine Wertung aus.', 'roxxcomp-results'));
    }
    $uuid = strtolower($uuid);
    $competition = roxxcomp_results_fetch('competitions/' . $uuid . '/');
    if (is_wp_error($competition)) {
        return roxxcomp_results_error($competition->get_error_message());
    }
    if (!isset($competition['name'], $competition['categories']) || !is_string($competition['name'])
        || !is_array($competition['categories'])) {
        return roxxcomp_results_error(__('Ungültige Wettkampfantwort.', 'roxxcomp-results'));
    }
    $categories = array();
    foreach ($competition['categories'] as $category) {
        if (!is_array($category) || !isset($category['id'], $category['name'])
            || !is_int($category['id']) || $category['id'] <= 0 || !is_string($category['name'])
            || !array_key_exists('ranking_url', $category)) {
            return roxxcomp_results_error(__('Ungültige Kategorie in der Wettkampfantwort.', 'roxxcomp-results'));
        }
        if ($category['ranking_url'] === null) {
            continue;
        }
        $path = is_string($category['ranking_url']) ? wp_parse_url($category['ranking_url'], PHP_URL_PATH) : null;
        $pattern = '#/api/v1/competitions/' . preg_quote($uuid, '#') . '/stages/(' . ROXXCOMP_RESULTS_UUID
            . ')/categories/' . $category['id'] . '/ranking/\z#iD';
        if (!is_string($path) || !preg_match($pattern, $path, $match)) {
            return roxxcomp_results_error(__('Ungültiger Ergebnislink in der Wettkampfantwort.', 'roxxcomp-results'));
        }
        $category['stage_uuid'] = strtolower($match[1]);
        $categories[$category['id']] = $category;
    }
    $settings = roxxcomp_results_settings();
    $competition_url = trailingslashit($settings['site_url']) . 'event/events/' . $uuid . '/';
    $heading = '<h2 class="roxxcomp-results__title"><a href="' . esc_url($competition_url) . '">'
        . esc_html($competition['name']) . '</a></h2>';
    if (!$categories) {
        return '<div class="wp-block-roxxcomp-results roxxcomp-results">' . $heading
            . '<p>' . esc_html__('Noch keine Ergebnisse verfügbar.', 'roxxcomp-results') . '</p></div>';
    }
    $parameter = 'roxxcomp_category_' . $block_number;
    $selected = array_key_first($categories);
    if (isset($_GET[$parameter])) {
        $requested = is_string($_GET[$parameter]) ? wp_unslash($_GET[$parameter]) : '';
        if (!ctype_digit($requested) || !isset($categories[(int) $requested])) {
            return roxxcomp_results_error(__('Die ausgewählte Kategorie ist nicht verfügbar.', 'roxxcomp-results'));
        }
        $selected = (int) $requested;
    }
    $category = $categories[$selected];
    $ranking = roxxcomp_results_fetch(
        'competitions/' . $uuid . '/stages/' . $category['stage_uuid'] . '/categories/' . $selected . '/ranking/',
        array('results' => $scope)
    );
    if (is_wp_error($ranking)) {
        return roxxcomp_results_error($ranking->get_error_message());
    }
    if (!isset($ranking['ranking'], $ranking['stage']['name'])
        || !is_array($ranking['ranking']) || !is_string($ranking['stage']['name'])) {
        return roxxcomp_results_error(__('Ungültige Ergebnisantwort.', 'roxxcomp-results'));
    }
    foreach ($ranking['ranking'] as $entry) {
        if (!is_array($entry) || !isset($entry['rank'], $entry['name'])
            || !is_int($entry['rank']) || !is_string($entry['name'])
            || !array_key_exists('score', $entry)
            || !(is_int($entry['score']) || is_float($entry['score']) || $entry['score'] === null)) {
            return roxxcomp_results_error(__('Ungültiger Eintrag in der Ergebnisliste.', 'roxxcomp-results'));
        }
    }
    $select_id = wp_unique_id('roxxcomp-category-');
    $permalink = get_permalink();
    if (!$permalink) {
        return roxxcomp_results_error(__('Die Seitenadresse ist nicht verfügbar.', 'roxxcomp-results'));
    }
    $permalink_query = wp_parse_url($permalink, PHP_URL_QUERY);
    $query_args = array();
    if (is_string($permalink_query)) {
        parse_str($permalink_query, $query_args);
    }
    ob_start();
    ?>
    <div class="wp-block-roxxcomp-results roxxcomp-results">
        <?php echo $heading; // Escaped above. ?>
        <form class="roxxcomp-results__filter" method="get" action="<?php echo esc_url($permalink); ?>">
            <?php foreach ($query_args as $name => $value) : ?>
                <?php if (is_string($value)) : ?>
                    <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <?php foreach ($_GET as $name => $value) : ?>
                <?php if (preg_match('/\Aroxxcomp_category_[1-9][0-9]*\z/D', $name) && $name !== $parameter
                    && is_string($value) && ctype_digit($value)) : ?>
                    <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr(wp_unslash($value)); ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <label for="<?php echo esc_attr($select_id); ?>"><?php esc_html_e('Kategorie', 'roxxcomp-results'); ?></label>
            <select id="<?php echo esc_attr($select_id); ?>" name="<?php echo esc_attr($parameter); ?>">
                <?php foreach ($categories as $item) : ?>
                    <option value="<?php echo esc_attr($item['id']); ?>" <?php selected($selected, $item['id']); ?>><?php echo esc_html($item['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit"><?php esc_html_e('Anzeigen', 'roxxcomp-results'); ?></button>
        </form>
        <p class="roxxcomp-results__status" role="status" aria-live="polite"
            data-loading="<?php esc_attr_e('Ergebnisse werden geladen …', 'roxxcomp-results'); ?>"
            data-loaded="<?php esc_attr_e('Ergebnisse für %s geladen.', 'roxxcomp-results'); ?>"
            data-error="<?php esc_attr_e('Ergebnisse konnten nicht geladen werden. Bitte versuche es erneut.', 'roxxcomp-results'); ?>"></p>
        <div class="roxxcomp-results__content">
        <p class="roxxcomp-results__stage"><?php echo esc_html($ranking['stage']['name']); ?></p>
        <?php if ($ranking['ranking']) : ?>
        <div class="roxxcomp-results__table-wrap" tabindex="0" role="region" aria-label="<?php esc_attr_e('Ergebnisliste', 'roxxcomp-results'); ?>">
            <table class="roxxcomp-results__table">
                <thead><tr><th scope="col"><?php esc_html_e('Platz', 'roxxcomp-results'); ?></th><th scope="col"><?php esc_html_e('Name', 'roxxcomp-results'); ?></th><th scope="col"><?php esc_html_e('Punkte', 'roxxcomp-results'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($ranking['ranking'] as $entry) : ?>
                    <tr><td><?php echo esc_html($entry['rank']); ?></td><td><?php echo esc_html($entry['name']); ?></td><td><?php echo esc_html($entry['score'] === null ? '–' : (string) $entry['score']); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else : ?>
            <p><?php esc_html_e('Für diese Kategorie liegen noch keine Ergebnisse vor.', 'roxxcomp-results'); ?></p>
        <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
