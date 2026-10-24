// Port of dashboard/controllerapp.php: Controller App (OBS Connector) and the
// Stream Deck plugin. Static content, no API.
const OBS_V = '1.1', DESK_V = '2.0.0', SD_V = '1.0.0.2'
const OBS_DL = `https://cdn.botofthespecter.com/app-builds/OBSConnector/BotOfTheSpecter-OBS-Connector-v${OBS_V}.exe`
const DOCS = 'https://app.botofthespecter.com'
const SD_DL = `https://cdn.botofthespecter.com/app-builds/StreamDeck/BotOfTheSpecter-${SD_V}.streamDeckPlugin`
type F = [icon: string, title: string, desc: string, soon: boolean]
const OBS: F[] = [
  ['fas fa-object-group', 'OBS Scene Control', 'Switch program and preview scenes live, see your full scene tree update as OBS changes, and forward scene events to the bot relay.', true],
  ['fas fa-sliders-h', 'Sources & Audio Mixer', 'Toggle source visibility and filters, mute and set levels, and watch live peak meters, plus stream, record, replay buffer and virtual camera controls.', true],
  ['fas fa-tachometer-alt', 'Live Dashboard', 'Twitch live status (viewers, game, title), OBS health (bitrate, FPS, CPU, dropped frames, accurate LIVE timecode) and a rolling activity feed in one place.', true],
  ['fas fa-comments', 'Chat & Moderation', 'Follow live Twitch chat and moderation events (timeouts, bans, deletes, clears) without leaving the control room.', true],
  ['fas fa-coins', 'Channel Points', 'Create and edit manageable rewards, fulfill or refund redemptions, import website rewards, and group rewards for bulk enable/disable.', true],
  ['fas fa-gift', 'Giveaways, Polls & More', 'Run raffles/giveaways, Twitch polls and predictions, timers, the soundboard and an alerts feed from the same desktop app.', true],
  ['fas fa-robot', 'Bot Relay & Integration', 'Connect with your API key to the BotOfTheSpecter relay so OBS events and stream data stay linked to your bot account.', false],
  ['fas fa-cogs', 'Automations & Actions', 'Build a visual rules library: triggers, checks and reusable actions (webhooks, variables, commands, TTS, rewards, ads, markers, clips and more), organised in folders and queues.', true],
  ['fas fa-lock', 'Secure Connection', 'Use your BotOfTheSpecter API key on your PC; connections run through the official relay and secrets are kept out of casual logs where possible.', false],
]
const SD: F[] = [
  ['fas fa-volume-up', 'Trigger Sound Alerts', 'Pick any sound from your BotOfTheSpecter sound library and fire it instantly with a key press. Sound lists load from your account and can be refreshed from the action settings.', false],
  ['fas fa-terminal', 'Trigger Custom Commands', 'Select one of your custom bot commands and run it from a Stream Deck key, the same result as typing the command in chat. Command lists load from your account and can be refreshed any time.', false],
  ['fas fa-key', 'Global API Key', 'Configure your API key once in the plugin or action settings. Stream Deck stores it securely and reuses it for every Sound Alert and Custom Command action.', false],
  ['fas fa-film', 'Trigger Video Alerts', 'Play video alerts on your stream at the press of a button.', true],
  ['fas fa-walking', 'Trigger Walkons Manually', 'Manually trigger walkon sequences for any chatter without waiting for them to chat.', true],
  ['fas fa-plug', 'Controller App Integration', 'Work seamlessly alongside the Controller App to give you full stream control from your Stream Deck.', true],
]
const Feats = ({ list }: { list: F[] }) => <div className="ca-feats">{list.map(([i, t, d, soon]) => <div key={t} className="ca-feat">
  <div className="ca-feat-h"><i className={i} /><b>{t}</b>{soon && <span className="pill pill-warn">Coming Soon</span>}</div><p className="muted small">{d}</p></div>)}</div>
const Sub = ({ icon, children }: { icon: string; children: string }) => <h4 className="ca-sub"><i className={icon} /> {children}</h4>

