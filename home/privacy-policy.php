<?php
ob_start();
?>
<script type="application/ld+json">{"@context": "https://schema.org","@type": "WebPage","name": "BotOfTheSpecter - Privacy Policy","description": "Privacy Policy for BotOfTheSpecter, outlining data collection, usage, and user rights.","url": "https://botofthespecter.com/privacy-policy.php"}</script>
<?php
$extraScripts = ob_get_clean();

ob_start();
$pageTitle = "BotOfTheSpecter - Privacy Policy";
$pageDescription = "BotOfTheSpecter is an advanced Twitch bot designed to enhance your streaming experience, offering a suite of tools for community interaction, channel management, and analytics.";
$effectiveDate = 'October 29, 2025';
$lastUpdated = date('F j, Y');
?>
<main class="box is-fullwidth content" role="main" aria-labelledby="privacy-heading">
    <h1 id="privacy-heading" class="title">Privacy Policy</h1>
    <p class="subtitle">Effective date: <?php echo $effectiveDate; ?>. Last updated: <?php echo $lastUpdated; ?>.</p>
    <br />
    <section id="introduction">
        <h2>1. Introduction</h2>
        <p><strong>BotOfTheSpecter</strong>, operating under the business name <strong>YourStreamingTools</strong> and registered as a subsidiary of <strong>LochStudios, Australia Business Number 20 447 022 747</strong> ("Company," "we," "us," or "our") is committed to protecting your privacy and complying with applicable data protection laws including the GDPR. This Privacy Policy explains how we collect, use, and protect personal information when you visit our Site or use our services.</p>
    </section>
    <br />
    <section id="data-controller">
        <h2>2. Data controller & contact information</h2>
        <p>For GDPR purposes, <strong>BotOfTheSpecter</strong> is the data controller. If you have any questions about this Privacy Policy or our data practices, please contact us at <a href="mailto:questions@botofthespecter.com">questions@botofthespecter.com</a>.</p>
    </section>
    <br />
    <section id="information-we-collect">
        <h2>3. Information we collect</h2>
    <h3 class="subpoint">3.1 Personal information</h3>
        <p>We collect information you provide directly to us (for example: name, email, billing address) when you register, make purchases, subscribe to newsletters, or contact us.</p>
    <h3 class="subpoint">3.2 Usage data</h3>
        <p>We automatically collect information about how you interact with our Site, including IP address, browser type, device, referring URLs, pages visited, and timestamps.</p>
    <h3 class="subpoint">3.3 Payment information</h3>
        <p>We use third-party payment processors for transactions. We record transaction references and receipts but do not store full card details on our servers.</p>
    <h3 class="subpoint">3.4 Cookies and tracking</h3>
        <p>We use cookies and similar technologies to personalize your experience and analyze site usage. See our cookie settings in the site footer (if available) for options to manage preferences.</p>
    </section>
    <br />
    <section id="lawful-bases">
        <h2>4. Lawful bases for processing</h2>
        <ul>
            <li><strong>Consent:</strong> Where you have given consent (e.g., marketing emails).</li>
            <li><strong>Contractual necessity:</strong> To provide services you have requested.</li>
            <li><strong>Legal obligation:</strong> To comply with laws and regulations.</li>
            <li><strong>Legitimate interests:</strong> For our legitimate business interests, provided your rights are respected.</li>
        </ul>
    </section>
    <br />
    <section id="how-we-use">
        <h2>5. How we use your information</h2>
        <ul>
            <li>To provide and improve our services, process payments and manage accounts.</li>
            <li>To communicate with you about orders, account changes, and updates.</li>
            <li>For marketing with your consent.</li>
            <li>To comply with legal and security obligations.</li>
        </ul>
    </section>
    <br />
    <section id="sharing">
        <h2>6. Sharing your information</h2>
        <p>We share information only as necessary with payment processors, service providers (e.g., hosting, analytics), and where required by law. If we transfer data outside the EEA, we use appropriate safeguards such as standard contractual clauses.</p>
    </section>
    <br />
    <section id="retention">
        <h2>7. Data retention</h2>
        <p>We keep personal data only as long as necessary to fulfill the purposes described, comply with legal obligations, and resolve disputes.</p>
    </section>
    <br />
    <section id="rights">
        <h2>8. Your rights under GDPR</h2>
        <p>You have rights including access, correction, deletion, restriction, portability, objection, and the right to withdraw consent where applicable. To exercise these rights, contact us at <a href="mailto:questions@botofthespecter.com">questions@botofthespecter.com</a>. You may also lodge a complaint with a supervisory authority.</p>
    </section>
    <br />
    <section id="security">
        <h2>9. Security</h2>
        <p>We implement reasonable technical and organisational measures to protect personal data. However, no internet transmission is completely secure.</p>
    </section>
    <br />
    <section id="changes">
        <h2>10. Changes to this policy</h2>
        <p>We may update this policy. We will post the latest policy on this page with the updated date above; please review it periodically.</p>
    </section>
    <br />
    <section id="contact">
        <h2>11. Contact us</h2>
        <p>If you have questions, please contact us at <a href="mailto:questions@botofthespecter.com">questions@botofthespecter.com</a>.</p>
    </section>
</main>
<?php
$pageContent = ob_get_clean();
include 'layout.php';
?>