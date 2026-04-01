<?php
/**
 * Plugin Name: Kilpailu Hallinta (Ilmainen ACF)
 * Description: Hallitse kilpailuja ja tuloksia ilman ACF Prota.
 * Version: 1.1
 */

if (!defined('ABSPATH')) exit;

/**
 * 1. SISÄLTÖTYYPPI: KILPAILU
 */
add_action('init', function() {
    register_post_type('kilpailu', [
        'labels' => ['name' => 'Kilpailut', 'singular_name' => 'Kilpailu'],
        'public' => true,
        'has_archive' => false,
        'show_in_rest' => true, // Tarvitaan lohkon hakuun
        'supports' => ['title'],
        'menu_icon' => 'dashicons-trophy',
    ]);
});

/**
 * 2. ACF-KENTÄT (10 RIVIÄ ILMAN PROTA)
 */
if (function_exists('acf_add_local_field_group')) {
    $fields = [];

    for ($i = 1; $i <= 10; $i++) {
        $fields[] = [
            'key' => "field_group_row_$i",
            'label' => "Rivi $i",
            'name' => "rivi_$i",
            'type' => 'accordion',
            'open' => ($i <= 5) ? 1 : 0, // Ensimmäiset 5 auki oletuksena
            'multi_expand' => 1,
        ];
        $fields[] = [
            'key' => "field_kilpailija_$i",
            'label' => "Kilpailija $i",
            'name' => "kilpailija_$i",
            'type' => 'user',
            'wrapper' => ['width' => '70%'],
        ];
        $fields[] = [
            'key' => "field_pisteet_$i",
            'label' => "Pisteet $i",
            'name' => "pisteet_$i",
            'type' => 'number',
            'step' => '0.01',
            'wrapper' => ['width' => '30%'],
        ];
    }

    acf_add_local_field_group([
        'key' => 'group_kilpailun_tiedot',
        'title' => 'Kilpailun tulokset',
        'fields' => $fields,
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'kilpailu']]],
    ]);
}

/**
 * 3. TIETOTURVA & RAJOITUKSET
 */
// Estä muiden kuin sivujen (page) ja etusivun katselu
add_action('template_redirect', function() {
    if (!is_admin() && !is_page() && !is_front_page()) {
        wp_redirect(home_url());
        exit;
    }
});

// Poista julkinen REST API
add_filter('rest_authentication_errors', function($result) {
    if (!empty($result)) return $result;
    if (!is_user_logged_in()) {
        return new WP_Error('rest_forbidden', 'Ei pääsyä.', ['status' => 401]);
    }
    return $result;
});

/**
 * 4. TULOSLOHKO (GUTENBERG)
 */
add_action('init', function() {
    register_block_type('kh/kilpailu-tulokset', [
        'render_callback' => 'kh_render_results_block'
    ]);
});

function kh_render_results_block($attributes) {
    $kilpailut = get_posts(['post_type' => 'kilpailu', 'posts_per_page' => -1]);
    if (empty($kilpailut)) return '<p>Ei kilpailuja löytynyt.</p>';

    $kokonaistilanne = [];
    $kilpailut_html = '';

    foreach ($kilpailut as $post) {
        $data = [];
        for ($i = 1; $i <= 10; $i++) {
            $user_id = get_post_meta($post->ID, "kilpailija_$i", true);
            $pisteet = get_post_meta($post->ID, "pisteet_$i", true);

            if ($user_id) {
                $user = get_userdata($user_id);
                $nimi = $user ? $user->display_name : 'Tuntematon';
                $pisteet_val = (float)$pisteet;

                $data[] = ['nimi' => $nimi, 'pisteet' => $pisteet_val];

                // Laskenta kokonaistilanteeseen
                if (!isset($kokonaistilanne[$user_id])) {
                    $kokonaistilanne[$user_id] = ['nimi' => $nimi, 'pisteet' => 0];
                }
                $kokonaistilanne[$user_id]['pisteet'] += $pisteet_val;
            }
        }

        if (!empty($data)) {
            usort($data, fn($a, $b) => $b['pisteet'] <=> $a['pisteet']);
            $rows = '';
            foreach ($data as $r) $rows .= "<tr><td>{$r['nimi']}</td><td>{$r['pisteet']}</td></tr>";
            $kilpailut_html .= kh_format_details($post->post_title, $rows);
        }
    }

    // Kokonaistilanne-haitari
    usort($kokonaistilanne, fn($a, $b) => $b['pisteet'] <=> $a['pisteet']);
    $total_rows = '';
    foreach ($kokonaistilanne as $d) $total_rows .= "<tr><td>{$d['nimi']}</td><td>{$d['pisteet']}</td></tr>";
    
    $alku = kh_format_details('Kokonaistilanne', $total_rows);

    return "<div class='kilpailu-container'>{$alku}{$kilpailut_html}</div>";
}

// Apufunktio HTML-muotoiluun
function kh_format_details($title, $rows) {
    return "
    <details class='wp-block-details'>
        <summary><strong>{$title}</strong></summary>
        <div class='wp-block-details__content'>
            <table class='kilpailu-table'>
                <thead><tr><th>Kilpailija</th><th>Pisteet</th></tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        </div>
    </details>";
}

/**
 * 5. TYYLIT (CSS)
 */
add_action('wp_head', function() {
    echo "
    <style>
        .kilpailu-container { margin: 20px 0; }
        details.wp-block-details { border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; border-radius: 4px; }
        summary { cursor: pointer; padding: 5px; }
        .kilpailu-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .kilpailu-table th, .kilpailu-table td { border-bottom: 1px solid #eee; padding: 8px; text-align: left; }
        .kilpailu-table th { background: #f9f9f9; font-weight: bold; }
    </style>";
});