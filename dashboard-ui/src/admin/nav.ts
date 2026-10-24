// Mirrors the old admin menu (dashboard/menu.php, admin role) item for item.
// `path` is set once a page is ported; until then the item opens the old page.
export const OLD_ADMIN = 'https://admin.botofthespecter.com'
export type AdminLink = { label: string; icon: string; old: string; path?: string }
export type AdminItem = AdminLink | { divider: true }

export const ADMIN_NAV: AdminItem[] = [
  { label: 'Dashboard', icon: 'fas fa-tachometer-alt', old: 'index.php', path: '/' },
  { divider: true },
  { label: 'User Management', icon: 'fas fa-users-cog', old: 'users.php', path: '/users' },
  { label: 'Start User Bots', icon: 'fas fa-play-circle', old: 'start_bots.php', path: '/start-bots' },
  { label: 'EventSub Connections', icon: 'fas fa-bell', old: 'event_sub.php', path: '/eventsub' },
  { divider: true },
  { label: 'Log Management', icon: 'fas fa-clipboard-list', old: 'logs.php', path: '/logs' },
  { label: 'Feedback', icon: 'fas fa-comments', old: 'feedback.php', path: '/feedback' },
  { label: 'Beta Programs', icon: 'fas fa-flask', old: 'beta_programs.php', path: '/beta-programs' },
  { divider: true },
  { label: 'Twitch Tokens', icon: 'fab fa-twitch', old: 'twitch_tokens.php', path: '/twitch-tokens' },
  { label: 'Discord Bot Overview', icon: 'fab fa-discord', old: 'discordbot_overview.php', path: '/discord' },
  { label: 'Websocket Clients', icon: 'fas fa-plug', old: 'websocket_clients.php', path: '/websocket-clients' },
  { divider: true },
  { label: 'API Keys', icon: 'fas fa-key', old: 'api_keys.php', path: '/api-keys' },
  { label: 'Custom Webhooks', icon: 'fas fa-satellite-dish', old: 'webhooks.php', path: '/webhooks' },
  { label: 'Spam Patterns', icon: 'fas fa-ban', old: 'spam_patterns.php', path: '/spam-patterns' },
  { label: 'DB Migrations', icon: 'fas fa-database', old: 'migrations.php', path: '/migrations' },
  { label: 'User Databases', icon: 'fas fa-user-check', old: 'user_schema.php', path: '/user-schema' },
  { label: 'Known Bots', icon: 'fas fa-robot', old: 'known_bots.php', path: '/known-bots' },
  { divider: true },
  { label: 'Caddy Server', icon: 'fas fa-server', old: 'caddy.php', path: '/caddy' },
  { label: 'CDN File Manager', icon: 'fas fa-folder-open', old: 'cdn_files.php', path: '/cdn-files' },
]

export const oldUrl = (file: string) => `${OLD_ADMIN}/${file}`
