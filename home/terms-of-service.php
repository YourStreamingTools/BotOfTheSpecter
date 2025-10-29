<?php
ob_start();
?>
<script type="application/ld+json">{"@context": "https://schema.org","@type": "WebPage","name": "BotOfTheSpecter - Terms of Service","description": "Terms of Service for BotOfTheSpecter, outlining user obligations, payment terms, privacy, and more.","url": "https://botofthespecter.com/terms-of-service.php"}</script>
<?php
$extraScripts = ob_get_clean();
ob_start();
$pageTitle = "BotOfTheSpecter - Terms of Service";
$pageDescription = "BotOfTheSpecter is an advanced Twitch bot designed to enhance your streaming experience, offering a suite of tools for community interaction, channel management, and analytics.";
$effectiveDate = 'October 29, 2025';
$lastUpdated = date('F j, Y');
?>
<main class="box is-fullwidth content" role="main" aria-labelledby="tos-heading">
    <h1 id="tos-heading" class="title">Terms of Service</h1>
    <p class="subtitle">Effective date: <?php echo $effectiveDate; ?>. Last updated: <?php echo $lastUpdated; ?>.</p>
    <br />
    <section id="acceptance">
        <h2>1. Acceptance of Terms</h2>
        <p>By accessing, browsing, or using this website ("Site") and/or purchasing any products or services ("Services") offered by <strong>BotOfTheSpecter</strong> ("Company," "we," "us," or "our"), operating under the business name <strong>YourStreamingTools</strong> and registered as a subsidiary of <strong>LochStudios, Australia Business Number 20 447 022 747</strong>, you ("User," "you," or "your") acknowledge that you have read, understood, and agree to be bound by these Terms. If you do not agree to these Terms, please do not use this Site or purchase our Services.</p>
    </section>
    <br />
    <section id="description">
        <h2>2. Description of Services</h2>
        <p>The Site provides information about our Twitch and Discord bot functionalities and related Services. We also facilitate the purchase of these Services through our integrated payment processing system.</p>
    </section>
    <br />
    <section id="user-obligations">
        <h2>3. User obligations</h2>
    <h3 class="subpoint">3.1 Accurate information</h3>
        <p>You agree to provide accurate, current, and complete information during any registration or purchase process on the Site.</p>
    <h3 class="subpoint">3.2 Compliance with laws</h3>
        <p>You agree to comply with all applicable laws and regulations regarding your use of the Site and Services, including data protection legislation such as the GDPR where applicable.</p>
    <h3 class="subpoint">3.3 Prohibited conduct</h3>
        <p>You agree not to: disrupt or interfere with the Site or Services; attempt unauthorized access; use automated systems to access the Site; transmit malware; or engage in fraudulent activity.</p>
    </section>
    <br />
    <section id="payment-terms">
        <h2>4. Payment terms</h2>
        <p><strong>Fees:</strong> You agree to pay all fees and charges associated with purchases as specified on the Site. We use third-party payment processors; you agree to their terms. Failure to pay may result in suspension or termination of Services. Refunds are subject to our refund policy available on the Site.</p>
    </section>
    <br />
    <section id="privacy">
        <h2>5. Privacy</h2>
        <p>Your privacy matters. Please review our <a href="privacy-policy.php">Privacy Policy</a> for details about data collection, use, and protection.</p>
    </section>
    <br />
    <section id="ip">
        <h2>6. Intellectual property</h2>
        <p>All Site content (text, graphics, logos, images, software) is owned by <strong>BotOfTheSpecter</strong> or licensors and is protected by intellectual property laws. Do not reproduce or create derivative works without written permission.</p>
    </section>
    <br />
    <section id="termination">
        <h2>7. Termination</h2>
        <p>We may suspend or terminate your access to the Site and Services at our discretion for violations of these Terms or non-payment.</p>
    </section>
    <br />
    <section id="disclaimer">
        <h2>8. Disclaimer of warranties</h2>
        <p>The Site and Services are provided "as is" and "as available" without warranties of any kind.</p>
    </section>
    <br />
    <section id="liability">
        <h2>9. Limitation of liability</h2>
        <p>In no event shall <strong>BotOfTheSpecter</strong> be liable for indirect, incidental, special, or consequential damages arising from your use of the Site or Services, even if advised of the possibility.</p>
    </section>
    <br />
    <section id="indemnification">
        <h2>10. Indemnification</h2>
        <p>You agree to indemnify and hold harmless <strong>BotOfTheSpecter</strong> and its affiliates from any claims, damages, losses, or expenses arising from your use of the Site or your violation of these Terms.</p>
    </section>
    <br />
    <section id="changes">
        <h2>11. Changes to terms</h2>
        <p>We may modify these Terms by posting updates on the Site. Continued use after changes constitutes acceptance.</p>
    </section>
    <br />
    <section id="governing-law">
        <h2>12. Governing law</h2>
        <p>These Terms are governed by the laws of <strong>Australia</strong>, without regard to conflict of law principles.</p>
    </section>
    <br />
    <section id="contact">
        <h2>13. Contact information</h2>
        <p>If you have questions about these Terms, contact us at <a href="mailto:questions@botofthespecter.com">questions@botofthespecter.com</a>.</p>
    </section>
</main>
<?php
$pageContent = ob_get_clean();
include 'layout.php';
?>