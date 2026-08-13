<?php
require_once '/var/www/lib/session_bootstrap.php';
require_once '/var/www/lib/require_auth.php';
include "/var/www/config/db_connect.php";
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$pageTitle = t('navbar_obsconnector') ?? 'Controller App';

// Include files for database and user data
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php';
session_write_close();

$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Stable public release (legacy connector). Desktop Electron rewrite (v2) is still in development — do not ship a download for it yet.
$obsconnectorVersion = "1.1";
$desktopNextVersion = "2.0.0";
$githubReleasesUrl = "https://github.com/YourStreamingTools/BotOfTheSpecter-OBS-Connector/releases";
$downloadUrl = "https://cdn.botofthespecter.com/app-builds/OBSConnector/BotOfTheSpecter-OBS-Connector-v{$obsconnectorVersion}.exe";
$docsUrl = "https://app.botofthespecter.com";

// Stream Deck Plugin version and download information (matches plugin manifest Version)
$streamdeckVersion = "1.0.0.2";
$streamdeckDownloadUrl = "https://cdn.botofthespecter.com/app-builds/StreamDeck/BotOfTheSpecter-$streamdeckVersion.streamDeckPlugin";
$streamdeckProfileUrl = "profile.php";

ob_start();
?>
<div class="sp-card">
    <header class="sp-card-header">
        <span class="sp-card-title">
            <i class="fas fa-plug"></i>
            <?php echo t('obsconnector_title'); ?>
        </span>
        <span class="sp-badge sp-badge-blue">
            <i class="fas fa-cube"></i>
            <?php echo sprintf(t('obsconnector_version_tag'), $obsconnectorVersion); ?>
        </span>
        <span class="sp-badge sp-badge-amber" style="margin-left:0.4rem;">
            <i class="fas fa-flask"></i>
            <?php echo sprintf(t('obsconnector_desktop_next_badge'), $desktopNextVersion); ?>
        </span>
    </header>
    <div class="sp-card-body">
        <!-- Description section -->
        <div style="text-align:center;padding:1rem 2rem 2rem;">
            <p style="font-size:1.05rem;font-weight:600;color:var(--text-primary);margin-bottom:0.75rem;">
                <?php echo t('obsconnector_banner_title'); ?>
            </p>
            <p style="max-width:720px;margin:0 auto;line-height:1.6;color:var(--text-secondary);">
                <?php echo t('obsconnector_banner_p1'); ?>
            </p>
        </div>
        <!-- Desktop rewrite preview (not downloadable yet) -->
        <div class="sp-card" style="margin:0 auto 2rem;max-width:800px;border-color:var(--amber, #d97706);">
            <div class="sp-card-body" style="text-align:center;">
                <p style="font-size:0.95rem;font-weight:600;color:var(--text-primary);margin-bottom:0.5rem;">
                    <i class="fas fa-hammer" style="margin-right:0.4rem;color:var(--amber);"></i>
                    <?php echo sprintf(t('obsconnector_desktop_next_title'), $desktopNextVersion); ?>
                </p>
                <p style="font-size:0.85rem;color:var(--text-muted);line-height:1.55;margin:0;">
                    <?php echo t('obsconnector_desktop_next_note'); ?>
                </p>
            </div>
        </div>
        <!-- Features section (stable connector + roadmap for Desktop) -->
        <div style="margin:2rem 0;">
            <p style="font-size:0.9rem;font-weight:600;color:var(--text-primary);margin-bottom:1rem;">
                <i class="fas fa-star" style="margin-right:0.4rem;color:var(--accent-hover);"></i>
                <?php echo t('obsconnector_keyfeatures_title'); ?>
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;">
                <!-- Scene Control (Desktop) -->
                <div class="sp-card" style="margin-bottom:0;opacity:0.85;position:relative;">
                    <div style="position:absolute;top:0.75rem;right:0.75rem;">
                        <span class="sp-badge sp-badge-amber"><?php echo t('obsconnector_coming_soon_badge'); ?></span>
                    </div>
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--green);margin-bottom:0.75rem;"><i class="fas fa-object-group"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('obsconnector_feature_scene_control_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('obsconnector_feature_scene_control_desc'); ?></p>
                    </div>
                </div>
                <!-- Source & Audio (Desktop) -->
                <div class="sp-card" style="margin-bottom:0;opacity:0.85;position:relative;">
                    <div style="position:absolute;top:0.75rem;right:0.75rem;">
                        <span class="sp-badge sp-badge-amber"><?php echo t('obsconnector_coming_soon_badge'); ?></span>
                    </div>
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--blue);margin-bottom:0.75rem;"><i class="fas fa-sliders-h"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('obsconnector_feature_source_management_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('obsconnector_feature_source_management_desc'); ?></p>
                    </div>
                </div>
                <!-- Live Dashboard (Desktop) -->
                <div class="sp-card" style="margin-bottom:0;opacity:0.85;position:relative;">
                    <div style="position:absolute;top:0.75rem;right:0.75rem;">
                        <span class="sp-badge sp-badge-amber"><?php echo t('obsconnector_coming_soon_badge'); ?></span>
                    </div>
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--amber);margin-bottom:0.75rem;"><i class="fas fa-tachometer-alt"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('obsconnector_feature_realtime_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('obsconnector_feature_realtime_desc'); ?></p>
                    </div>
                </div>
                <!-- Chat & Moderation (Desktop) -->
                <div class="sp-card" style="margin-bottom:0;opacity:0.85;position:relative;">
                    <div style="position:absolute;top:0.75rem;right:0.75rem;">
                        <span class="sp-badge sp-badge-amber"><?php echo t('obsconnector_coming_soon_badge'); ?></span>
                    </div>
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--blue);margin-bottom:0.75rem;"><i class="fas fa-comments"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('obsconnector_feature_chat_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('obsconnector_feature_chat_desc'); ?></p>
                    </div>
                </div>
                <!-- Channel Points (Desktop) -->
                <div class="sp-card" style="margin-bottom:0;opacity:0.85;position:relative;">
                    <div style="position:absolute;top:0.75rem;right:0.75rem;">
                        <span class="sp-badge sp-badge-amber"><?php echo t('obsconnector_coming_soon_badge'); ?></span>
                    </div>
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--accent-hover);margin-bottom:0.75rem;"><i class="fas fa-coins"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('obsconnector_feature_channel_points_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('obsconnector_feature_channel_points_desc'); ?></p>
                    </div>
                </div>
                <!-- Engagement (Desktop) -->
                <div class="sp-card" style="margin-bottom:0;opacity:0.85;position:relative;">
                    <div style="position:absolute;top:0.75rem;right:0.75rem;">
                        <span class="sp-badge sp-badge-amber"><?php echo t('obsconnector_coming_soon_badge'); ?></span>
                    </div>
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--amber);margin-bottom:0.75rem;"><i class="fas fa-gift"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('obsconnector_feature_engagement_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('obsconnector_feature_engagement_desc'); ?></p>
                    </div>
                </div>
                <!-- Bot Relay (available via stable + Desktop) -->
                <div class="sp-card" style="margin-bottom:0;">
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--green);margin-bottom:0.75rem;"><i class="fas fa-robot"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('obsconnector_feature_bot_integration_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('obsconnector_feature_bot_integration_desc'); ?></p>
                    </div>
                </div>
                <!-- Automations (Desktop) -->
                <div class="sp-card" style="margin-bottom:0;opacity:0.85;position:relative;">
                    <div style="position:absolute;top:0.75rem;right:0.75rem;">
                        <span class="sp-badge sp-badge-amber"><?php echo t('obsconnector_coming_soon_badge'); ?></span>
                    </div>
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--red);margin-bottom:0.75rem;"><i class="fas fa-cogs"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('obsconnector_feature_automation_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('obsconnector_feature_automation_desc'); ?></p>
                    </div>
                </div>
                <!-- Secure -->
                <div class="sp-card" style="margin-bottom:0;">
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--accent-hover);margin-bottom:0.75rem;"><i class="fas fa-lock"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('obsconnector_feature_secure_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('obsconnector_feature_secure_desc'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <!-- Requirements -->
        <div style="max-width:800px;margin:0 auto 1.5rem;padding:0 0.5rem;">
            <p style="font-size:0.85rem;font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;">
                <i class="fas fa-info-circle" style="margin-right:0.35rem;color:var(--accent-hover);"></i>
                <?php echo t('obsconnector_requirements_title'); ?>
            </p>
            <p style="font-size:0.8rem;color:var(--text-muted);line-height:1.55;margin:0;">
                <?php echo t('obsconnector_requirements_note'); ?>
            </p>
        </div>
        <!-- Download section — stable v1.1 only; Desktop v2 is not offered yet -->
        <div class="sp-card" style="margin-bottom:0;max-width:800px;margin-left:auto;margin-right:auto;">
            <div class="sp-card-body" style="text-align:center;">
                <p style="font-size:0.95rem;font-weight:600;color:var(--text-primary);margin-bottom:0.75rem;">
                    <i class="fas fa-download" style="margin-right:0.4rem;"></i>
                    <?php echo t('obsconnector_download_title'); ?>
                </p>
                <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:1.25rem;">
                    <?php echo t('obsconnector_download_note'); ?>
                </p>
                <div style="display:flex;flex-wrap:wrap;gap:0.75rem;justify-content:center;">
                    <a href="<?php echo htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8'); ?>" class="sp-btn sp-btn-success">
                        <i class="fas fa-download"></i>
                        <span><?php echo sprintf(t('obsconnector_download_button'), $obsconnectorVersion); ?></span>
                    </a>
                    <a href="<?php echo htmlspecialchars($docsUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="sp-btn sp-btn-secondary">
                        <i class="fas fa-book"></i>
                        <span><?php echo t('obsconnector_view_docs'); ?></span>
                    </a>
                </div>
                <p style="font-size:0.75rem;color:var(--text-muted);margin:1rem 0 0;">
                    <?php echo sprintf(t('obsconnector_download_desktop_hold'), $desktopNextVersion); ?>
                </p>
            </div>
        </div>
    </div>
