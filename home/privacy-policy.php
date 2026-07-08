<?php
ob_start();
?>
<script type="application/ld+json">{"@context": "https://schema.org","@type": "WebPage","name": "BotOfTheSpecter - Privacy Policy","description": "Privacy Policy for BotOfTheSpecter, outlining data collection, usage, and user rights.","url": "https://botofthespecter.com/privacy-policy.php"}</script>
<?php
$extraScripts = ob_get_clean();

ob_start();
$pageTitle = "BotOfTheSpecter - Privacy Policy";
$pageDescription = "Privacy Policy for BotOfTheSpecter &mdash; how we collect, use, share, and protect personal information across our Twitch bot, dashboard, overlays, and related services.";
$effectiveDate = 'July 9, 2026';
?>
<div class="hs-prose" role="main" aria-labelledby="privacy-heading">
    <h1 id="privacy-heading">Privacy Policy</h1>
    <p class="hs-prose-subtitle">Effective date: <?php echo $effectiveDate; ?>.</p>
    <br />
    <section id="introduction">
        <h2>1. Introduction</h2>
        <p><strong>BotOfTheSpecter</strong>, operating under the business name <strong>YourStreamingTools</strong> and registered as a subsidiary of <strong>LochStudios, Australia Business Number 20 447 022 747</strong> (&ldquo;Company,&rdquo; &ldquo;we,&rdquo; &ldquo;us,&rdquo; or &ldquo;our&rdquo;) is committed to protecting your privacy and complying with applicable data protection laws, including the Australian Privacy Principles and the GDPR where it applies. This Privacy Policy explains how we collect, use, share, and protect personal information when you visit our websites or use our services.</p>
        <p>Our services include the public website, account pages, dashboard, Twitch bot, Discord extension, Kick companion bot, browser-source overlays, API and WebSocket services, optional stream recording and multi-destination forwarding tools, and related ecosystem pages (together, the &ldquo;Services&rdquo; or &ldquo;Site&rdquo;).</p>
    </section>
    <br />
    <section id="data-controller">
        <h2>2. Data controller &amp; contact information</h2>
        <p>For GDPR and general privacy purposes, <strong>BotOfTheSpecter / YourStreamingTools</strong> is the data controller for personal information we process about you. If you have questions about this Privacy Policy or our data practices, contact us at <a href="mailto:questions@botofthespecter.com">questions@botofthespecter.com</a>. For data-subject requests (access, erasure, and similar rights), use <a href="mailto:gdpr@yourstreamingtools.com">gdpr@yourstreamingtools.com</a>.</p>
    </section>
    <br />
    <section id="information-we-collect">
        <h2>3. Information we collect</h2>
        <h3>3.1 Account and identity information</h3>
        <p>We use <strong>Twitch</strong> as our primary login provider (&ldquo;Sign in with Twitch&rdquo;). When you authenticate with Twitch, <strong>Twitch Interactive, Inc.</strong> may provide us with account information associated with your Twitch account, such as your Twitch user ID, username/display name, profile image, and the email address linked to your Twitch account where available/authorised. We may also store information you provide when you contact support or submit tickets through our support portal.</p>
        <p>Your use of Twitch is governed by Twitch&rsquo;s policies, including their <a href="https://legal.twitch.com/legal/privacy-policy/" target="_blank" rel="noopener">Privacy Policy</a> and <a href="https://legal.twitch.com/legal/terms-of-service/" target="_blank" rel="noopener">Terms of Service</a>.</p>
        <h3>3.2 Service and feature data</h3>
        <p>To operate the bot and dashboard features you enable, we store configuration and operational data associated with your channel/account. Depending on which features you use, this may include:</p>
        <ul>
            <li><strong>Channel and bot settings:</strong> preferences, feature toggles, custom commands, aliases, timers, alert settings, overlay preferences, timezone and display options, and similar configuration.</li>
            <li><strong>Community and engagement data:</strong> points/currency balances, watch time, lurker status, channel-point redemption history, death counters, counts, typos, hugs/kisses/high-fives, todos, and other feature-specific records generated through chat or dashboard use.</li>
            <li><strong>Chat and event history:</strong> messages and event metadata processed to provide commands, moderation tools, AI features (where enabled), and troubleshooting.</li>
            <li><strong>Media you upload:</strong> walk-on sounds, music, alert media, and similar assets stored for use with overlays or bot features.</li>
            <li><strong>Credentials we issue or store for you:</strong> API keys and OAuth tokens needed so your dashboard, bot instance, overlays, and integrations can authenticate to our Services. We do not collect or store full payment card numbers.</li>
        </ul>
        <h3>3.3 Connected integrations</h3>
        <p>If you connect or configure third-party services, we receive and store the information needed to provide those features. Integrations available in the product include:</p>
        <ul>
            <li><strong>Discord:</strong> OAuth tokens, Discord user/guild identifiers, channel settings, and related alert configuration when you link Discord or use the Discord extension (including Discord&ndash;Twitch account linking).</li>
            <li><strong>Spotify:</strong> OAuth tokens and account identifiers for now-playing, song requests, and related music features.</li>
            <li><strong>StreamElements and Streamlabs:</strong> OAuth/socket tokens and identifiers for tip and donation events you enable.</li>
            <li><strong>Patreon, Ko-fi, and Fourthwall:</strong> webhook event payloads (for example memberships, donations, or shop orders) routed to your channel using your API key.</li>
            <li><strong>Kick:</strong> channel event data processed by the Kick companion bot and webhook endpoints; optional Kick stream-forward settings if you configure recording/forwarding.</li>
            <li><strong>HypeRate:</strong> a heart-rate code you configure and live heart-rate values used by related commands/features.</li>
            <li><strong>YouTube (limited):</strong> URLs/titles used for song requests or media playback, and optional YouTube RTMP stream-forward keys if you enable multi-destination streaming&mdash;not a full YouTube account OAuth product.</li>
        </ul>
        <p>Each third-party service has its own privacy policy. We only request access needed for the features you enable, and you can disconnect some integrations where the dashboard provides that option.</p>
        <h3>3.4 Platform services we call on your behalf</h3>
        <p>Some features use platform-operated third-party APIs (you do not supply your own keys for these). Depending on features used, this may include:</p>
        <ul>
            <li><strong>OpenAI:</strong> chat/AI responses and text-to-speech generation may send prompts, chat context, or TTS text to OpenAI to produce the response or audio you requested.</li>
            <li><strong>Weather providers:</strong> location strings and weather queries (for example OpenWeatherMap) to answer weather commands and drive weather overlays.</li>
            <li><strong>Shazam (via API provider):</strong> short stream-audio samples may be analysed to identify a song when that failover path is used.</li>
            <li><strong>Steam:</strong> game-name lookups to map a current game to a Steam store listing.</li>
            <li><strong>ExchangeRate-API:</strong> currency amounts and codes from chat conversion commands.</li>
            <li><strong>IP geolocation (for example IPLocate):</strong> approximate location/VPN-related flags derived from IP address for session/security views.</li>
        </ul>
        <h3>3.5 Usage, technical, and security data</h3>
        <p>We automatically collect technical information needed to operate and secure the Services, including:</p>
        <ul>
            <li><strong>Log and device data:</strong> IP address, browser/user agent, device-related headers, and approximate location derived from IP address.</li>
            <li><strong>Session and authentication data:</strong> login/logout and session identifiers used to keep you signed in and help prevent unauthorised access.</li>
            <li><strong>Technical and performance data:</strong> error reports, diagnostics, response times, WebSocket connection events, and system metrics used for reliability monitoring and debugging.</li>
        </ul>
        <h3>3.6 Premium and payment-related information</h3>
        <p><strong>BotOfTheSpecter Premium</strong> is provided through a <strong>Twitch channel subscription</strong> to the bot developer <strong>Lachlan</strong> (<strong>gfaUnDead</strong>, <a href="https://twitch.tv/gfaundead" target="_blank" rel="noopener">https://twitch.tv/gfaundead</a>). Premium eligibility is determined using Twitch subscription status (and, where applicable, an internal beta-access flag). Payment for that subscription is processed by <strong>Twitch</strong>, not by us. We receive subscription tier/status information needed to unlock premium features; we do not receive or store your full card details for Twitch subscriptions.</p>
        <p>We do not currently operate an in-product card checkout for BotOfTheSpecter Premium. If we offer other paid products in the future, we will update this policy and identify the payment provider used at that time.</p>
        <h3>3.7 Stream recording and media (where enabled)</h3>
        <p>If you use our optional stream ingest, recording, or multi-destination forwarding tools, we may process stream media, stream keys/forward settings you provide, technical stream metadata (for example bitrate, resolution, and connection details), and operational logs needed to receive, forward, record, convert, and store streams. Recording and storage may use multi-region infrastructure (for example Australia, United States, and Europe), depending on the ingest location and storage path configured.</p>
        <h3>3.8 Cookies and similar technologies</h3>
        <p>We use cookies and similar technologies (including local storage and session storage) for core functionality and preferences, such as:</p>
        <ul>
            <li><strong>Strictly necessary cookies / session storage:</strong> session management, login/authentication, security protections, and enabling key site features.</li>
            <li><strong>Preference storage:</strong> remembering settings you choose (for example display theme or dashboard UI preferences) so you do not have to re-enter them each time.</li>
        </ul>
        <p>On the dashboard, you may be offered cookie preference controls for certain non-essential preference cookies. Our public home Site does not currently run a third-party advertising or product-analytics cookie suite. You can also control cookies through your browser settings; disabling certain cookies may affect site functionality.</p>
    </section>
    <br />
    <section id="lawful-bases">
        <h2>4. Lawful bases for processing</h2>
        <p>Where the GDPR or similar laws apply, we rely on one or more of the following bases:</p>
        <ul>
            <li><strong>Consent:</strong> where you have given consent (for example optional preference cookies where consent is requested).</li>
            <li><strong>Contractual necessity:</strong> to provide Services you have requested or entered into with us.</li>
            <li><strong>Legal obligation:</strong> to comply with laws and regulations.</li>
            <li><strong>Legitimate interests:</strong> for our legitimate business interests (such as securing our systems, preventing abuse, improving reliability, and operating features you enable), provided your rights are respected.</li>
        </ul>
    </section>
    <br />
    <section id="how-we-use">
        <h2>5. How we use your information</h2>
        <ul>
            <li>To provide, operate, maintain, and improve the Services, including bot features, dashboard configuration, overlays, APIs, and integrations you enable.</li>
            <li>To authenticate you, manage accounts, and determine premium or beta entitlements.</li>
            <li>To communicate with you about account changes, security notices, support requests, data-export delivery, and service updates.</li>
            <li>To comply with legal and security obligations.</li>
            <li>To detect, prevent, investigate, and take action against spam, abuse, fraud, and other prohibited activity, including enforcing our Terms of Service.</li>
            <li>To generate diagnostics and reliability metrics that help us keep the platform available and secure.</li>
        </ul>
    </section>
    <br />
    <section id="sharing">
        <h2>6. Sharing your information</h2>
        <p>We do not sell your personal information. We share or disclose personal information only when necessary to operate our Services, provide requested features, comply with legal obligations, or protect the security and integrity of our systems.</p>
        <p>Typical disclosures include:</p>
        <ul>
            <li><strong>Platform partners you use or connect:</strong> Twitch (login and premium status), Discord, Spotify, StreamElements, Streamlabs, Kick, Patreon, Ko-fi, Fourthwall, and similar platforms exchange data as needed for authentication and the features you enable. Their processing is governed by their own policies.</li>
            <li><strong>Service providers we use to run the product:</strong> for example email/SMTP delivery for support and data exports, object storage (including Cloudflare R2 for large export downloads), infrastructure/hosting providers, AI providers when you use AI or TTS features, weather/music-recognition/currency/IP geolocation APIs, and similar operators needed to deliver a feature.</li>
            <li><strong>Privacy data export delivery:</strong> when you request an export via <strong>Export my Data</strong> on the dashboard profile page (or via a manual privacy request), we prepare a ZIP of account-related data and deliver it to the email address linked to your Twitch account (or otherwise provided/verified). Smaller exports may be emailed as an attachment; larger exports are delivered via a time-limited download link (currently valid for up to <strong>7 days</strong>).</li>
            <li><strong>Legal requests:</strong> where we are required to disclose information under applicable law, including lawful requests from government authorities (including from another country where applicable legal processes apply).</li>
            <li><strong>Business transfers:</strong> if we are involved in a merger, acquisition, reorganisation, or sale of assets, personal information may be transferred as part of that transaction, subject to appropriate protections.</li>
        </ul>
    </section>
    <br />
    <section id="international">
        <h2>7. International storage and transfers</h2>
        <p>We are based in <strong>Australia</strong>. Core application systems are operated as part of our Australian-based platform. Personal information may still be processed in other countries in limited cases, including:</p>
        <ul>
            <li>when you use multi-region stream infrastructure (ingest/recording nodes and related storage in regions such as Australia, the United States, or Europe);</li>
            <li>when third-party providers (Twitch/Discord and other platforms, AI providers, email delivery, object storage, weather and similar APIs) process data in their own regions; or</li>
            <li>when support, security, or legal processes require access from another jurisdiction.</li>
        </ul>
        <p>Where personal information is transferred internationally, we take steps designed to ensure it continues to be protected in accordance with this Privacy Policy and applicable law.</p>
    </section>
    <br />
    <section id="retention">
        <h2>8. Data retention</h2>
        <p>We keep personal data only for as long as it is reasonably necessary to provide our Services, fulfil the purposes described in this Privacy Policy, comply with legal obligations, resolve disputes, enforce our agreements, and protect the security and integrity of our systems.</p>
        <p>Retention depends on the type of data and why we collected it. For example:</p>
        <ul>
            <li><strong>Account, profile, and channel configuration:</strong> retained while you maintain an account with us so we can provide the Services you request. If your access is terminated or your account is closed, we may retain limited data for compliance, fraud prevention, security, and dispute resolution.</li>
            <li><strong>Feature and community data:</strong> retained while the related features remain active for your channel, or until deleted through available tools or a valid erasure request, subject to backup and legal retention needs.</li>
            <li><strong>Usage, security, and diagnostic logs:</strong> retained for a limited period as needed for security monitoring, abuse prevention, troubleshooting, and service reliability.</li>
            <li><strong>Premium entitlement checks:</strong> subscription tier/status is checked against Twitch (and internal beta-access flags where applicable) as needed to provide premium features.</li>
            <li><strong>Stream recordings (if used):</strong> retained according to the recording/storage path you use and any retention controls we provide; temporary intermediate files may be discarded after conversion or processing.</li>
            <li><strong>Privacy data exports:</strong> download links for large exports are time-limited (currently up to <strong>7 days</strong>). You can request a new export if needed.</li>
        </ul>
        <p>Where retention is required by law, or where we need to establish, exercise, or defend legal claims, we may retain relevant information for longer.</p>
    </section>
    <br />
    <section id="rights">
        <h2>9. Your rights</h2>
        <p>Depending on where you live, you may have rights in relation to your personal data (subject to certain conditions and exemptions). If the GDPR applies to you, these may include:</p>
        <ul>
            <li><strong>Right of access:</strong> request access to the personal data we hold about you.</li>
            <li><strong>Right to rectification:</strong> request that we correct inaccurate or incomplete personal data.</li>
            <li><strong>Right to erasure (&ldquo;right to be forgotten&rdquo;):</strong> request deletion of your personal data in certain circumstances.</li>
            <li><strong>Right to restriction of processing:</strong> request that we limit how we use your personal data in certain circumstances.</li>
            <li><strong>Right to data portability:</strong> request a copy of certain personal data in a portable format.</li>
            <li><strong>Right to object:</strong> object to processing in certain circumstances (including certain processing based on legitimate interests).</li>
            <li><strong>Right to withdraw consent:</strong> where we rely on consent, you can withdraw it at any time (this does not affect processing already carried out).</li>
        </ul>
        <p>Australian users may also have rights under the Privacy Act 1988 (Cth), including the right to request access to and correction of personal information we hold, and to complain about our handling of personal information.</p>
        <p>To exercise these rights, contact us at <a href="mailto:gdpr@yourstreamingtools.com">gdpr@yourstreamingtools.com</a>. To protect your privacy and security, we may need to verify your identity before completing your request. We aim to respond within the timeframes required by applicable law (generally within one month under the GDPR, and may be extended where permitted).</p>
        <p>You may also lodge a complaint with your local data protection authority (or, in Australia, the Office of the Australian Information Commissioner) if you believe our processing of your personal data infringes applicable law.</p>
        <h3>9.1 Export my Data (automated download)</h3>
        <p>If you would like to access or export personal data we currently hold about your account/profile, you can use the <strong>Export my Data</strong> button on the <strong>dashboard profile page</strong>. When you request an export, our system collects information currently stored about your account/profile, packages it into a ZIP file, and emails delivery instructions to the email address linked to your Twitch account (shown when you start the export). Depending on size, the ZIP may be attached to the email or provided via a time-limited download link (currently up to <strong>7 days</strong>).</p>
        <p>If the email address shown is not correct, update your email address on Twitch, log out and log back into the dashboard, and try again. Alternatively, email us at <a href="mailto:gdpr@yourstreamingtools.com">gdpr@yourstreamingtools.com</a> to request an export manually.</p>
    </section>
    <br />
    <section id="security">
        <h2>10. Security</h2>
        <p>We implement reasonable technical and organisational measures designed to protect personal data from accidental or unlawful destruction, loss, alteration, unauthorised disclosure, or access.</p>
        <p>Our security measures may include (as appropriate):</p>
        <ul>
            <li><strong>Access controls:</strong> limiting access to personal data to authorised personnel and systems on a need-to-know basis.</li>
            <li><strong>Encryption in transit:</strong> using HTTPS/TLS for data transmitted between your device and our Services where supported.</li>
            <li><strong>Monitoring and logging:</strong> maintaining security and diagnostic logs to detect suspicious activity, prevent abuse, and investigate incidents.</li>
            <li><strong>Operational safeguards:</strong> applying security updates, using least-privilege principles, and maintaining backup and recovery practices appropriate to the service.</li>
        </ul>
        <p>You are responsible for keeping your account access secure (for example, protecting your Twitch account, connected integration credentials, API keys we issue to you, and any devices you use to access our Services). While we work to protect your personal data, no method of transmission over the Internet or method of electronic storage is completely secure, and we cannot guarantee absolute security.</p>
    </section>
    <br />
    <section id="children">
        <h2>11. Children</h2>
        <p>Our Services are intended for streamers and users who can lawfully use Twitch and related platforms. We do not knowingly collect personal information from children in a manner that violates applicable law or platform age requirements. If you believe a child has provided personal information to us inappropriately, contact us and we will take appropriate steps to delete the information where required.</p>
    </section>
    <br />
    <section id="changes">
        <h2>12. Changes to this policy</h2>
        <p>We may update this Privacy Policy from time to time to reflect changes to our practices, technologies, legal requirements, or for other operational reasons.</p>
        <p>When we make changes, we will post the updated policy on this page and update the &ldquo;Effective date&rdquo; above. Unless stated otherwise, changes take effect when they are posted.</p>
        <p>If we make material changes, we may provide additional notice where appropriate (for example, by displaying a notice on the Site or contacting you via an email address associated with your account). We encourage you to review this policy periodically.</p>
    </section>
    <br />
    <section id="contact">
        <h2>13. Contact us</h2>
        <p>If you have questions or would like to contact us about privacy or our Services, you can reach us at:</p>
        <ul>
            <li><strong>General questions:</strong> <a href="mailto:questions@botofthespecter.com">questions@botofthespecter.com</a></li>
            <li><strong>Privacy / GDPR requests:</strong> <a href="mailto:gdpr@yourstreamingtools.com">gdpr@yourstreamingtools.com</a></li>
            <li><strong>Administrative concerns:</strong> <a href="mailto:admin@botofthespecter.com">admin@botofthespecter.com</a></li>
            <li><strong>Integrations help:</strong> <a href="mailto:integrations@botofthespecter.com">integrations@botofthespecter.com</a></li>
            <li><strong>Code-related issues:</strong> <a href="mailto:code@botofthespecter.com">code@botofthespecter.com</a></li>
            <li><strong>Support portal:</strong> <a href="https://support.botofthespecter.com" target="_blank" rel="noopener">https://support.botofthespecter.com</a></li>
        </ul>
    </section>
</div>
<?php
$pageContent = ob_get_clean();
include 'layout.php';
?>
