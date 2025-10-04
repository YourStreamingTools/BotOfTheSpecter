<?php
ob_start();
?>
<section class="hero mb-5">
    <div class="hero-body" style="height: 300px;">
        <div class="container" style="height: 100%;">
            <div class="columns is-vcentered" style="height: 100%;">
                <div class="column is-8" style="display: flex; flex-direction: column; justify-content: center;">
                    <h1 class="title is-1 has-text-weight-bold has-text-light">BotOfTheSpecter Help & Wiki</h1>
                    <p class="subtitle is-4 has-text-light">Your comprehensive guide to setting up and using BotOfTheSpecter, the ultimate streaming bot for Twitch, Discord, and beyond.</p>
                    <div class="buttons">
                        <a href="https://github.com/YourStreamingTools/BotOfTheSpecter" target="_blank" class="button is-large has-text-light">
                            <span class="icon">
                                <i class="fab fa-github"></i>
                            </span>
                            <span>View on GitHub</span>
                        </a>
                    </div>
                </div>
                <div class="column is-4 has-text-centered">
                    <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter Logo" style="width: 350px; max-height: 300px; object-fit: contain;" />
                </div>
            </div>
        </div>
    </div>
</section>

<div class="columns">
    <div class="column is-8">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light">
                <h2 id="introduction" class="title is-4 has-text-light">
                    <span class="icon">
                        <i class="fas fa-info-circle"></i>
                    </span>
                    What is BotOfTheSpecter?
                </h2>
                <p>
                    BotOfTheSpecter is a powerful, feature-rich bot designed specifically for streamers and content creators.
                    It provides comprehensive automation, moderation, entertainment, and engagement tools for Twitch, Discord,
                    and other streaming platforms. Built with modularity in mind, it can be easily customized and extended
                    to fit your specific streaming needs.
                </p>
                <div class="columns mt-4 has-text-light">
                    <div class="column has-text-centered">
                        <span class="icon is-large has-text-primary">
                            <i class="fas fa-shield-alt fa-3x"></i>
                        </span>
                        <h5 class="title is-5">Moderation</h5>
                        <p>Advanced moderation tools to keep your community safe and welcoming.</p>
                    </div>
                    <div class="column has-text-centered">
                        <span class="icon is-large has-text-success">
                            <i class="fas fa-gamepad fa-3x"></i>
                        </span>
                        <h5 class="title is-5">Entertainment</h5>
                        <p>Fun commands and games to engage your audience.</p>
                    </div>
                    <div class="column has-text-centered">
                        <span class="icon is-large has-text-info">
                            <i class="fas fa-chart-line fa-3x"></i>
                        </span>
                        <h5 class="title is-5">Analytics</h5>
                        <p>Detailed insights into your stream performance and audience.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light">
                <h2 id="features" class="title is-4 has-text-light">
                    <span class="icon">
                        <i class="fas fa-star"></i>
                    </span>
                    Key Features
                </h2>
                <div class="columns has-text-light">
                    <div class="column">
                        <h5 class="title is-5 has-text-light">
                            <span class="icon">
                                <i class="fas fa-comments"></i>
                            </span>
                            Chat Integration
                        </h5>
                        <ul>
                            <li>Multi-platform chat support (Twitch, Discord)</li>
                            <li>Real-time message processing</li>
                            <li>Custom command system</li>
                        </ul>
                        <h5 class="title is-5 has-text-light mt-5">
                            <span class="icon">
                                <i class="fas fa-music"></i>
                            </span>
                            Media Features
                        </h5>
                        <ul>
                            <li>YouTube integration for music requests</li>
                            <li>Sound alerts and notifications</li>
                            <li>TTS (Text-to-Speech) support</li>
                        </ul>
                    </div>
                    <div class="column">
                        <h5 class="title is-5 has-text-light">
                            <span class="icon">
                                <i class="fas fa-gamepad"></i>
                            </span>
                            Entertainment
                        </h5>
                        <ul>
                            <li>Built-in games and mini-games</li>
                            <li>Quote and fortune systems</li>
                            <li>Custom emotes and reactions</li>
                        </ul>
                        <h5 class="title is-5 has-text-light mt-5">
                            <span class="icon">
                                <i class="fas fa-chart-bar"></i>
                            </span>
                            Analytics
                        </h5>
                        <ul>
                            <li>Viewer statistics and tracking</li>
                            <li>Command usage analytics</li>
                            <li>Performance monitoring</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-header has-background-dark">
                <p class="card-header-title has-text-light">
                    <span class="icon">
                        <i class="fas fa-code-branch"></i>
                    </span>
                    Quick Links
                </p>
            </div>
            <div class="card-content has-background-dark">
                <div class="content has-text-light">
                    <ul>
                        <li><a href="setup.php" class="has-text-light">
                            <span class="icon">
                                <i class="fas fa-rocket"></i>
                            </span>
                            First Time Setup
                        </a></li>
                        <li><a href="command_reference.php" class="has-text-light">
                            <span class="icon">
                                <i class="fas fa-terminal"></i>
                            </span>
                            Command Reference
                        </a></li>
                        <li><a href="https://api.botofthespecter.com/docs" target="_blank" class="has-text-light">
                            <span class="icon">
                                <i class="fas fa-code"></i>
                            </span>
                            API Documentation
                        </a></li>
                        <li><a href="faq.php" class="has-text-light">
                            <span class="icon">
                                <i class="fas fa-question-circle"></i>
                            </span>
                            Frequently Asked Questions
                        </a></li>
                        <li><a href="troubleshooting.php" class="has-text-light">
                            <span class="icon">
                                <i class="fas fa-wrench"></i>
                            </span>
                            Troubleshooting Guide
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="card has-background-dark has-shadow">
            <div class="card-header has-background-dark">
                <p class="card-header-title has-text-light">
                    <span class="icon">
                        <i class="fas fa-life-ring"></i>
                    </span>
                    Support
                </p>
            </div>
            <div class="card-content has-background-dark">
                <p class="has-text-light">Need help? Get support through:</p>
                <div class="content has-text-light">
                    <ul>
                        <li><a href="https://github.com/YourStreamingTools/BotOfTheSpecter/issues" target="_blank" class="has-text-light">
                            <span class="icon">
                                <i class="fab fa-github"></i>
                            </span>
                            GitHub Issues
                        </a></li>
                        <li><a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" class="has-text-light">
                            <span class="icon">
                                <i class="fab fa-discord"></i>
                            </span>
                            Discord Server
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Home';
include 'layout.php';
?>
