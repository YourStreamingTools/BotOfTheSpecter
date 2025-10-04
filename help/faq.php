<?php
ob_start();
?>
<nav class="breadcrumb has-text-light" aria-label="breadcrumbs" style="margin-bottom: 2rem; background-color: rgba(255, 255, 255, 0.05); padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.1);">
    <ul>
        <li><a href="index.php" class="has-text-light">Home</a> <span style="color: #fff;">→</span></li>
        <li class="is-active"><a aria-current="page" class="has-text-link has-text-weight-bold">FAQ</a></li>
    </ul>
</nav>
<h1 class="title is-2 has-text-light">Frequently Asked Questions</h1>
<p class="subtitle has-text-light">Common questions and answers about BotOfTheSpecter</p>

<div class="content has-text-light">
    <div class="notification is-info has-background-dark">
        <h2 class="title is-4 has-text-light">
            <span class="icon">
                <i class="fas fa-info-circle"></i>
            </span>
            No FAQs Yet
        </h2>
        <p class="has-text-light">
            We don't have any frequently asked questions compiled yet. As we receive more questions from our community,
            we'll update this page with the most common ones and their answers.
        </p>
    </div>
    <h2 class="title is-4 has-text-light">Have a Question?</h2>
    <p class="has-text-light">If you have questions about BotOfTheSpecter, we'd love to hear from you! Here are the best ways to reach us:</p>
    <div class="columns is-multiline is-flex">
        <div class="column is-5">
            <div class="card has-background-dark has-shadow is-flex" style="height: 100%;">
                <div class="card-content has-background-dark has-text-light has-text-centered">
                    <span class="icon is-large has-text-primary">
                        <i class="fas fa-stream fa-3x"></i>
                    </span>
                    <h3 class="title is-5 has-text-light">Live on Stream</h3>
                    <p>Ask questions during our live streams for immediate answers and community discussion.</p>
                    <p class="mt-2"><strong>Developer Stream:</strong> <a href="https://twitch.tv/gfaundead" class="has-text-link" target="_blank">twitch.tv/gfaundead</a></p>
                </div>
            </div>
        </div>
        <div class="column is-5">
            <div class="card has-background-dark has-shadow is-flex" style="height: 100%;">
                <div class="card-content has-background-dark has-text-light has-text-centered">
                    <span class="icon is-large has-text-info">
                        <i class="fab fa-discord fa-3x"></i>
                    </span>
                    <h3 class="title is-5 has-text-light">Discord Server</h3>
                    <p>Join our <a href="https://discord.com/invite/ANwEkpauHJ" class="has-text-link" target="_blank">Discord server</a> for community support and discussions.</p>
                </div>
            </div>
        </div>
        <div class="column is-5">
            <div class="card has-background-dark has-shadow is-flex" style="height: 100%;">
                <div class="card-content has-background-dark has-text-light has-text-centered">
                    <span class="icon is-large has-text-success">
                        <i class="fas fa-envelope fa-3x"></i>
                    </span>
                    <h3 class="title is-5 has-text-light">Email Support</h3>
                    <p>Send us an email at <a href="mailto:questions@botofthespecter.com" class="has-text-link">questions@botofthespecter.com</a> for detailed inquiries.</p>
                </div>
            </div>
        </div>
        <div class="column is-5">
            <div class="card has-background-dark has-shadow is-flex" style="height: 100%;">
                <div class="card-content has-background-dark has-text-light has-text-centered">
                    <span class="icon is-large has-text-warning">
                        <i class="fab fa-twitch fa-3x"></i>
                    </span>
                    <h3 class="title is-5 has-text-light">Developer Support</h3>
                    <p>Connect directly with our developer for technical questions and development updates.</p>
                    <p class="mt-2"><a href="https://twitch.tv/gfaundead" class="has-text-link" target="_blank">twitch.tv/gfaundead</a></p>
                </div>
            </div>
        </div>
    </div>
    <div class="notification is-warning has-background-dark has-text-light mt-5">
        <strong>Coming Soon:</strong> As we collect more questions from our community, we'll add them to this FAQ page with detailed answers to help everyone get the most out of BotOfTheSpecter.
    </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'FAQ';
include 'layout.php';
?>