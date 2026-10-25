// Mirrors the old dashboard menu (dashboard/menu.php, default role) item for item.
export type NavLink = { label: string; icon: string; path: string }
export type NavDivider = { divider: true }
export type NavGroup = { label: string; icon: string; children: (NavLink | NavDivider)[] }
export type NavItem = NavLink | NavDivider | NavGroup

export const NAV: NavItem[] = [
  { label: "Dashboard", icon: "fas fa-home", path: "/" },
  { label: "Bot Control", icon: "fas fa-robot", path: "/bot" },
  { divider: true },
  { label: "Chat & Commands", icon: "fas fa-comments", children: [
    { label: "View Custom Commands", icon: "fas fa-code", path: "/custom-commands" },
    { label: "Manage User Commands", icon: "fas fa-user-cog", path: "/manage-custom-user-commands" },
    { label: "View Built-in Commands", icon: "fas fa-cubes", path: "/builtin" },
    { divider: true },
    { label: "Timed Messages", icon: "fas fa-clock", path: "/timed-messages" },
    { label: "Welcome Messages", icon: "fas fa-handshake", path: "/known-users" }
  ] },
  { label: "Alerts & Media", icon: "fas fa-bolt", children: [
    { label: "Alerts", icon: "fas fa-bell", path: "/alerts" },
    { divider: true },
    { label: "Media", icon: "fas fa-photo-film", path: "/media" },
    { label: "Emote Vault", icon: "fas fa-icons", path: "/emote-vault" },
    { label: "Media Player", icon: "fas fa-play-circle", path: "/media-player" },
    { label: "VOD Music", icon: "fas fa-music", path: "/music" }
  ] },
  { label: "Overlays", icon: "fas fa-desktop", children: [
    { label: "Overlays", icon: "fas fa-layer-group", path: "/overlays" },
    { label: "Closed Captions", icon: "fas fa-closed-captioning", path: "/closed-captions" },
    { divider: true },
    { label: "Avatar", icon: "fas fa-user-astronaut", path: "/avatar" },
    { label: "Pet", icon: "fas fa-paw", path: "/pet" },
    { label: "Social Roller", icon: "fas fa-share-alt", path: "/socials" },
    { label: "Working & Study", icon: "fas fa-user-clock", path: "/working-or-study" },
    { label: "Game Tracker", icon: "fas fa-gamepad", path: "/tracked-games" },
    { label: "Makers & Crafting", icon: "fas fa-palette", path: "/makers" },
    { label: "Subathon", icon: "fas fa-hourglass-half", path: "/subathon" }
  ] },
  { divider: true },
  { label: "Engagement", icon: "fas fa-heart", children: [
    { label: "Points System", icon: "fas fa-coins", path: "/bot-points" },
    { label: "Point Store", icon: "fas fa-store", path: "/point-store" },
    { label: "Channel Rewards", icon: "fas fa-gift", path: "/channel-rewards" },
    { divider: true },
    { label: "Raffles", icon: "fas fa-ticket-alt", path: "/raffles" },
    { label: "Twitch Polls", icon: "fas fa-poll", path: "/polls" },
    { label: "Stream Bingo", icon: "fas fa-trophy", path: "/bingo" },
    { label: "Tanggle", icon: "fas fa-shapes", path: "/tanggle" },
    { divider: true },
    { label: "Counters", icon: "fas fa-calculator", path: "/counters" },
    { label: "Stream Watch Streaks", icon: "fas fa-fire", path: "/stream-streak" }
  ] },
  { label: "Community", icon: "fas fa-users", children: [
    { label: "Followers", icon: "fas fa-user-plus", path: "/followers" },
    { label: "Subscribers", icon: "fas fa-star", path: "/subscribers" },
    { label: "Moderators", icon: "fas fa-user-shield", path: "/mods" },
    { label: "VIP's", icon: "fas fa-gem", path: "/vips" },
    { divider: true },
    { label: "Channel Moderation", icon: "fas fa-gavel", path: "/moderation" },
    { label: "Birthdays", icon: "fas fa-cake-candles", path: "/birthdays" },
    { label: "Raids", icon: "fas fa-bullhorn", path: "/raids" }
  ] },
  { label: "Insights", icon: "fas fa-chart-line", children: [
    { label: "Bot Logs", icon: "fas fa-clipboard-list", path: "/logs" },
    { label: "EventSub Notifications", icon: "fas fa-inbox", path: "/notifications" },
    { divider: true },
    { label: "Schedule", icon: "fas fa-calendar-days", path: "/schedule" },
    { label: "Videos", icon: "fas fa-photo-video", path: "/videos" }
  ] },
  { divider: true },
  { label: "Streaming & Integrations", icon: "fas fa-plug", children: [
    { label: "Streaming", icon: "fas fa-tower-broadcast", path: "/streaming" },
    { label: "Platform Integrations", icon: "fas fa-globe", path: "/integrations" },
    { divider: true },
    { label: "Discord Bot", icon: "fab fa-discord", path: "/discordbot" },
    { label: "StreamElements", icon: "fas fa-globe", path: "/streamelements" },
    { label: "StreamLabs", icon: "fas fa-gift", path: "/streamlabs" },
    { divider: true },
    { label: "Controller App", icon: "fas fa-sliders", path: "/controllerapp" },
    { label: "Specter Modules", icon: "fa fa-puzzle-piece", path: "/modules" }
  ] },
  { divider: true },
  { label: "To Do List", icon: "fas fa-list-check", path: "/todolist" },
  { label: "Premium", icon: "fas fa-crown", path: "/premium" }
]

export function navTitle(path: string): string | null {
  for (const i of NAV) {
    if ('path' in i && i.path === path) return i.label
    if ('children' in i) for (const c of i.children) if ('path' in c && c.path === path) return c.label
  }
  return null
}
