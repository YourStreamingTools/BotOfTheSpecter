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
			<p>BotOfTheSpecter's API provides several endpoints to interact with bot features.<br>All API requests must include your API key as a URL query parameter, for example <code>?api_key=YOUR_API_KEY</code>.<br>This is the only supported authentication method.</p>
			<h3 class="title is-5 has-text-light">Bot</h3>
			<ul>
				<li><code>GET /versions</code> — Get the current bot versions</li>
				<li><code>GET /chat-instructions</code> — Get AI chat instructions</li>
				<li><code>GET /api/song</code> — Get the remaining song requests</li>
				<li><code>GET /api/exchangerate</code> — Get the remaining exchangerate requests</li>
				<li><code>GET /api/weather</code> — Get the remaining weather API requests</li>
			</ul>
			<h3 class="title is-5 has-text-light">Commands</h3>
			<ul>
				<li><code>GET /quotes</code> — Get a random quote</li>
				<li><code>GET /fortune</code> — Get a random fortune</li>
				<li><code>GET /kill</code> — Retrieve the Kill Command responses</li>
				<li><code>GET /joke</code> — Get a random joke</li>
				<li><code>GET /weather</code> — Get weather data and trigger WebSocket weather event</li>
			</ul>
			<h3 class="title is-5 has-text-light">Webhooks</h3>
			<ul>
				<li><code>POST /fourthwall</code> — Receive and process FOURTHWALL webhook requests</li>
				<li><code>POST /kofi</code> — Receive and process Ko-fi webhook requests</li>
				<li><code>POST /patreon</code> — Receive and process Patreon webhook requests</li>
			</ul>
			<h3 class="title is-5 has-text-light">WebSocket</h3>
			<p>Endpoints for interacting with the internal WebSocket server / triggering overlay events:</p>
			<ul>
				<li><code>GET /websocket/tts</code> — Trigger TTS via API</li>
				<li><code>GET /websocket/walkon</code> — Trigger Walkon via API</li>
				<li><code>GET /websocket/deaths</code> — Trigger Deaths via API</li>
				<li><code>GET /websocket/sound_alert</code> — Trigger Sound Alert via API</li>
				<li><code>GET /websocket/stream_online</code> — Trigger Stream Online via API</li>
				<li><code>GET /websocket/stream_offline</code> — Trigger Stream Offline via API</li>
				<li><code>POST /SEND_OBS_EVENT</code> — Pass OBS events to the websocket server</li>
			</ul>
			<h3 class="title is-5 has-text-light">Heartbeats</h3>
			<ul>
				<li><code>GET /heartbeat/websocket</code> — Get the heartbeat status of the websocket server</li>
				<li><code>GET /heartbeat/api</code> — Get the heartbeat status of the API server</li>
				<li><code>GET /heartbeat/database</code> — Get the heartbeat status of the database server</li>
			</ul>
			<h3 class="title is-5 has-text-light">Webhooks</h3>
			<ul>
				<li><code>POST /fourthwall</code> - Receive and process FourthWall webhook requests</li>
				<li><code>POST /kofi</code> - Receive and process Ko-fi webhook requests</li>
			</ul>
			<h3 class="title is-5 has-text-light">System Status</h3>
			<ul>
				<li><code>GET /heartbeat/websocket</code> - Check WebSocket server status</li>
				<li><code>GET /heartbeat/api</code> - Check API server status</li>
				<li><code>GET /heartbeat/database</code> - Check database server status</li>
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