export default function ControllerApp() {
  return <div className="page ca-page">
    <section className="card">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-plug" /> Controller App</h3>
        <div className="tk-pills"><span className="pill pill-ok"><i className="fas fa-cube" /> v{OBS_V}</span><span className="pill pill-warn"><i className="fas fa-flask" /> Desktop {DESK_V} - Coming Soon</span></div></div>
      <div className="ca-banner"><b>Bridge OBS Studio with BotOfTheSpecter</b><p>The Controller App connects your PC to BotOfTheSpecter so the bot can talk to OBS Studio and your stream events stay in sync. Download the current stable release today, and watch for the upcoming Desktop rewrite with a full live control room.</p></div>
      <div className="notice notice-info"><i className="fas fa-hammer" /> <span><b>BotOfTheSpecter Desktop {DESK_V} is in development.</b> We are rebuilding the Controller App as a full desktop control room (Windows, macOS, and Linux) with live OBS control, chat, channel points, giveaways, polls, predictions, automations and more. It is not ready for public download yet; the current stable connector remains available below.</span></div>
      <Sub icon="fas fa-star">Key Features</Sub>
      <Feats list={OBS} />
      <Sub icon="fas fa-info-circle">Requirements</Sub>
      <p className="muted">OBS Studio with obs-websocket enabled, plus a BotOfTheSpecter account and API key from this dashboard. The upcoming Desktop app will require OBS Studio 28+ (obs-websocket 5.x).</p>
      <Sub icon="fas fa-download">Download Controller App (stable)</Sub>
      <p className="muted">Download the current stable Windows build below. The full Desktop rewrite is not available for download yet.</p>
      <div className="row-btns"><a className="btn" href={OBS_DL}><i className="fas fa-download" /> Download Controller App v{OBS_V} (Windows)</a><a className="btn btn-ghost" href={DOCS} target="_blank" rel="noopener noreferrer"><i className="fas fa-book" /> App docs</a></div>
      <p className="muted small">Desktop v{DESK_V} is not ready for public download; no installer is published yet.</p>
    </section>
    <section className="card">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-th-large" /> Stream Deck Plugin</h3><div className="tk-pills"><span className="pill pill-ok"><i className="fas fa-cube" /> v{SD_V}</span></div></div>
      <div className="ca-banner"><b>Sound Alerts & Custom Commands on Your Stream Deck</b><p>The BotOfTheSpecter Stream Deck plugin lets you trigger sound alerts and custom chat commands with a single key press. Set your API key once in the plugin settings and it is shared across every action on your deck.</p></div>
      <Sub icon="fas fa-star">Key Features</Sub>
      <Feats list={SD} />
      <div className="ca-two">
        <div><Sub icon="fas fa-list-ol">Quick setup</Sub><ol className="ca-steps">
          <li>Copy your API key from your <a href="/profile">Profile</a> page on the dashboard.</li>
          <li>Download and install the plugin into the Elgato Stream Deck software.</li>
          <li>Add a Sound Alert or Custom Command action to a key, paste your API key (or set it under plugin settings), then choose a sound or command from the list.</li>
          <li>Press the key while your bot is running to trigger the action on stream.</li></ol></div>
        <div><Sub icon="fas fa-info-circle">Requirements</Sub><ul className="tk-list muted"><li>Elgato Stream Deck software 6.9 or newer</li><li>Windows 10 or later</li><li>macOS 12 or later</li><li>BotOfTheSpecter API key (Profile page)</li><li>Bot running for live sound and command triggers</li></ul></div>
      </div>
      <Sub icon="fas fa-download">Download Stream Deck Plugin</Sub>
      <p className="muted">Download the .streamDeckPlugin package, then open it (or double-click) to install into the Elgato Stream Deck software. Requires Stream Deck software 6.9 or newer on Windows 10+ or macOS 12+.</p>
      <div className="row-btns"><a className="btn" href={SD_DL}><i className="fas fa-download" /> Download Plugin v{SD_V}</a><a className="btn btn-ghost" href="/profile"><i className="fas fa-user-cog" /> Open Profile for API key</a></div>
    </section>
  </div>
}
