<?php
$pageTitle = "Custom API Documentation";
$pageDescription = "BotOfTheSpecter custom API reference, endpoints and authentication details.";

ob_start();
?>
<nav class="breadcrumb has-text-light" aria-label="breadcrumbs" style="margin-bottom: 2rem; background-color: rgba(255, 255, 255, 0.05); padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.1);">
	<ul>
		<li><a href="index.php" class="has-text-light">Home</a> <span style="color: #fff;">→</span></li>
		<li class="is-active"><a aria-current="page" class="has-text-link has-text-weight-bold">Custom API</a></li>
	</ul>
</nav>
<section class="section">
	<div class="container">
		<h1 class="title is-2 has-text-light">Custom API Documentation</h1>
		<p class="subtitle has-text-light">Information about BotOfTheSpecter's custom API, endpoints, authentication and webhook integrations.</p>
		<div class="content has-text-light">
			<h2 class="title is-4 has-text-light">API Overview</h2>
			<p>The BotOfTheSpecter API enables programmatic access to various bot features, allowing developers to build custom integrations, extensions, and applications that interact with the bot's functionality.</p>
			<h2 class="title is-4 has-text-light">Authentication</h2>
			<p>All API requests require authentication using your unique API key.<br>This key is essential for all BotOfTheSpecter integrations, including API access, WebSocket server connections, and third-party platform integrations.</p>
			<h3 class="title is-5 has-text-light">Obtaining Your API Key</h3>
			<ol>
				<li>Log in to the <a href="https://dashboard.botofthespecter.com/" target="_blank" class="has-text-link">BotOfTheSpecter Dashboard</a></li>
				<li>Navigate to <strong>Dashboard</strong> &rarr; <strong>Profile</strong></li>
				<li>Locate your API key in the "API Access" section of the Profile page</li>
			</ol>
			<div class="notification is-warning">
				<strong>Warning:</strong> Keep your API key secure. Do not share it publicly or include it in client-side code. Your API key provides full access to your BotOfTheSpecter account.
			</div>
			<h3 class="title is-5 has-text-light">API Key Regeneration</h3>
			<p>If you believe your API key has been compromised:</p>
			<ol>
				<li>Go to <strong>Dashboard</strong> &rarr; <strong>Profile</strong></li>
				<li>Click the regenerate button in the API Key section</li>
				<li><strong>Important:</strong> Regenerating your API key will require a full restart of all BotOfTheSpecter components (Twitch Chat Bot & Overlays). After regenerating you must restart those components via the dashboard.</li>
			</ol>
			<h2 class="title is-4 has-text-light">Webhook Integrations</h2>
			<p>The API supports webhook integrations with various third-party services.<br>These integrations allow BotOfTheSpecter to receive real-time notifications from external platforms and trigger appropriate actions in your Twitch chat or stream overlays.</p>
			<h2 class="title is-4 has-text-light">API Endpoints</h2>
			<p>BotOfTheSpecter's API provides several endpoint groups for different use cases.<br>Some endpoints are public, while others require a user API key (or admin key) using a query parameter such as <code>?api_key=YOUR_API_KEY</code>.</p>
			<div class="notification is-dark" style="display:flex; flex-direction:column; gap:0.75rem;">
				<p><strong>Authenticated endpoint highlights</strong></p>
				<div class="tags" style="flex-wrap:wrap; gap:0.35rem;">
					<span class="tag is-link is-light">GET /custom-commands</span>
					<span class="tag is-link is-light">GET /user-points</span>
					<span class="tag is-link is-light">POST /user-points/credit</span>
					<span class="tag is-link is-light">POST /user-points/debit</span>
					<span class="tag is-link is-light">GET /account</span>
					<span class="tag is-link is-light">GET /bot/status</span>
					<span class="tag is-link is-light">POST /SEND_OBS_EVENT</span>
					<span class="tag is-link is-light">GET /websocket/stream_offline</span>
					<span class="tag is-link is-light">GET /websocket/stream_online</span>
					<span class="tag is-link is-light">GET /websocket/sound_alert</span>
					<span class="tag is-link is-light">GET /websocket/custom_command</span>
					<span class="tag is-link is-light">GET /websocket/raffle_winner</span>
					<span class="tag is-link is-light">GET /websocket/deaths</span>
					<span class="tag is-link is-light">GET /websocket/walkon</span>
					<span class="tag is-link is-light">GET /websocket/tts</span>
					<span class="tag is-link is-light">POST /patreon</span>
					<span class="tag is-link is-light">POST /kofi</span>
					<span class="tag is-link is-light">POST /fourthwall</span>
					<span class="tag is-link is-light">GET /weather</span>
					<span class="tag is-link is-light">GET /sound-alerts</span>
					<span class="tag is-link is-light">GET /joke</span>
					<span class="tag is-link is-light">GET /kill</span>
					<span class="tag is-link is-light">GET /fortune</span>
					<span class="tag is-link is-light">GET /quotes</span>
					<span class="tag is-warning is-light">GET /authorizedusers (admin key)</span>
					<span class="tag is-warning is-light">GET /discord/linked (admin key)</span>
				</div>
			</div>
			<h3 class="title is-5 has-text-light">Public</h3>
			<p>Public endpoints that do not require authentication:</p>
			<ul>
				<li><code>GET /freestuff/games</code> — Get recent free games</li>
				<li><code>GET /freestuff/latest</code> — Get the most recent free game</li>
				<li><code>GET /versions</code> — Fetch the beta, stable, and discord bot version numbers</li>
				<li><code>GET /commands/info</code> — Get built-in command information</li>
				<li><code>GET /heartbeat/websocket</code> — Retrieve the current heartbeat status of the WebSocket server</li>
				<li><code>GET /heartbeat/api</code> — Retrieve the current heartbeat status of the API server</li>
				<li><code>GET /heartbeat/database</code> — Retrieve the current heartbeat status of the database server</li>
				<li><code>GET /system/uptime</code> — Retrieve current API process uptime</li>
				<li><code>GET /chat-instructions</code> — Return the AI system instructions used by the Twitch chat bot (<code>?discord</code> flag switches to the Discord-specific instructions file if present)</li>
				<li><code>GET /api/song</code> — Get the number of remaining song requests for the current reset period</li>
				<li><code>GET /api/exchangerate</code> — Retrieve the number of remaining exchange rate requests for the current reset period</li>
				<li><code>GET /api/weather</code> — Retrieve the number of remaining weather API requests for the current day, as well as the time remaining until midnight</li>
			</ul>
			<h3 class="title is-5 has-text-light">Commands</h3>
			<p>Endpoints for retrieving command responses and data (requires user API key; admins can query any user's data with the <code>channel</code> parameter):</p>
			<ul>
				<li><code>GET /quotes</code> — Retrieve a random quote from the database of quotes, based on a random author</li>
				<li><code>GET /fortune</code> — Retrieve a random fortune from the database of fortunes</li>
				<li><code>GET /kill</code> — Fetch kill command responses for various events.</li>
				<li><code>GET /joke</code> — Fetch a random joke from a joke API, filtered to exclude inappropriate content.</li>
				<li><code>GET /sound-alerts</code> — Retrieve a list of all sound alert files available for the authenticated user from the website server</li>
				<li><code>GET /custom-commands</code> — Get list of custom commands for your account</li>
				<li><code>GET /user-points</code> — Get user points</li>
				<li><code>GET /weather</code> — Retrieve current weather data for a given location and send it to the WebSocket server</li>
			</ul>
			<h3 class="title is-5 has-text-light">User Points Integrations</h3>
			<p>You can use these POST endpoints for custom integrations that add or remove points from a user:</p>
			<ul>
				<li><code>POST /user-points/credit</code> — Adds points to the user.</li>
				<li><code>POST /user-points/debit</code> — Removes points from the user.</li>
			</ul>
			<div class="box has-background-dark" style="border-radius:8px; border:1px solid #363636;">
				<p class="has-text-light"><strong>Examples</strong></p>
				<p class="has-text-light" style="margin-top:0.5rem; margin-bottom:0.5rem;"><strong>CREDIT</strong></p>
				<pre style="background:#111; color:#dcdcdc; padding:0.75rem; border-radius:6px;"><code>https://api.botofthespecter.com/user-points/credit?api_key=1234&username=test&amount=1</code></pre>
				<p class="has-text-light" style="margin-top:0.75rem; margin-bottom:0.5rem;"><strong>DEBIT</strong></p>
				<pre style="background:#111; color:#dcdcdc; padding:0.75rem; border-radius:6px;"><code>https://api.botofthespecter.com/user-points/debit?api_key=1234&username=test&amount=1&allow_negative=false</code></pre>
				<p class="has-text-light" style="margin-top:0.75rem; margin-bottom:0;">
					Full endpoint docs:<br>
					<a href="https://api.botofthespecter.com/docs#/Commands/credit_user_points" target="_blank" class="has-text-link">https://api.botofthespecter.com/docs#/Commands/credit_user_points</a><br>
					<a href="https://api.botofthespecter.com/docs#/Commands/debit_user_points" target="_blank" class="has-text-link">https://api.botofthespecter.com/docs#/Commands/debit_user_points</a>
				</p>
			</div>
			<h3 class="title is-5 has-text-light">User Account</h3>
			<p>Endpoints for managing user account data and bot status (requires user API key; admins can query any user's data with the <code>channel</code> parameter):</p>
			<ul>
				<li><code>GET /account</code> — Get account information</li>
				<li><code>GET /bot/status</code> — Get chat bot status</li>
			</ul>
			<h3 class="title is-5 has-text-light">Webhooks</h3>
			<p>Endpoints for receiving webhook events from external services (requires API key authentication):</p>
			<ul>
				<li><code>POST /fourthwall</code> — This endpoint allows you to send webhook data from FOURTHWALL to be processed by the bot's WebSocket server</li>
				<li><code>POST /kofi</code> — This endpoint allows you to receive KOFI webhook events and forward them to the WebSocket server</li>
				<li><code>POST /patreon</code> — This endpoint allows you to send webhook data from Patreon to be processed by the bot's WebSocket server</li>
			</ul>
			<h3 class="title is-5 has-text-light">WebSocket Triggers</h3>
			<p>Endpoints that trigger real-time events via WebSocket to the bot and overlays (requires user API key):</p>
			<ul>
				<li><code>GET /websocket/tts</code> — Send a text-to-speech (TTS) event to the WebSocket server, allowing TTS to be triggered via API</li>
				<li><code>GET /websocket/walkon</code> — Trigger the 'Walkon' event for a specified user via the WebSocket server. Supports .mp3 (audio) and .mp4 (video) walkons</li>
				<li><code>GET /websocket/deaths</code> — Trigger the 'Deaths' event with custom death text for a game via the WebSocket server</li>
				<li><code>GET /websocket/sound_alert</code> — Trigger a sound alert for the specified sound file via the WebSocket server</li>
				<li><code>GET /websocket/custom_command</code> — Trigger a custom command via API</li>
				<li><code>GET /websocket/stream_online</code> — Send a 'Stream Online' event to the WebSocket server to notify that the stream is live</li>
				<li><code>GET /websocket/raffle_winner</code> — Trigger raffle winner event via API</li>
				<li><code>GET /websocket/stream_offline</code> — Send a 'Stream Offline' event to the WebSocket server to notify that the stream is offline</li>
				<li><code>POST /SEND_OBS_EVENT</code> — Send a 'OBS EVENT' to the WebSocket server to notify the system of a change in the OBS Connector</li>
			</ul>
			<h3 class="title is-5 has-text-light">Admin Only</h3>
			<p>Administrative endpoints that require admin API key authentication:</p>
			<ul>
				<li><code>GET /authorizedusers</code> — Get a list of authorized users for full beta access to the Specter ecosystem</li>
				<li><code>GET /discord/linked</code> — Check if Discord user is linked</li>
			</ul>
			<h2 class="title is-4 has-text-light">Using the API</h2>
			<p>To use the BotOfTheSpecter API include your API key as a URL query parameter on every request.<br>Example: <code>https://api.botofthespecter.com/quotes?api_key=YOUR_API_KEY</code>.<br>Do not expose the key in public client-side code; treat it like a secret and rotate it if you suspect compromise.</p>
			<div class="box has-background-dark" style="border-radius:8px; border:1px solid #363636;">
				<p class="has-text-light"><strong>Examples</strong> — choose a language to view example requests. Authentication is done by appending <code>?api_key=YOUR_API_KEY</code> to the request URL.</p>
				<div style="display:flex; gap:12px; align-items:center; margin-top:0.5rem;">
					<label class="has-text-light">Example language:</label>
					<select id="exampleLang" style="border-radius:6px; background:#222; color:#fff; border:1px solid #444; padding:6px;">
						<option value="curl">curl</option>
						<option value="javascript">JavaScript (fetch)</option>
						<option value="python">Python (requests)</option>
						<option value="php">PHP (curl)</option>
						<option value="java">Java (HttpClient)</option>
					</select>
				</div>
				<div style="margin-top:1rem;">
					<pre class="code-sample" data-lang="curl" style="background:#111; color:#dcdcdc; padding:0.75rem; border-radius:6px;"><code>curl "https://api.botofthespecter.com/quotes?api_key=YOUR_API_KEY"</code></pre>
					<pre class="code-sample" data-lang="javascript" style="display:none; background:#111; color:#dcdcdc; padding:0.75rem; border-radius:6px;"><code>fetch('https://api.botofthespecter.com/quotes?api_key=YOUR_API_KEY')
.then(r =&gt; r.json()).then(console.log);
</code></pre>
					<pre class="code-sample" data-lang="python" style="display:none; background:#111; color:#dcdcdc; padding:0.75rem; border-radius:6px;"><code>import requests

resp = requests.get('https://api.botofthespecter.com/quotes?api_key=YOUR_API_KEY')
print(resp.json())
</code></pre>
					<pre class="code-sample" data-lang="php" style="display:none; background:#111; color:#dcdcdc; padding:0.75rem; border-radius:6px;"><code>$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.botofthespecter.com/quotes?api_key=YOUR_API_KEY');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);
echo $response;
</code></pre>
					<pre class="code-sample" data-lang="java" style="display:none; background:#111; color:#dcdcdc; padding:0.75rem; border-radius:6px;"><code>// Java 11+ HttpClient
HttpClient client = HttpClient.newHttpClient();
HttpRequest request = HttpRequest.newBuilder()
	.uri(URI.create("https://api.botofthespecter.com/quotes?api_key=YOUR_API_KEY"))
	.GET()
	.build();
HttpResponse&lt;String&gt; resp = client.send(request, HttpResponse.BodyHandlers.ofString());
System.out.println(resp.body());
</code></pre>
				</div>
								<script>
								(function(){
									var sel = document.getElementById('exampleLang');
									var blocks = Array.prototype.slice.call(document.querySelectorAll('.code-sample'));
									function show(v){ blocks.forEach(function(b){ b.style.display = (b.dataset.lang === v ? 'block' : 'none'); }); }
									sel.addEventListener('change', function(e){ show(e.target.value); });
									show(sel.value);
								})();
								</script>
				<p class="has-text-light" style="margin-top:0.75rem;">Replace <code>YOUR_API_KEY</code> with the API key from your dashboard. Keep keys secret and rotate them if you suspect a compromise.</p>
			</div>
		</div>
	</div>
</section>
<?php
$content = ob_get_clean();
include 'layout.php';
?>