<?php
session_start();
include "/var/www/config/db_connect.php";
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$pageTitle = t('navbar_obsconnector') ?? 'Controller App';

// Include files for database and user data
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';

$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Controller App version and download information
$obsconnectorVersion = "1.1";
$githubReleasesUrl = "https://github.com/YourStreamingTools/BotOfTheSpecter-OBS-Connector/releases";
$downloadUrl = "https://cdn.botofthespecter.com/app-builds/OBSConnector/BotOfTheSpecter-OBS-Connector-v$obsconnectorVersion.exe";

// StreamDeck Plugin version and download information
$streamdeckVersion = "1.0.0.1";
$streamdeckDownloadUrl = "https://cdn.botofthespecter.com/app-builds/StreamDeck/BotOfTheSpecter-$streamdeckVersion.streamDeckPlugin";

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
    </header>
    <div class="sp-card-body">
        <!-- Description section -->
        <div style="text-align:center;padding:1rem 2rem 2rem;">
            <p style="font-size:1.05rem;font-weight:600;color:var(--text-primary);margin-bottom:0.75rem;">
                <?php echo t('obsconnector_banner_title'); ?>
            </p>
            <p style="max-width:700px;margin:0 auto;line-height:1.6;color:var(--text-secondary);">
                <?php echo t('obsconnector_banner_p1'); ?>
            </p>
        </div>
        <!-- Features section -->
        <div style="margin:2rem 0;">
            <p style="font-size:0.9rem;font-weight:600;color:var(--text-primary);margin-bottom:1rem;">
                <i class="fas fa-star" style="margin-right:0.4rem;color:var(--accent-hover);"></i>
                <?php echo t('obsconnector_keyfeatures_title'); ?>
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;">
                <!-- Scene Control (coming soon) -->
                <div class="sp-card" style="margin-bottom:0;opacity:0.7;position:relative;">
                    <div style="position:absolute;top:0.75rem;right:0.75rem;">
                        <span class="sp-badge sp-badge-amber"><?php echo t('obsconnector_coming_soon_badge'); ?></span>
                    </div>
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:#00d1b2;margin-bottom:0.75rem;"><i class="fas fa-object-group"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('obsconnector_feature_scene_control_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('obsconnector_feature_scene_control_desc'); ?></p>
                    </div>
                </div>
                <!-- Source Management (coming soon) -->
                <div class="sp-card" style="margin-bottom:0;opacity:0.7;position:relative;">
                    <div style="position:absolute;top:0.75rem;right:0.75rem;">
                        <span class="sp-badge sp-badge-amber"><?php echo t('obsconnector_coming_soon_badge'); ?></span>
                    </div>
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--blue);margin-bottom:0.75rem;"><i class="fas fa-sliders-h"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('obsconnector_feature_source_management_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('obsconnector_feature_source_management_desc'); ?></p>
                    </div>
                </div>
                <!-- Real-time -->
                <div class="sp-card" style="margin-bottom:0;">
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--amber);margin-bottom:0.75rem;"><i class="fas fa-circle-notch"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('obsconnector_feature_realtime_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('obsconnector_feature_realtime_desc'); ?></p>
                    </div>
                </div>
                <!-- Bot Integration -->
                <div class="sp-card" style="margin-bottom:0;">
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--green);margin-bottom:0.75rem;"><i class="fas fa-robot"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('obsconnector_feature_bot_integration_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('obsconnector_feature_bot_integration_desc'); ?></p>
                    </div>
                </div>
                <!-- Automation (coming soon) -->
                <div class="sp-card" style="margin-bottom:0;opacity:0.7;position:relative;">
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
        <!-- Download section -->
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
                    <a href="<?php echo $downloadUrl; ?>" class="sp-btn sp-btn-success">
                        <i class="fas fa-download"></i>
                        <span><?php echo sprintf(t('obsconnector_download_button'), $obsconnectorVersion); ?></span>
                    </a>
                    <a href="<?php echo $githubReleasesUrl; ?>" target="_blank" rel="noopener noreferrer" class="sp-btn sp-btn-secondary">
                        <i class="fab fa-github"></i>
                        <span><?php echo t('obsconnector_view_on_github'); ?></span>
                    </a>
                </div>
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
            <p style="max-width:700px;margin:0 auto;line-height:1.6;color:var(--text-secondary);">
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
                <div class="sp-card" style="margin-bottom:0;position:relative;">
                    <div style="position:absolute;top:0.75rem;right:0.75rem;">
                        <span class="sp-badge sp-badge-blue">Bot v5.8 Beta</span>
                    </div>
                    <div class="sp-card-body">
                        <div style="font-size:2rem;color:var(--blue);margin-bottom:0.75rem;"><i class="fas fa-terminal"></i></div>
                        <p style="font-weight:600;color:var(--text-primary);margin-bottom:0.4rem;"><?php echo t('streamdeck_feature_customcommands_title'); ?></p>
                        <p style="font-size:0.8rem;color:var(--text-muted);"><?php echo t('streamdeck_feature_customcommands_desc'); ?></p>
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
                        <div style="font-size:2rem;color:#00d1b2;margin-bottom:0.75rem;"><i class="fas fa-walking"></i></div>
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
                    <a href="<?php echo $streamdeckDownloadUrl; ?>" class="sp-btn sp-btn-success">
                        <i class="fas fa-download"></i>
                        <span><?php echo sprintf(t('streamdeck_download_button'), $streamdeckVersion); ?></span>
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