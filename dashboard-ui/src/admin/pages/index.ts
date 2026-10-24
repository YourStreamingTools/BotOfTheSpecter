import type { ComponentType } from 'react'
import KnownBots from './KnownBots'
import EventSub from './EventSub'
import Caddy from './Caddy'
import UserSchema from './UserSchema'
import Migrations from './Migrations'
import CdnFiles from './CdnFiles'
import TwitchTokens from './TwitchTokens'
import StartBots from './StartBots'
import Home from './Home'
import Users from './Users'
import Logs from './Logs'
import WebsocketClients from './WebsocketClients'
import DiscordOverview from './DiscordOverview'
import Webhooks from './Webhooks'
import ApiKeys from './ApiKeys'
import Feedback from './Feedback'
import BetaPrograms from './BetaPrograms'
import SpamPatterns from './SpamPatterns'

// Route -> ported page. nav.ts sets `path` on the same items.
export const PAGES: Record<string, ComponentType> = {
  '/known-bots': KnownBots,
  '/eventsub': EventSub,
  '/caddy': Caddy,
  '/user-schema': UserSchema,
  '/migrations': Migrations,
  '/cdn-files': CdnFiles,
  '/twitch-tokens': TwitchTokens,
  '/start-bots': StartBots,
  '/': Home,
  '/users': Users,
  '/logs': Logs,
  '/websocket-clients': WebsocketClients,
  '/discord': DiscordOverview,
  '/webhooks': Webhooks,
  '/api-keys': ApiKeys,
  '/feedback': Feedback,
  '/beta-programs': BetaPrograms,
  '/spam-patterns': SpamPatterns,
}