</div>

<div class="sp-card" style="margin-top:2rem;">
    <header class="sp-card-header">
        <span class="sp-card-title">
            <i class="fas fa-th-large"></i>
            <?php echo t('streamdeck_title'); ?>
        </span>
        <span class="sp-badge sp-badge-blue">
            <i class="fas fa-cube"></i>
            <?php echo sprintf(t('streamdeck_version_tag'), $streamdeckVersion); ?>
        </span>
    </header>
    <div class="sp-card-body">
        <!-- Description section -->
        <div style="text-align:center;padding:1rem 2rem 2rem;">
            <p style="font-size:1.05rem;font-weight:600;color:var(--text-primary);margin-bottom:0.75rem;">
                <?php echo t('streamdeck_banner_title'); ?>
            </p>
            <p style="max-width:720px;margin:0 auto;line-height:1.6;color:var(--text-secondary);">
                <?php echo t('streamdeck_banner_p1'); ?>
            </p>
        </div>
        <!-- Features section -->
        <div style="margin:2rem 0;">
            <p style="font-size:0.9rem;font-weight:600;color:var(--text-primary);margin-bottom:1rem;">
                <i class="fas fa-star" style="margin-right:0.4rem;color:var(--accent-hover);"></i>
                <?php echo t('streamdeck_keyfeatures_title'); ?>
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;">
                <!-- Trigger Sound Alerts -->
                <div class="sp-card" style="margin-bottom:0;">
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--amber);margin-bottom:0.75rem;"><i class="fas fa-volume-up"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('streamdeck_feature_soundalerts_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('streamdeck_feature_soundalerts_desc'); ?></p>
                    </div>
                </div>
                <!-- Trigger Custom Commands -->
                <div class="sp-card" style="margin-bottom:0;">
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--blue);margin-bottom:0.75rem;"><i class="fas fa-terminal"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('streamdeck_feature_customcommands_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('streamdeck_feature_customcommands_desc'); ?></p>
                    </div>
                </div>
                <!-- Global API Key -->
                <div class="sp-card" style="margin-bottom:0;">
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--accent-hover);margin-bottom:0.75rem;"><i class="fas fa-key"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('streamdeck_feature_apikey_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('streamdeck_feature_apikey_desc'); ?></p>
                    </div>
                </div>
                <!-- Trigger Video Alerts (coming soon) -->
                <div class="sp-card" style="margin-bottom:0;opacity:0.7;position:relative;">
                    <div style="position:absolute;top:0.75rem;right:0.75rem;">
                        <span class="sp-badge sp-badge-amber"><?php echo t('obsconnector_coming_soon_badge'); ?></span>
                    </div>
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--red);margin-bottom:0.75rem;"><i class="fas fa-film"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('streamdeck_feature_videoalerts_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('streamdeck_feature_videoalerts_desc'); ?></p>
                    </div>
                </div>
                <!-- Trigger Walkons Manually (coming soon) -->
                <div class="sp-card" style="margin-bottom:0;opacity:0.7;position:relative;">
                    <div style="position:absolute;top:0.75rem;right:0.75rem;">
                        <span class="sp-badge sp-badge-amber"><?php echo t('obsconnector_coming_soon_badge'); ?></span>
                    </div>
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--green);margin-bottom:0.75rem;"><i class="fas fa-walking"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('streamdeck_feature_walkons_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('streamdeck_feature_walkons_desc'); ?></p>
                    </div>
                </div>
                <!-- ConnectorApp Integration (coming soon) -->
                <div class="sp-card" style="margin-bottom:0;opacity:0.7;position:relative;">
                    <div style="position:absolute;top:0.75rem;right:0.75rem;">
                        <span class="sp-badge sp-badge-amber"><?php echo t('obsconnector_coming_soon_badge'); ?></span>
                    </div>
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--green);margin-bottom:0.75rem;"><i class="fas fa-plug"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('streamdeck_feature_connectorapp_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('streamdeck_feature_connectorapp_desc'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <!-- Quick setup -->
        <div style="max-width:800px;margin:0 auto 1.5rem;padding:0 0.5rem;">
            <p style="font-size:0.85rem;font-weight:600;color:var(--text-primary);margin-bottom:0.75rem;">
                <i class="fas fa-list-ol" style="margin-right:0.35rem;color:var(--accent-hover);"></i>
                <?php echo t('streamdeck_setup_title'); ?>
            </p>
            <ol style="margin:0;padding-left:1.25rem;font-size:0.8rem;color:var(--text-muted);line-height:1.65;">
                <li><?php echo t('streamdeck_setup_step1'); ?></li>
                <li><?php echo t('streamdeck_setup_step2'); ?></li>
                <li><?php echo t('streamdeck_setup_step3'); ?></li>
                <li><?php echo t('streamdeck_setup_step4'); ?></li>
            </ol>
        </div>
        <!-- Requirements -->
        <div style="max-width:800px;margin:0 auto 1.5rem;padding:0 0.5rem;">
            <p style="font-size:0.85rem;font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;">
                <i class="fas fa-info-circle" style="margin-right:0.35rem;color:var(--accent-hover);"></i>
                <?php echo t('streamdeck_requirements_title'); ?>
            </p>
            <p style="font-size:0.8rem;color:var(--text-muted);line-height:1.55;margin:0;">
                <?php echo t('streamdeck_requirements_note'); ?>
            </p>
        </div>
        <!-- Download section -->
        <div class="sp-card" style="margin-bottom:0;max-width:800px;margin-left:auto;margin-right:auto;">
            <div class="sp-card-body" style="text-align:center;">
                <p style="font-size:0.95rem;font-weight:600;color:var(--text-primary);margin-bottom:0.75rem;">
                    <i class="fas fa-download" style="margin-right:0.4rem;"></i>
                    <?php echo t('streamdeck_download_title'); ?>
                </p>
                <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:1.25rem;">
                    <?php echo t('streamdeck_download_note'); ?>
                </p>
                <div style="display:flex;flex-wrap:wrap;gap:0.75rem;justify-content:center;">
                    <a href="<?php echo htmlspecialchars($streamdeckDownloadUrl, ENT_QUOTES, 'UTF-8'); ?>" class="sp-btn sp-btn-success">
                        <i class="fas fa-download"></i>
                        <span><?php echo sprintf(t('streamdeck_download_button'), $streamdeckVersion); ?></span>
                    </a>
                    <a href="<?php echo htmlspecialchars($streamdeckProfileUrl, ENT_QUOTES, 'UTF-8'); ?>" class="sp-btn sp-btn-secondary">
                        <i class="fas fa-user-cog"></i>
                        <span><?php echo t('streamdeck_profile_link'); ?></span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

include 'layout.php';
?>
