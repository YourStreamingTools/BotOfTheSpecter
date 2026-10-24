// Static data mirrored from dashboard/alerts.php (categories, options, samples).
export type Alert = {
  id: number; alert_category: string; variant_name: string; variant_index: number | string; enabled: number | string
  alert_condition: string | null; duration: number | string; animation_in: string; animation_out: string
  animation_in_duration: number | string; animation_out_duration: number | string; layout_preset: string
  bg_color: string; bg_opacity: number | string; padding: number | string; gap: number | string
  rounded_corners: number | string; drop_shadow: number | string; message_template: string | null
  font_family: string; font_weight: string; font_size: number | string; text_alignment: string; text_vertical_alignment: string
  text_color: string; accent_color: string; ad_color?: string | null; text_drop_shadow: number | string; tts_enabled: number | string
  alert_image: string | null; image_scale: number | string; image_volume: number | string; alert_sound: string | null
  sound_volume: number | string; celebration_enabled: number | string; celebration_effect: string; celebration_intensity: string
  screen_position: string | null; position_x: number | string | null; position_y: number | string | null; video_size?: number | string | null
}
export type Group = 'twitch' | 'integrations' | 'own'
export const CATS: { key: string; icon: string; label: string; group: Group }[] = [
  { key: 'follow', icon: 'fas fa-heart', label: 'Follows', group: 'twitch' },
  { key: 'subscription', icon: 'fas fa-star', label: 'Subscriptions', group: 'twitch' },
  { key: 'gift_subscription', icon: 'fas fa-gift', label: 'Gifted subs', group: 'twitch' },
  { key: 'bits', icon: 'fas fa-gem', label: 'Bits', group: 'twitch' },
  { key: 'raid', icon: 'fas fa-bullhorn', label: 'Raids', group: 'twitch' },
  { key: 'hype_train', icon: 'fas fa-train', label: 'Hype trains', group: 'twitch' },
  { key: 'ad_break', icon: 'fas fa-rectangle-ad', label: 'Ads', group: 'twitch' },
  { key: 'charity', icon: 'fas fa-hand-holding-heart', label: 'Charity', group: 'twitch' },
  { key: 'channel_points', icon: 'fas fa-circle-dot', label: 'Channel points', group: 'twitch' },
  { key: 'discord_join', icon: 'fab fa-discord', label: 'Discord joins', group: 'integrations' },
  { key: 'kofi', icon: 'fas fa-mug-hot', label: 'Ko-fi tips', group: 'integrations' },
  { key: 'patreon', icon: 'fab fa-patreon', label: 'Patreon', group: 'integrations' },
  { key: 'fourthwall', icon: 'fas fa-store', label: 'Fourthwall', group: 'integrations' },
  { key: 'subathon', icon: 'fas fa-hourglass-half', label: 'Subathon', group: 'integrations' },
  { key: 'stream_bingo', icon: 'fas fa-trophy', label: 'Stream bingo', group: 'integrations' },
  { key: 'watch_streak', icon: 'fas fa-fire', label: 'Watch streaks', group: 'integrations' },
  { key: 'weather', icon: 'fas fa-cloud-sun', label: 'Weather', group: 'own' },
  { key: 'deaths', icon: 'fas fa-skull', label: 'Death counter', group: 'own' },
  { key: 'walkons', icon: 'fas fa-door-open', label: 'Walk-ons', group: 'own' },
  { key: 'sound_alerts', icon: 'fas fa-volume-up', label: 'Sound alerts', group: 'own' },
  { key: 'video_alerts', icon: 'fas fa-film', label: 'Video alerts', group: 'own' },
  { key: 'tts', icon: 'fas fa-comment-dots', label: 'Text to speech', group: 'own' },
  { key: 'goals', icon: 'fas fa-bullseye', label: 'Goals', group: 'own' },
]
export const catLabel = (k: string) => CATS.find((c) => c.key === k)?.label ?? k
export const SIMPLE = ['walkons', 'sound_alerts', 'video_alerts', 'tts']
export const MODULE = ['weather', 'deaths']
export const FIXED = ['discord_join', 'kofi', 'patreon', 'fourthwall', 'ad_break', 'watch_streak', 'goals']
export const MEDIA_LEVELS = ['sound_alerts', 'video_alerts', 'walkons', 'tts']
export const TESTABLE_PLAYBACK = ['sound_alerts', 'video_alerts', 'tts']
export const SWITCH_GATED = ['sound_alerts', 'video_alerts', 'tts', 'deaths', 'weather', 'goals']
export const FIXED_DEFAULTS: Record<string, string> = {
  discord_join: '{username}\nhopped into the Discord!', kofi: '{username}\nsupported on Ko-fi!', patreon: '{username}\nbecame a patron!',
  fourthwall: '{username}\nsupported on Fourthwall!', ad_break: 'Ads are playing\nBack in {duration}',
  watch_streak: '{username}\nis on a {streak}-stream watch streak!', goals: '{description}\n{current_amount} / {target_amount}',
}
export const PLAYBACK_NOTES: Record<string, string> = {
  sound_alerts: 'Sound alerts play the clip you mapped on the Media page. Switch this off to silence them.',
  video_alerts: 'Video alerts play the clip you mapped on the Media page. Switch this off to hide them. Take the old Video Alerts browser source out of OBS so the clip does not play twice.',
  tts: 'Text to speech reads reward and command lines aloud. Switch this off to silence it.',
}
export const VARS: Record<string, string[]> = {
  follow: ['{username}'], subscription: ['{username}', '{months}', '{tier}'], gift_subscription: ['{username}', '{amount}', '{tier}'],
  bits: ['{username}', '{amount}'], raid: ['{username}', '{viewers}'], hype_train: ['{level}'], charity: ['{username}', '{amount}', '{charity_name}'],
  channel_points: ['{username}', '{reward_title}', '{cost}', '{message}'], discord_join: ['{username}'],
  kofi: ['{username}', '{amount}', '{message}', '{tier_name}'], patreon: ['{username}', '{amount}', '{tier_name}', '{lifetime}'],
  fourthwall: ['{username}', '{amount}', '{item}', '{message}', '{interval}'], subathon: ['{added_minutes}'],
  stream_bingo: ['{username}', '{rank_text}', '{bingo_event_name}', '{bingo_number}', '{events_count}'], watch_streak: ['{username}', '{streak}'],
  ad_break: ['{duration}', '{seconds}'], goals: ['{description}', '{current_amount}', '{target_amount}', '{goal_type}'],
}
export const SAMPLE: Record<string, string> = {
  username: 'PreviewUser', amount: '100', months: '3', viewers: '42', tier: '1', added_minutes: '5', streak: '7', duration: '1:30', seconds: '90',
  message: 'Hello!', tier_name: 'Gold', lifetime: '50', item: 'Hoodie', interval: 'monthly', level: '5', charity_name: 'Example Charity',
  description: 'Follower goal', current_amount: '10', target_amount: '100', goal_type: 'follower',
}
export const BINGO: [string, string][] = [['STREAM_BINGO_STARTED', 'Game Started'], ['STREAM_BINGO_EVENT_CALLED', 'Game Event'], ['STREAM_BINGO_WINNER', 'Game Winner'], ['STREAM_BINGO_ENDED', 'Game Ended']]
export const ANIM_IN: [string, string][] = [['fadeIn', 'Fade in'], ['slideInLeft', 'Slide left'], ['slideInRight', 'Slide right'], ['slideInUp', 'Slide up'], ['slideInDown', 'Slide down'], ['bounceIn', 'Bounce'], ['zoomIn', 'Zoom']]
export const ANIM_OUT: [string, string][] = [['fadeOut', 'Fade out'], ['slideOutLeft', 'Slide left'], ['slideOutRight', 'Slide right'], ['slideOutUp', 'Slide up'], ['slideOutDown', 'Slide down'], ['bounceOut', 'Bounce'], ['zoomOut', 'Zoom']]
export const FONTS = ['Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Oswald', 'Raleway', 'Poppins', 'Nunito', 'Ubuntu', 'Bebas Neue', 'Bangers', 'Permanent Marker', 'Press Start 2P', 'Creepster']
export const WEIGHTS: [string, string, string][] = [['Light', '300', 'Light'], ['Regular', '400', 'Regular'], ['Medium', '500', 'Medium'], ['Semi-Bold', '600', 'Semi-Bold'], ['Bold', '700', 'Bold'], ['Extra-Bold', '800', 'Extra-Bold']]
export const LAYOUTS: [string, string][] = [['above', 'Image above text'], ['right', 'Text left, image right'], ['left', 'Image left, text right'], ['below', 'Text above, image below'], ['behind', 'Image behind text']]
export const PILL_POS: [string, string][] = [['left-top', 'Top left'], ['center-top', 'Top center'], ['right-top', 'Top right'], ['left-bottom', 'Bottom left'], ['center-bottom', 'Bottom center'], ['right-bottom', 'Bottom right']]
export const CANVAS: [string, string][] = [['1280x720', '1280 x 720 (720p)'], ['1920x1080', '1920 x 1080 (1080p)'], ['2560x1440', '2560 x 1440 (2K)']]
export const EFFECTS: [string, string][] = [['fireworks', 'Fireworks'], ['confetti', 'Confetti'], ['bubbles', 'Bubbles']]
export const INTENSITY: [string, string][] = [['light', 'Light'], ['medium', 'Medium'], ['heavy', 'Heavy']]
export const rewardIdOf = (c: string | null | undefined) => (String(c || '').match(/reward_id\s*=\s*['"]?([^'"\s]+)['"]?/) || [])[1] || ''
export const bingoOf = (c: string | null | undefined) => (String(c || '').match(/bingo_event\s*=\s*['"]?([^'"\s]+)['"]?/) || [])[1] || ''
export const condValue = (c: string | null | undefined, field: string, fb: number) => (String(c || '').match(new RegExp(field + '\\s*>?=\\s*(\\d+(?:\\.\\d+)?)')) || [])[1] || String(fb)
export const adPill = (v: string | null | undefined) => (PILL_POS.some(([k]) => k === v) ? (v as string) : 'right-top')
export const adOpacity = (v: unknown) => { const n = parseInt(String(v), 10); return isNaN(n) || n < 10 ? 100 : Math.min(100, n) }
export const adColor = (v: unknown) => (/^#[0-9A-Fa-f]{6}$/.test(String(v || '')) ? String(v) : '#F5C518')
export const hexRgb = (h: string) => { const n = parseInt(adColor(h).slice(1), 16); return `${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}` }
export const clamp = (v: number, lo: number, hi: number) => Math.max(lo, Math.min(hi, v))
export function presetToPercent(preset: string, w: number, h: number) {
  const m = 2.5, [ph, pv] = String(preset || 'center-center').split('-')
  const x = ph === 'left' ? m : ph === 'right' ? 100 - w - m : (100 - w) / 2
  const y = pv === 'top' ? m : pv === 'bottom' ? 100 - h - m : (100 - h) / 2
  return { x: clamp(x, 0, Math.max(0, 100 - w)), y: clamp(y, 0, Math.max(0, 100 - h)) }
}
// The test event the live page sends for each category.
export function testEvent(a: Alert, rewards: { reward_id: string; reward_title: string; reward_cost: number | string | null }[], testMedia: { sound?: string | null; video?: string | null }, voice: Record<string, string | null> | null): { event: string; params: Record<string, string>; file?: string } | { info: string } {
  const c = a.alert_condition || ''
  const rid = rewardIdOf(c), rw = rewards.find((r) => rid && String(r.reward_id) === rid)
  const tts: Record<string, string> = { text: 'This is a test of text to speech.' }
  if (voice) for (const k of ['voice', 'language', 'style', 'expressive_voice']) if (voice[k]) tts[k] = String(voice[k])
  const m: Record<string, { event: string; params: Record<string, string> }> = {
    follow: { event: 'TWITCH_FOLLOW', params: { user: 'TestUser' } },
    subscription: { event: 'TWITCH_SUB', params: { user: 'TestUser', sub_tier: '1', sub_months: condValue(c, 'months', 1) } },
    gift_subscription: { event: 'TWITCH_GIFT_SUB', params: { user: 'TestUser', sub_tier: '1', gift_count: condValue(c, 'gift_count', 1) } },
    bits: { event: 'TWITCH_CHEER', params: { user: 'TestUser', cheer_amount: condValue(c, 'bits', 100) } },
    raid: { event: 'TWITCH_RAID', params: { user: 'TestUser', raid_viewers: condValue(c, 'viewers', 42) } },
    hype_train: { event: 'TWITCH_HYPE_TRAIN', params: { level: condValue(c, 'level', 1) } },
    ad_break: { event: 'TWITCH_AD_BREAK', params: { duration_seconds: '15' } },
    charity: { event: 'TWITCH_CHARITY', params: { user: 'TestUser', amount: condValue(c, 'amount', 25) + ' USD', charity_name: 'Example Charity' } },
    channel_points: { event: 'TWITCH_CHANNELPOINTS', params: { user: 'TestUser', reward_id: rid, reward_title: rw?.reward_title ? String(rw.reward_title) : 'Hydrate', cost: rw?.reward_cost != null ? String(rw.reward_cost) : '500', message: 'Test redemption' } },
    discord_join: { event: 'DISCORD_JOIN', params: { member: 'TestUser' } },
    watch_streak: { event: 'WATCH_STREAK', params: { username: 'TestUser', streak: '7' } },
    goals: { event: 'TWITCH_GOAL_BEGIN', params: { description: 'Follower goal', current_amount: '10', target_amount: '100', goal_type: 'follower' } },
    subathon: { event: 'SUBATHON_ADD_TIME', params: { added_minutes: '5' } },
    deaths: { event: 'DEATHS', params: { death: '42', game: 'Test Game' } },
    weather: { event: 'WEATHER_DATA', params: {} },
    tts: { event: 'TTS', params: tts },
  }
  const cat = a.alert_category
  if (m[cat]) return m[cat]
  if (cat === 'sound_alerts' || cat === 'video_alerts') {
    const s = cat === 'sound_alerts', f = s ? testMedia.sound : testMedia.video
    if (!f) return { info: s ? 'Map a sound to a channel point reward on the Media page, then send the test.' : 'Map a video to a channel point reward on the Media page, then send the test.' }
    return s ? { event: 'SOUND_ALERT', params: { sound: f }, file: f } : { event: 'VIDEO_ALERT', params: { video: f }, file: f }
  }
  const sub = (re: RegExp, fb: string) => (c.match(re) || [])[1] || fb
  if (cat === 'fourthwall') return { event: 'FOURTHWALL', params: { fourthwall_type: sub(/fourthwall_type\s*=\s*['"]([^'"]+)['"]/, 'DONATION'), user: 'TestUser', amount: '10.00', currency: 'USD', message: 'Awesome stream!', item: 'Test Item' } }
  if (cat === 'patreon') return { event: 'PATREON', params: { patreon_type: sub(/patreon_type\s*=\s*['"]([^'"]+)['"]/, 'pledge'), user: 'TestUser', amount: '5.00', currency: 'USD', tier_name: 'Gold Tier', lifetime: '50.00' } }
  if (cat === 'kofi') {
    const t = sub(/kofi_type\s*=\s*['"]([^'"]+)['"]/, 'Donation'), p: Record<string, string> = { kofi_type: t, user: 'TestUser', amount: '5.00', currency: 'USD' }
    if (t === 'Donation') p.message = 'Awesome stream!'
    if (t === 'Subscription') p.tier_name = 'Gold'
    return { event: 'KOFI', params: p }
  }
  if (cat === 'stream_bingo') {
    const be = bingoOf(c)
    if (!be) return { info: 'Choose a bingo event from the dropdown and save before testing.' }
    const p: Record<string, string> = be === 'STREAM_BINGO_WINNER' ? { player_name: 'TestUser', rank: '1', rank_text: '1st' } : be === 'STREAM_BINGO_EVENT_CALLED' ? { display_number: '42', event_name: 'Test bingo event' } : be === 'STREAM_BINGO_STARTED' ? { is_sub_only: '0', events_count: '25' } : {}
    return { event: be, params: p }
  }
  return { info: 'Test events for this category are not wired up here yet. Use the trigger in your bot to test live.' }
}
