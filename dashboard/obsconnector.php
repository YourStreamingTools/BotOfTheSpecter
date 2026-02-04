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

ob_start();
?>
<div class="columns is-centered">
    <div class="column is-fullwidth">
        <div class="card has-background-dark has-text-white mb-5" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
            <header class="card-header" style="border-bottom: 1px solid #23272f;">
                <div style="display: flex; align-items: center; flex: 1; min-width: 0;">
                    <span class="card-header-title is-size-4 has-text-white" style="font-weight:700; flex-shrink: 0;">
                        <span class="icon mr-2"><i class="fas fa-plug"></i></span>
                        <?php echo t('obsconnector_title'); ?>
                    </span>
                    <div class="se-badges" style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-left: 1rem;">
                        <span class="tag is-info is-medium" style="border-radius: 6px; font-weight: 600;">
                            <span class="icon is-small"><i class="fas fa-cube"></i></span>
                            <span><?php echo sprintf(t('obsconnector_version_tag'), $obsconnectorVersion); ?></span>
                        </span>
                    </div>
                </div>
            </header>
            <div class="card-content">
                <!-- Description section -->
                <div class="has-text-centered mb-5" style="padding: 1rem 2rem 2rem;">
                    <p class="subtitle is-5 has-text-white mb-3" style="font-weight: 600;">
                        <?php echo t('obsconnector_banner_title'); ?>
                    </p>
                    <p class="subtitle is-6 has-text-grey-light" style="max-width: 700px; margin: 0 auto; line-height: 1.6;">
                        <?php echo t('obsconnector_banner_p1'); ?>
                    </p>
                </div>
                <!-- Features section -->
                <div style="margin: 2rem auto; max-width: 1200px;">
                    <p class="subtitle is-6 has-text-white mb-4" style="font-weight: 600;">
                        <span class="icon is-small"><i class="fas fa-star"></i></span>
                        <?php echo t('obsconnector_keyfeatures_title'); ?>
                    </p>
                    <div class="columns is-multiline">
                        <div class="column is-half-tablet is-one-third-desktop">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; height: 100%; position: relative; opacity: 0.7;">
                                <div style="position: absolute; top: 0.75rem; right: 0.75rem;">
                                    <span class="tag is-warning is-small" style="border-radius: 4px; font-weight: 600; font-size: 0.75rem;">
                                        <?php echo t('obsconnector_coming_soon_badge'); ?>
                                    </span>
                                </div>
                                <div class="card-content" style="padding: 1.5rem;">
                                    <div class="icon-box mb-3" style="font-size: 2rem; color: #00d1b2;">
                                        <i class="fas fa-object-group"></i>
                                    </div>
                                    <p class="has-text-weight-semibold has-text-white mb-2"><?php echo t('obsconnector_feature_scene_control_title'); ?></p>
                                    <p class="is-size-7 has-text-grey-light"><?php echo t('obsconnector_feature_scene_control_desc'); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="column is-half-tablet is-one-third-desktop">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; height: 100%; position: relative; opacity: 0.7;">
                                <div style="position: absolute; top: 0.75rem; right: 0.75rem;">
                                    <span class="tag is-warning is-small" style="border-radius: 4px; font-weight: 600; font-size: 0.75rem;">
                                        <?php echo t('obsconnector_coming_soon_badge'); ?>
                                    </span>
                                </div>
                                <div class="card-content" style="padding: 1.5rem;">
                                    <div class="icon-box mb-3" style="font-size: 2rem; color: #3273dc;">
                                        <i class="fas fa-sliders-h"></i>
                                    </div>
                                    <p class="has-text-weight-semibold has-text-white mb-2"><?php echo t('obsconnector_feature_source_management_title'); ?></p>
                                    <p class="is-size-7 has-text-grey-light"><?php echo t('obsconnector_feature_source_management_desc'); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="column is-half-tablet is-one-third-desktop">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; height: 100%;">
                                <div class="card-content" style="padding: 1.5rem;">
                                    <div class="icon-box mb-3" style="font-size: 2rem; color: #f39c12;">
                                        <i class="fas fa-circle-notch"></i>
                                    </div>
                                    <p class="has-text-weight-semibold has-text-white mb-2"><?php echo t('obsconnector_feature_realtime_title'); ?></p>
                                    <p class="is-size-7 has-text-grey-light"><?php echo t('obsconnector_feature_realtime_desc'); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="column is-half-tablet is-one-third-desktop">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; height: 100%;">
                                <div class="card-content" style="padding: 1.5rem;">
                                    <div class="icon-box mb-3" style="font-size: 2rem; color: #48c774;">
                                        <i class="fas fa-robot"></i>
                                    </div>
                                    <p class="has-text-weight-semibold has-text-white mb-2"><?php echo t('obsconnector_feature_bot_integration_title'); ?></p>
                                    <p class="is-size-7 has-text-grey-light"><?php echo t('obsconnector_feature_bot_integration_desc'); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="column is-half-tablet is-one-third-desktop">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; height: 100%; position: relative; opacity: 0.7;">
                                <div style="position: absolute; top: 0.75rem; right: 0.75rem;">
                                    <span class="tag is-warning is-small" style="border-radius: 4px; font-weight: 600; font-size: 0.75rem;">
                                        <?php echo t('obsconnector_coming_soon_badge'); ?>
                                    </span>
                                </div>
                                <div class="card-content" style="padding: 1.5rem;">
                                    <div class="icon-box mb-3" style="font-size: 2rem; color: #ff3860;">
                                        <i class="fas fa-cogs"></i>
                                    </div>
                                    <p class="has-text-weight-semibold has-text-white mb-2"><?php echo t('obsconnector_feature_automation_title'); ?></p>
                                    <p class="is-size-7 has-text-grey-light"><?php echo t('obsconnector_feature_automation_desc'); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="column is-half-tablet is-one-third-desktop">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; height: 100%;">
                                <div class="card-content" style="padding: 1.5rem;">
                                    <div class="icon-box mb-3" style="font-size: 2rem; color: #9775fa;">
                                        <i class="fas fa-lock"></i>
                                    </div>
                                    <p class="has-text-weight-semibold has-text-white mb-2"><?php echo t('obsconnector_feature_secure_title'); ?></p>
                                    <p class="is-size-7 has-text-grey-light"><?php echo t('obsconnector_feature_secure_desc'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Download section -->
                <div style="margin: 2rem auto 0; max-width: 800px;">
                    <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636;">
                        <div class="card-content" style="padding: 2rem;">
                            <div class="has-text-centered">
                                <p class="subtitle is-6 has-text-white mb-4" style="font-weight: 600;">
                                    <span class="icon is-small"><i class="fas fa-download"></i></span>
                                    <?php echo t('obsconnector_download_title'); ?>
                                </p>
                                <p class="is-size-7 has-text-grey-light mb-4">
                                    <?php echo t('obsconnector_download_note'); ?>
                                </p>
                                <div class="buttons is-centered is-flex-wrap-wrap">
                                    <a href="<?php echo $downloadUrl; ?>" class="button is-success is-medium" style="border-radius: 8px; font-weight: 600;">
                                        <span class="icon mr-2">
                                            <i class="fas fa-download"></i>
                                        </span>
                                        <span><?php echo sprintf(t('obsconnector_download_button'), $obsconnectorVersion); ?></span>
                                    </a>
                                    <a href="<?php echo $githubReleasesUrl; ?>" target="_blank" rel="noopener noreferrer" class="button is-dark is-medium" style="border-radius: 8px; font-weight: 600; border: 1px solid #363636;">
                                        <span class="icon mr-2">
                                            <i class="fab fa-github"></i>
                                        </span>
                                        <span><?php echo t('obsconnector_view_on_github'); ?></span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

include 'layout.php';
?>
