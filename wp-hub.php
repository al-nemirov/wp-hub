<?php
/**
 * Plugin Name: WooCommerce Menu Hub
 * Description: Универсальный консолидатор админ-меню. Любой пункт любого плагина можно СКРЫТЬ или ВСТРОИТЬ как вкладку в единый хаб. Настраивается через UI — без правки кода плагинов.
 * Version: 1.0.0
 * Author: Al Nemirov
 * Requires PHP: 7.4
 * License: MIT
 *
 * Как работает «встраивание»:
 *   страницу плагина НЕ переносим (иначе ломается её screen-specific JS — кнопки
 *   «тест связи» и т.п.). Вместо этого: убираем пункт из меню (remove_*_page — при
 *   этом страница остаётся доступной по URL), регистрируем один хаб-пункт и поверх
 *   всех встроенных страниц рисуем общую панель вкладок (in_admin_header). Получается
 *   единое меню с вкладками, а функциональность каждой страницы сохраняется.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Hub {

    const OPT           = 'wphub_config';
    const HUB_SLUG      = 'wphub-hub';
    const SETTINGS_SLUG = 'wphub-settings';

    /* Пункты, которые НИКОГДА нельзя скрывать/встраивать (защита от самоблокировки). */
    const PROTECTED = [ 'wphub-settings', 'wphub-hub', 'options-general.php', 'index.php' ];

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_pages' ], 8 );
        add_action( 'admin_menu', [ __CLASS__, 'apply_rules' ], 9999 );
        add_action( 'in_admin_header', [ __CLASS__, 'inject_tabbar' ] );
        add_action( 'admin_head', [ __CLASS__, 'styles' ] );
        add_filter(
            'plugin_action_links_' . plugin_basename( __FILE__ ),
            [ __CLASS__, 'action_links' ]
        );
    }

    /* ── Конфиг ───────────────────────────────────────────────── */

    public static function cfg(): array {
        return wp_parse_args( get_option( self::OPT, [] ), [
            'hub_title' => '📦 Отправка',
            'hub_icon'  => 'dashicons-archive',
            'items'     => [],   // key "parent>slug" или "slug" => 'show'|'hide'|'embed'
            'labels'    => [],   // key => человекочитаемая подпись (для вкладки)
        ] );
    }

    private static function parse_key( string $key ): array {
        $parts = explode( '>', $key, 2 );
        if ( count( $parts ) === 2 ) {
            return [ $parts[0], $parts[1] ]; // [parent, slug]
        }
        return [ '', $parts[0] ];            // top-level
    }

    /* ── Регистрация наших страниц (хаб + настройки) ──────────── */

    public static function register_pages(): void {
        $cfg = self::cfg();

        $has_embed = in_array( 'embed', $cfg['items'], true );
        if ( $has_embed ) {
            add_menu_page(
                $cfg['hub_title'],
                $cfg['hub_title'],
                'manage_woocommerce',
                self::HUB_SLUG,
                [ __CLASS__, 'render_hub' ],
                $cfg['hub_icon'] ?: 'dashicons-archive',
                56
            );
        }

        add_options_page(
            'WooCommerce Menu Hub',
            'WC Menu Hub',
            'manage_options',
            self::SETTINGS_SLUG,
            [ __CLASS__, 'render_settings' ]
        );
    }

    public static function action_links( $links ) {
        $url = admin_url( 'options-general.php?page=' . self::SETTINGS_SLUG );
        array_unshift( $links, '<a href="' . esc_url( $url ) . '">Настройки</a>' );
        return $links;
    }

    /* ── Применение правил: скрыть / встроить (= убрать из меню) ─ */

    public static function apply_rules(): void {
        $cfg = self::cfg();
        foreach ( $cfg['items'] as $key => $action ) {
            if ( $action !== 'hide' && $action !== 'embed' ) {
                continue;
            }
            if ( in_array( self::parse_key( $key )[1], self::PROTECTED, true ) ) {
                continue;
            }
            list( $parent, $slug ) = self::parse_key( $key );
            if ( $parent !== '' ) {
                remove_submenu_page( $parent, $slug );
            } else {
                remove_menu_page( $slug );
            }
        }
    }

    /* ── Список встроенных вкладок ────────────────────────────── */

    private static function embedded_tabs(): array {
        $cfg  = self::cfg();
        $tabs = [];
        foreach ( $cfg['items'] as $key => $action ) {
            if ( $action !== 'embed' ) {
                continue;
            }
            list( , $slug ) = self::parse_key( $key );
            $tabs[ $slug ] = [
                'label' => $cfg['labels'][ $key ] ?? $slug,
                'page'  => $slug,
            ];
        }
        return $tabs;
    }

    /* ── Панель вкладок ───────────────────────────────────────── */

    private static function tabbar( string $active ): void {
        $cfg = self::cfg();
        echo '<nav class="wphub-tabs">';
        // Кнопка-домик хаба
        printf(
            '<a href="%s" class="wphub-tab wphub-tab-home%s">%s</a>',
            esc_url( admin_url( 'admin.php?page=' . self::HUB_SLUG ) ),
            $active === self::HUB_SLUG ? ' active' : '',
            esc_html( $cfg['hub_title'] )
        );
        foreach ( self::embedded_tabs() as $slug => $tab ) {
            printf(
                '<a href="%s" class="wphub-tab%s">%s</a>',
                esc_url( admin_url( 'admin.php?page=' . $slug ) ),
                $slug === $active ? ' active' : '',
                esc_html( $tab['label'] )
            );
        }
        echo '</nav>';
    }

    /** Текущая admin-страница (?page=…). */
    private static function current_page(): string {
        return isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
    }

    /** Впрыск панели вкладок на встроенных страницах. */
    public static function inject_tabbar(): void {
        $cur  = self::current_page();
        $tabs = self::embedded_tabs();
        if ( $cur !== self::HUB_SLUG && ! isset( $tabs[ $cur ] ) ) {
            return;
        }
        if ( $cur === self::HUB_SLUG ) {
            return; // на самом хабе панель рисует render_hub()
        }
        echo '<div class="wrap wphub-wrap wphub-injected">';
        self::tabbar( $cur );
        echo '</div>';
    }

    /* ── Лендинг хаба ─────────────────────────────────────────── */

    public static function render_hub(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Нет доступа' );
        }
        $cfg = self::cfg();
        echo '<div class="wrap wphub-wrap">';
        echo '<h1 style="margin-bottom:6px">' . esc_html( $cfg['hub_title'] ) . '</h1>';
        self::tabbar( self::HUB_SLUG );
        echo '<div class="wphub-cards">';
        foreach ( self::embedded_tabs() as $slug => $tab ) {
            printf(
                '<a class="wphub-card" href="%s">%s</a>',
                esc_url( admin_url( 'admin.php?page=' . $slug ) ),
                esc_html( $tab['label'] )
            );
        }
        echo '</div>';
        if ( ! self::embedded_tabs() ) {
            echo '<p>Пока ничего не встроено. Откройте <a href="' .
                esc_url( admin_url( 'options-general.php?page=' . self::SETTINGS_SLUG ) ) .
                '">настройки WooCommerce Menu Hub</a> и отметьте пункты «Встроить».</p>';
        }
        echo '</div>';
    }

    /* ── Стили ────────────────────────────────────────────────── */

    public static function styles(): void {
        $cur  = self::current_page();
        $tabs = self::embedded_tabs();
        if ( $cur !== self::HUB_SLUG && $cur !== self::SETTINGS_SLUG && ! isset( $tabs[ $cur ] ) ) {
            return;
        }
        ?>
        <style>
        .wphub-tabs{display:flex;flex-wrap:wrap;gap:2px;margin:14px 0 0;border-bottom:2px solid #dcdcde}
        .wphub-tab{padding:9px 18px;text-decoration:none;font-size:13px;font-weight:600;color:#50575e;
            border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;background:transparent}
        .wphub-tab:hover{color:#2271b1;background:#f0f6ff}
        .wphub-tab.active{background:#fff;color:#2271b1;border-color:#dcdcde;margin-bottom:-2px;border-bottom-color:#fff}
        .wphub-tab-home{font-weight:700}
        .wphub-injected{margin:10px 20px 0 2px}
        .wphub-cards{display:flex;flex-wrap:wrap;gap:12px;margin-top:22px}
        .wphub-card{display:block;min-width:180px;padding:18px 20px;background:#fff;border:1px solid #dcdcde;
            border-radius:8px;text-decoration:none;font-size:15px;font-weight:600;color:#1d2327;
            box-shadow:0 1px 2px rgba(0,0,0,.04)}
        .wphub-card:hover{border-color:#2271b1;color:#2271b1}
        .wphub-settings-table td,.wphub-settings-table th{padding:6px 10px;vertical-align:middle}
        .wphub-settings-table .wphub-parent{background:#f6f7f7;font-weight:600}
        .wphub-settings-table select{min-width:130px}
        .wphub-sub-slug{color:#888;font-size:11px}
        </style>
        <?php
    }

    /* ── Страница настроек: список всех пунктов меню ──────────── */

    public static function render_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Нет доступа' );
        }

        // Сохранение
        if ( isset( $_POST['wphub_save'] ) && check_admin_referer( 'wphub_settings' ) ) {
            $cfg = self::cfg();
            $cfg['hub_title'] = sanitize_text_field( wp_unslash( $_POST['hub_title'] ?? '📦 Отправка' ) );
            $cfg['hub_icon']  = sanitize_text_field( wp_unslash( $_POST['hub_icon'] ?? 'dashicons-archive' ) );
            $actions = isset( $_POST['wphub'] ) && is_array( $_POST['wphub'] ) ? wp_unslash( $_POST['wphub'] ) : [];
            $labels_in = isset( $_POST['wphub_label'] ) && is_array( $_POST['wphub_label'] ) ? wp_unslash( $_POST['wphub_label'] ) : [];
            $items = [];
            $labels = [];
            foreach ( $actions as $key => $action ) {
                $key = sanitize_text_field( $key );
                $action = in_array( $action, [ 'show', 'hide', 'embed' ], true ) ? $action : 'show';
                if ( $action !== 'show' ) {
                    $items[ $key ] = $action;
                }
                if ( isset( $labels_in[ $key ] ) ) {
                    $labels[ $key ] = sanitize_text_field( $labels_in[ $key ] );
                }
            }
            $cfg['items']  = $items;
            $cfg['labels'] = $labels;
            update_option( self::OPT, $cfg );
            echo '<div class="notice notice-success is-dismissible"><p>Сохранено. Обновите страницу — меню перестроится.</p></div>';
        }

        $cfg = self::cfg();

        global $menu, $submenu;
        ?>
        <div class="wrap wphub-wrap">
            <h1>WooCommerce Menu Hub</h1>
            <p>Отметьте, что сделать с каждым пунктом меню: <b>Показать</b> (как есть), <b>Скрыть</b> (убрать из меню), <b>Встроить</b> (как вкладку в единый хаб «<?php echo esc_html( $cfg['hub_title'] ); ?>»).</p>
            <form method="post">
                <?php wp_nonce_field( 'wphub_settings' ); ?>

                <table class="form-table" style="max-width:560px">
                    <tr>
                        <th>Название хаба</th>
                        <td><input type="text" name="hub_title" value="<?php echo esc_attr( $cfg['hub_title'] ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Иконка (dashicon)</th>
                        <td><input type="text" name="hub_icon" value="<?php echo esc_attr( $cfg['hub_icon'] ); ?>" class="regular-text" placeholder="dashicons-archive">
                            <p class="description"><a href="https://developer.wordpress.org/resource/dashicons/" target="_blank">список dashicons</a></p></td>
                    </tr>
                </table>

                <table class="widefat striped wphub-settings-table" style="max-width:920px;margin-top:10px">
                    <thead><tr><th style="width:46%">Пункт меню</th><th>Слаг</th><th style="width:160px">Действие</th><th>Подпись вкладки</th></tr></thead>
                    <tbody>
                    <?php
                    if ( is_array( $menu ) ) {
                        foreach ( $menu as $m ) {
                            $top_title = isset( $m[0] ) ? trim( wp_strip_all_tags( $m[0] ) ) : '';
                            $top_slug  = isset( $m[2] ) ? $m[2] : '';
                            // разделители (separator…) и пустые пропускаем
                            if ( $top_slug === '' || preg_match( '/^separator/', $top_slug ) ) {
                                continue;
                            }
                            self::render_row( $top_slug, $top_title, '', $cfg, true );

                            if ( isset( $submenu[ $top_slug ] ) && is_array( $submenu[ $top_slug ] ) ) {
                                foreach ( $submenu[ $top_slug ] as $sm ) {
                                    $sub_title = isset( $sm[0] ) ? trim( wp_strip_all_tags( $sm[0] ) ) : '';
                                    $sub_slug  = isset( $sm[2] ) ? $sm[2] : '';
                                    if ( $sub_slug === '' ) {
                                        continue;
                                    }
                                    self::render_row( $sub_slug, $sub_title, $top_slug, $cfg, false );
                                }
                            }
                        }
                    }
                    ?>
                    </tbody>
                </table>

                <p style="margin-top:14px"><input type="submit" name="wphub_save" class="button button-primary" value="Сохранить"></p>
            </form>
        </div>
        <?php
    }

    private static function render_row( string $slug, string $title, string $parent, array $cfg, bool $is_top ): void {
        $key       = $parent !== '' ? $parent . '>' . $slug : $slug;
        $action    = $cfg['items'][ $key ] ?? 'show';
        $label     = $cfg['labels'][ $key ] ?? $title;
        $protected = in_array( $slug, self::PROTECTED, true );
        $rowcls    = $is_top ? ' class="wphub-parent"' : '';
        ?>
        <tr<?php echo $rowcls; ?>>
            <td><?php echo $is_top ? '' : '&nbsp;&nbsp;↳ '; echo esc_html( $title ?: '(без названия)' ); ?></td>
            <td><span class="wphub-sub-slug"><?php echo esc_html( $key ); ?></span></td>
            <td>
                <?php if ( $protected ) : ?>
                    <em style="color:#888">защищён</em>
                <?php else : ?>
                    <select name="wphub[<?php echo esc_attr( $key ); ?>]">
                        <option value="show"  <?php selected( $action, 'show' ); ?>>Показать</option>
                        <option value="hide"  <?php selected( $action, 'hide' ); ?>>Скрыть</option>
                        <option value="embed" <?php selected( $action, 'embed' ); ?>>Встроить</option>
                    </select>
                <?php endif; ?>
            </td>
            <td>
                <?php if ( ! $protected ) : ?>
                    <input type="text" name="wphub_label[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php echo esc_attr( $title ); ?>" style="width:160px">
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}

add_action( 'plugins_loaded', function () {
    WP_Hub::init();
} );
