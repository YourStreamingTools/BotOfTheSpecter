<?php
session_start();
include "/var/www/config/db_connect.php";
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$pageTitle = t('navbar_obsconnector') ?? 'OBSConnector';

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

// OBSConnector version and download information
$obsconnectorVersion = "1.0";
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
                        OBSConnector
                    </span>
                    <div class="se-badges" style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-left: 1rem;">
                        <span class="tag is-info is-medium" style="border-radius: 6px; font-weight: 600;">
                            <span class="icon is-small"><i class="fas fa-cube"></i></span>
                            <span>v<?php echo $obsconnectorVersion; ?></span>
                        </span>
                    </div>
                </div>
            </header>
            <div class="card-content">
                <!-- Description section -->
                <div class="has-text-centered mb-5" style="padding: 1rem 2rem 2rem;">
                    <p class="subtitle is-5 has-text-white mb-3" style="font-weight: 600;">
                        Bridge Your OBS Studio with BotOfTheSpecter
                    </p>
                    <p class="subtitle is-6 has-text-grey-light" style="max-width: 700px; margin: 0 auto; line-height: 1.6;">
                        OBSConnector is a powerful application that seamlessly integrates your OBS Studio with BotOfTheSpecter, enabling real-time scene management, 
                        source control, and automation directly from your bot dashboard. Control your entire stream setup without leaving the dashboard.
                    </p>
                </div>
                <!-- Features section -->
                <div style="margin: 2rem auto; max-width: 1200px;">
                    <p class="subtitle is-6 has-text-white mb-4" style="font-weight: 600;">
                        <span class="icon is-small"><i class="fas fa-star"></i></span>
                        Key Features
                    </p>
                    <div class="columns is-multiline">
                        <div class="column is-half-tablet is-one-third-desktop">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; height: 100%; position: relative; opacity: 0.7;">
                                <div style="position: absolute; top: 0.75rem; right: 0.75rem;">
                                    <span class="tag is-warning is-small" style="border-radius: 4px; font-weight: 600; font-size: 0.75rem;">
                                        Coming Soon
                                    </span>
                                </div>
                                <div class="card-content" style="padding: 1.5rem;">
                                    <div class="icon-box mb-3" style="font-size: 2rem; color: #00d1b2;">
                                        <i class="fas fa-object-group"></i>
                                    </div>
                                    <p class="has-text-weight-semibold has-text-white mb-2">Scene Control</p>
                                    <p class="is-size-7 has-text-grey-light">Switch between OBS scenes instantly from the dashboard or through bot commands.</p>
                                </div>
                            </div>
                        </div>
                        <div class="column is-half-tablet is-one-third-desktop">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; height: 100%; position: relative; opacity: 0.7;">
                                <div style="position: absolute; top: 0.75rem; right: 0.75rem;">
                                    <span class="tag is-warning is-small" style="border-radius: 4px; font-weight: 600; font-size: 0.75rem;">
                                        Coming Soon
                                    </span>
                                </div>
                                <div class="card-content" style="padding: 1.5rem;">
                                    <div class="icon-box mb-3" style="font-size: 2rem; color: #3273dc;">
                                        <i class="fas fa-sliders-h"></i>
                                    </div>
                                    <p class="has-text-weight-semibold has-text-white mb-2">Source Management</p>
                                    <p class="is-size-7 has-text-grey-light">Toggle sources on/off, adjust properties, and manage audio levels with ease.</p>
                                </div>
                            </div>
                        </div>
                        <div class="column is-half-tablet is-one-third-desktop">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; height: 100%;">
                                <div class="card-content" style="padding: 1.5rem;">
                                    <div class="icon-box mb-3" style="font-size: 2rem; color: #f39c12;">
                                        <i class="fas fa-circle-notch"></i>
                                    </div>
                                    <p class="has-text-weight-semibold has-text-white mb-2">Real-time Updates</p>
                                    <p class="is-size-7 has-text-grey-light">Get live notifications of scene changes and streaming status updates.</p>
                                </div>
                            </div>
                        </div>
                        <div class="column is-half-tablet is-one-third-desktop">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; height: 100%;">
                                <div class="card-content" style="padding: 1.5rem;">
                                    <div class="icon-box mb-3" style="font-size: 2rem; color: #48c774;">
                                        <i class="fas fa-robot"></i>
                                    </div>
                                    <p class="has-text-weight-semibold has-text-white mb-2">Bot Integration</p>
                                    <p class="is-size-7 has-text-grey-light">Create custom commands to control your stream from chat.</p>
                                </div>
                            </div>
                        </div>
                        <div class="column is-half-tablet is-one-third-desktop">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; height: 100%; position: relative; opacity: 0.7;">
                                <div style="position: absolute; top: 0.75rem; right: 0.75rem;">
                                    <span class="tag is-warning is-small" style="border-radius: 4px; font-weight: 600; font-size: 0.75rem;">
                                        Coming Soon
                                    </span>
                                </div>
                                <div class="card-content" style="padding: 1.5rem;">
                                    <div class="icon-box mb-3" style="font-size: 2rem; color: #ff3860;">
                                        <i class="fas fa-cogs"></i>
                                    </div>
                                    <p class="has-text-weight-semibold has-text-white mb-2">Automation</p>
                                    <p class="is-size-7 has-text-grey-light">Set up automated workflows to trigger based on stream events.</p>
                                </div>
                            </div>
                        </div>
                        <div class="column is-half-tablet is-one-third-desktop">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; height: 100%;">
                                <div class="card-content" style="padding: 1.5rem;">
                                    <div class="icon-box mb-3" style="font-size: 2rem; color: #9775fa;">
                                        <i class="fas fa-lock"></i>
                                    </div>
                                    <p class="has-text-weight-semibold has-text-white mb-2">Secure Connection</p>
                                    <p class="is-size-7 has-text-grey-light">End-to-end encrypted connection between your PC and dashboard.</p>
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
                                    Download OBSConnector
                                </p>
                                <p class="is-size-7 has-text-grey-light mb-4">
                                    Download the latest version for Windows. For other platforms or source code, visit GitHub.
                                </p>
                                <div class="buttons is-centered is-flex-wrap-wrap">
                                    <a href="<?php echo $downloadUrl; ?>" class="button is-success is-medium" style="border-radius: 8px; font-weight: 600;">
                                        <span class="icon mr-2">
                                            <i class="fas fa-download"></i>
                                        </span>
                                        <span>Download v<?php echo $obsconnectorVersion; ?> (Windows)</span>
                                    </a>
                                    <a href="<?php echo $githubReleasesUrl; ?>" target="_blank" rel="noopener noreferrer" class="button is-dark is-medium" style="border-radius: 8px; font-weight: 600; border: 1px solid #363636;">
                                        <span class="icon mr-2">
                                            <i class="fab fa-github"></i>
                                        </span>
                                        <span>View on GitHub</span>
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
