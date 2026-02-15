<?php
// Single menu renderer used by both mobile and desktop layouts
// Usage: include_once __DIR__ . '/menu.php'; renderMenu('mobile'|'desktop');

function getMenuItems()
{
    return [
        [ 'label' => t('navbar_home'), 'icon' => 'fas fa-home', 'href' => 'dashboard.php' ],
        [ 'label' => t('navbar_bot_control'), 'icon' => 'fas fa-robot', 'href' => 'bot.php' ],
        [ 'label' => t('navbar_commands'), 'icon' => 'fas fa-terminal', 'submenu' => [
            [ 'label' => t('navbar_view_custom_commands'), 'icon' => 'fas fa-terminal', 'href' => 'custom_commands.php' ],
            [ 'label' => t('navbar_manage_user_commands'), 'icon' => 'fas fa-user-cog', 'href' => 'manage_custom_user_commands.php' ],
            [ 'label' => t('navbar_view_builtin_commands'), 'icon' => 'fas fa-terminal', 'href' => 'builtin.php' ],
        ]],
        [ 'label' => 'Settings', 'icon' => 'fas fa-cogs', 'submenu' => [
            [ 'label' => t('navbar_timed_messages'), 'icon' => 'fas fa-clock', 'href' => 'timed_messages.php' ],
            [ 'label' => t('navbar_points_system'), 'icon' => 'fas fa-coins', 'href' => 'bot_points.php' ],
            [ 'label' => t('navbar_subathon'), 'icon' => 'fas fa-hourglass-half', 'href' => 'subathon.php' ],
            [ 'label' => t('navbar_welcome_messages'), 'icon' => 'fas fa-users', 'href' => 'known_users.php' ],
            [ 'label' => t('navbar_channel_rewards'), 'icon' => 'fas fa-gift', 'href' => 'channel_rewards.php' ],
        ]],
        [ 'label' => t('navbar_analytics'), 'icon' => 'fas fa-chart-line', 'submenu' => [
            [ 'label' => t('navbar_bot_logs'), 'icon' => 'fas fa-clipboard-list', 'href' => 'logs.php' ],
            [ 'label' => 'EventSub Notifications', 'icon' => 'fas fa-bell', 'href' => 'notifications.php' ],
            [ 'label' => t('navbar_counters'), 'icon' => 'fas fa-calculator', 'href' => 'counters.php' ],
            [ 'label' => 'Schedule', 'icon' => 'fas fa-calendar-days', 'href' => 'schedule.php' ],
            [ 'label' => t('navbar_followers'), 'icon' => 'fas fa-user-plus', 'href' => 'followers.php' ],
            [ 'label' => t('navbar_subscribers'), 'icon' => 'fas fa-star', 'href' => 'subscribers.php' ],
            [ 'label' => t('navbar_moderators'), 'icon' => 'fas fa-user-shield', 'href' => 'mods.php' ],
            [ 'label' => t('navbar_vips'), 'icon' => 'fas fa-crown', 'href' => 'vips.php' ],
            [ 'label' => t('navbar_raids'), 'icon' => 'fas fa-bullhorn', 'href' => 'raids.php' ],
        ]],
        [ 'label' => t('navbar_stream_tools'), 'icon' => 'fas fa-video', 'submenu' => [
            [ 'label' => t('navbar_recording'), 'icon' => 'fas fa-video', 'href' => 'streaming.php' ],
            [ 'label' => t('navbar_overlays'), 'icon' => 'fas fa-layer-group', 'href' => 'overlays.php' ],
            [ 'label' => t('navbar_sound_alerts'), 'icon' => 'fas fa-volume-up', 'href' => 'sound-alerts.php' ],
            [ 'label' => t('navbar_video_alerts'), 'icon' => 'fas fa-film', 'href' => 'video-alerts.php' ],
            [ 'label' => t('navbar_walkon_alerts'), 'icon' => 'fas fa-door-open', 'href' => 'walkons.php' ],
        ]],
        [ 'label' => t('navbar_integrations'), 'icon' => 'fas fa-plug', 'submenu' => [
            [ 'label' => t('navbar_specter_modules'), 'icon' => 'fa fa-puzzle-piece', 'href' => 'modules.php' ],
            [ 'label' => t('navbar_discord_bot'), 'icon' => 'fab fa-discord', 'href' => 'discordbot.php' ],
            [ 'label' => t('navbar_spotify'), 'icon' => 'fab fa-spotify', 'href' => 'spotifylink.php' ],
            [ 'label' => t('navbar_streamelements'), 'icon' => 'fas fa-globe', 'href' => 'streamelements.php' ],
            [ 'label' => t('navbar_obsconnector'), 'icon' => 'fas fa-plug', 'href' => 'controllerapp.php' ],
            [ 'label' => t('navbar_stream_bingo'), 'icon' => 'fas fa-trophy', 'href' => 'bingo.php' ],
            [ 'label' => 'Tanggle', 'icon' => 'fas fa-puzzle-piece', 'href' => 'tanggle.php' ],
            [ 'label' => t('navbar_streamlabs'), 'icon' => 'fas fa-gift', 'href' => 'streamlabs.php' ],
            [ 'label' => t('navbar_platform_integrations'), 'icon' => 'fas fa-globe', 'href' => 'integrations.php' ],
        ]],
        [ 'label' => t('navbar_premium'), 'icon' => 'fas fa-crown', 'href' => 'premium.php' ],
        [ 'label' => t('navbar_vod_music'), 'icon' => 'fas fa-music', 'href' => 'music.php' ],
        [ 'label' => t('navbar_raffles'), 'icon' => 'fas fa-ticket-alt', 'href' => 'raffles.php' ],
        [ 'label' => t('navbar_todo_list'), 'icon' => 'fas fa-list-check', 'href' => 'todolist/index.php' ],
    ];
}

function renderMenu($mode = 'desktop')
{
    $items = getMenuItems();
    $isMobile = ($mode === 'mobile');

    echo "<ul class=\"sidebar-menu\">\n";
    foreach ($items as $item) {
        $hasSub = isset($item['submenu']) && is_array($item['submenu']);
        $liClass = 'sidebar-menu-item' . ($hasSub ? ' has-submenu' : '');
        echo "    <li class=\"{$liClass}\">\n";

        if ($hasSub) {
            echo "        <a href=\"#\" class=\"sidebar-menu-link\" onclick=\"toggleSubmenu(event,this)\">\n";
            echo "            <span class=\"sidebar-menu-icon\"><i class=\"{$item['icon']}\"></i></span>\n";
            echo "            <span class=\"sidebar-menu-text\">{$item['label']}</span>\n";
            echo "            <span class=\"sidebar-submenu-toggle\"><i class=\"fas fa-chevron-down\"></i></span>\n";
            echo "        </a>\n";
            if (!$isMobile) {
                echo "        <div class=\"sidebar-tooltip\">{$item['label']}</div>\n";
            }
            echo "        <ul class=\"sidebar-submenu\">\n";
            foreach ($item['submenu'] as $sub) {
                echo "            <li>\n";
                echo "                <a href=\"{$sub['href']}\" class=\"sidebar-submenu-link\">\n";
                echo "                    <span class=\"sidebar-submenu-icon\"><i class=\"{$sub['icon']}\"></i></span>\n";
                echo "                    <span class=\"sidebar-menu-text\">{$sub['label']}</span>\n";
                echo "                </a>\n";
                echo "            </li>\n";
            }
            echo "        </ul>\n";
        } else {
            $href = isset($item['href']) ? $item['href'] : '#';
            echo "        <a href=\"{$href}\" class=\"sidebar-menu-link\">\n";
            echo "            <span class=\"sidebar-menu-icon\"><i class=\"{$item['icon']}\"></i></span>\n";
            echo "            <span class=\"sidebar-menu-text\">{$item['label']}</span>\n";
            echo "        </a>\n";
            if (!$isMobile) {
                echo "        <div class=\"sidebar-tooltip\">{$item['label']}</div>\n";
            }
        }

        echo "    </li>\n";
    }
    echo "</ul>\n";
}
