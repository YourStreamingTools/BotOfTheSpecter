import { useEffect, useState } from 'react'
import Modal from '../components/Modal'
import { get } from '../bot'
import { Counter, Switch, ToastBar, send, type Toast } from './commands/shared'

// Port of dashboard/channel_rewards.php: Channel Point Rewards card with the sync
// result box, no-channel-points notice, sync info with Sync Rewards, the important
// change notice, custom variables, then the three tabs (rewards table with inline
// custom message, Manage, Edit on Twitch and delete; recent redemptions with
// approve / refund; create managed reward) and the Edit Reward modal.
// Endpoints: public/api/channel-rewards. Sync is one request (no stream). No timers.
const A = '/api/channel-rewards/'
type Tw = { prompt: string; background_color: string; is_user_input_required: boolean; should_redemptions_skip_request_queue: boolean; is_paused: boolean; max_per_stream: number | null; max_per_user_per_stream: number | null; global_cooldown_seconds: number | null }
type Reward = { id: string; title: string; cost: number; image: string; synced: boolean; custom_message: string; managed_by: string; twitch?: Tw }
type Page = { success: boolean; no_channel_points: boolean; is_owner: boolean; channel: string; twitch_error: boolean; sync_message: string; rewards: Reward[] }
type Red = { id: string; user_name: string; user_input: string; status: string; redeemed_at: string; reward: { id: string; title: string; cost: number } }
type Reds = { success: boolean; managed: number; partial: boolean; data: Red[]; error?: string }
type Res = { success?: boolean; error?: string; message?: string; output?: string; title?: string }
const intOk = (v: string, lo: number, hi: number) => /^\d+$/.test(v.trim()) && +v >= lo && +v <= hi
const hexOk = (v: string) => /^#[0-9A-Fa-f]{6}$/.test(v)
const fromHtml = (h: string) => new DOMParser().parseFromString(h.replace(/<br\s*\/?>/gi, ''), 'text/html').body.textContent || ''
const when = (s: string) => { const d = new Date(s); if (isNaN(+d)) return s; const p = (n: number) => String(n).padStart(2, '0'); const h = d.getHours() % 12 || 12; return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(h)}:${p(d.getMinutes())}:${p(d.getSeconds())} ${d.getHours() < 12 ? 'AM' : 'PM'}` }

type Form = { title: string; cost: string; prompt: string; background_color: string; ms_on: boolean; ms: string; mu_on: boolean; mu: string; cd_on: boolean; cd: string; input: boolean; skip: boolean; paused: boolean }
const blank: Form = { title: '', cost: '100', prompt: '', background_color: '#00E5CB', ms_on: false, ms: '', mu_on: false, mu: '', cd_on: false, cd: '', input: false, skip: false, paused: false }
const formBad = (f: Form) => !f.title.trim() ? 'Add a title.' : f.title.trim().length > 45 ? 'Title must be 45 characters or fewer.' : !intOk(f.cost, 1, 2147483647) ? 'Cost must be a whole number of at least 1.'
  : f.prompt.length > 200 ? 'Description must be 200 characters or fewer.' : !hexOk(f.background_color) ? 'Background colour must be a hex colour like #00E5CB.'
  : f.ms_on && !intOk(f.ms, 1, 2147483647) ? 'Max per stream must be at least 1.' : f.mu_on && !intOk(f.mu, 1, 2147483647) ? 'Max per user must be at least 1.' : f.cd_on && !intOk(f.cd, 1, 604800) ? 'Global cooldown must be 1 to 604800 seconds.' : ''
const formBody = (f: Form, edit: boolean): Record<string, string> => ({ title: f.title.trim(), cost: f.cost.trim(), prompt: f.prompt, background_color: f.background_color,
  is_max_per_stream_enabled: String(f.ms_on), max_per_stream: f.ms_on ? f.ms.trim() : '0', is_max_per_user_per_stream_enabled: String(f.mu_on), max_per_user_per_stream: f.mu_on ? f.mu.trim() : '0',
  is_global_cooldown_enabled: String(f.cd_on), global_cooldown_seconds: f.cd_on ? f.cd.trim() : '0', is_user_input_required: String(f.input), should_redemptions_skip_request_queue: String(f.skip), ...(edit ? { is_paused: String(f.paused) } : {}) })

function RewardFields({ f, set, edit }: { f: Form; set: (p: Partial<Form>) => void; edit: boolean }) {
  const lim = (on: 'ms_on' | 'mu_on' | 'cd_on', k: 'ms' | 'mu' | 'cd', label: string, ph: string) => <div className="cr-lim">
    <label className="cr-chk"><input type="checkbox" checked={f[on]} onChange={(e) => set({ [on]: e.target.checked })} /> <span>{label}</span></label>
    {f[on] && <input className="input" type="number" inputMode="numeric" min={1} placeholder={ph} aria-label={label} value={f[k]} onChange={(e) => set({ [k]: e.target.value })} />}</div>
  return <>
    <div className="cr-two">
      <label className="fld"><span className="fld-label">Title</span><input className="input" maxLength={45} placeholder="e.g. Hydrate!" value={f.title} onChange={(e) => set({ title: e.target.value.replace(/[\r\n]/g, '') })} data-autofocus /></label>
      <label className="fld"><span className="fld-label">Cost</span><input className="input" type="number" inputMode="numeric" min={1} value={f.cost} onChange={(e) => set({ cost: e.target.value })} /></label>
    </div>
    <label className="fld"><span className="fld-label">Description <span className="muted small">Shown when redeeming, max 200 characters.</span></span><textarea className="input" rows={2} maxLength={200} placeholder="Describe the reward..." value={f.prompt} onChange={(e) => set({ prompt: e.target.value })} /><Counter n={f.prompt.length} max={200} /></label>
    <label className="fld cr-color"><span className="fld-label">Background Color</span><span className="cr-colrow"><input type="color" aria-label="Pick background colour" value={hexOk(f.background_color) ? f.background_color : '#00E5CB'} onChange={(e) => set({ background_color: e.target.value.toUpperCase() })} /><input className="input" maxLength={7} value={f.background_color} aria-invalid={!hexOk(f.background_color)} onChange={(e) => set({ background_color: e.target.value })} /></span></label>
    <h4 className="cr-h4">Limits &amp; Cooldowns</h4>
    <div className="cr-lims">{lim('ms_on', 'ms', 'Max Per Stream', 'Max')}{lim('mu_on', 'mu', 'Max Per User', 'Max')}{lim('cd_on', 'cd', 'Global Cooldown', 'Seconds')}</div>
    <h4 className="cr-h4">General Settings</h4>
    <div className="cr-sws">
      <div className="ps-sw"><Switch on={f.skip} label={edit ? 'Skip request queue (auto-fulfill)' : 'Skip Request Queue'} onChange={(v) => set({ skip: v })} /><span>{edit ? 'Skip request queue (auto-fulfill)' : 'Skip Request Queue'}</span></div>
      <div className="ps-sw"><Switch on={f.input} label="Require User Input" onChange={(v) => set({ input: v })} /><span>Require User Input</span></div>
      {edit && <div className="ps-sw"><Switch on={f.paused} label="Pause reward" onChange={(v) => set({ paused: v })} /><span>Pause reward</span></div>}
    </div>
  </>
}

function EditModal({ r, onClose, onSaved }: { r: Reward; onClose: () => void; onSaved: () => void }) {
  const t = r.twitch!
  const [f, setF] = useState<Form>({ title: r.title, cost: String(r.cost), prompt: t.prompt, background_color: t.background_color || '#00E5CB', ms_on: t.max_per_stream != null, ms: String(t.max_per_stream ?? ''), mu_on: t.max_per_user_per_stream != null, mu: String(t.max_per_user_per_stream ?? ''), cd_on: t.global_cooldown_seconds != null, cd: String(t.global_cooldown_seconds ?? ''), input: t.is_user_input_required, skip: t.should_redemptions_skip_request_queue, paused: t.is_paused })
  const [busy, setBusy] = useState(false), [err, setErr] = useState('')
  const bad = formBad(f)
  async function save(e: React.FormEvent) {
    e.preventDefault(); if (bad) return; setBusy(true); setErr('')
    try { const x = await send<Res>(A + 'edit.php', { reward_id: r.id, ...formBody(f, true) }); if (!x.success) throw new Error(x.error || 'Unknown error occurred'); onSaved() } catch (x) { setErr((x as Error).message) }
    setBusy(false)
  }
  return <Modal title="Edit Reward on Twitch" icon="fas fa-cog" onClose={onClose} wide>
    <form className="cr-form" onSubmit={save}>
      <RewardFields f={f} set={(p) => setF({ ...f, ...p })} edit />
      {(err || bad) && <p className="mp-bad small" role="alert">{err || bad}</p>}
      <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button type="submit" className="btn" disabled={busy || !!bad}><i className={busy ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> {busy ? 'Saving...' : 'Save Changes'}</button></div>
    </form>
  </Modal>
}

export default function ChannelRewards() {
  const [page, setPage] = useState<Page | null>(null), [err, setErr] = useState(''), [tab, setTab] = useState<'rewards' | 'red' | 'create'>('rewards')
  const [toast, setToast] = useState<Toast>(null), [busy, setBusy] = useState(''), [sync, setSync] = useState<string | null>(null)
  const [editMsg, setEditMsg] = useState<{ id: string; text: string } | null>(null), [editR, setEditR] = useState<Reward | null>(null)
  const [del, setDel] = useState<Reward | null>(null), [conv, setConv] = useState<Reward | null>(null), [manual, setManual] = useState<Reward | null>(null)
  const [reds, setReds] = useState<Reds | null>(null), [redErr, setRedErr] = useState(''), [cf, setCf] = useState<Form>(blank)
  const load = () => get<Page>(A + 'rewards.php', 30000).then((r) => { if (!r.success) throw new Error(); setPage(r) })
  const loadReds = () => { setReds(null); setRedErr(''); get<Reds>(A + 'redemptions.php', 30000).then((r) => { if (!r.success) throw new Error(r.error); setReds(r) }).catch((x) => setRedErr((x as Error).message || 'Failed to load redemptions.')) }
  useEffect(() => { load().catch(() => setErr('The Channel Rewards page could not load. Refresh to try again.')) }, [])
  useEffect(() => { if (tab === 'red' && !reds && !redErr) loadReds() }, [tab]) // eslint-disable-line react-hooks/exhaustive-deps
  async function act(k: string, url: string, body: Record<string, string>, ok: string): Promise<Res | null> {
    setBusy(k)
    try { const r = await send<Res>(A + url, body); if (!r.success) { if (r.error === 'manual_delete_required') return r; throw new Error(r.error || 'Unknown error occurred') } setToast({ kind: 'ok', text: r.message && !/^Reward managed/.test(r.message) ? r.message : ok }); await load(); return r }
    catch (x) { setToast({ kind: 'err', text: (x as Error).message }); return null } finally { setBusy('') }
  }
  async function doSync() {
    setBusy('sync'); setSync('Syncing rewards, please wait...')
    try { const r = await send<Res>(A + 'rewards.php', { syncRewards: '1' }); if (r.error) throw new Error(r.error); setSync(fromHtml(r.output || '')); await load() }
    catch (x) { setSync(null); setToast({ kind: 'err', text: (x as Error).message || 'Network error occurred.' }) } finally { setBusy('') }
  }
  async function convert(r: Reward) {
    setConv(null)
    const x = await act('conv', 'manage.php', { reward_id: r.id }, 'Reward successfully converted to Specter-managed!')
    if (x?.error === 'manual_delete_required') setManual(r)
  }
  async function create(e: React.FormEvent) {
    e.preventDefault(); if (formBad(cf)) return
    const r = await act('create', 'create.php', formBody(cf, false), 'Reward created successfully!')
    if (r) { setCf(blank); setTab('rewards') }
  }
  if (err) return <div className="page cmds mp cr"><div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div></div>
  if (!page) return <div className="page cmds mp cr"><section className="card"><p className="muted"><i className="fas fa-spinner fa-spin" /> Loading</p></section></div>
  const ncp = page.no_channel_points, cbad = formBad(cf)
  return (
    <div className="page cmds mp cr">
      <div className="pg-head"><h2><i className="fas fa-gift" /> Channel Point Rewards</h2></div>
      {sync !== null && <section className="card cr-sync" aria-live="polite"><div className="card-title-row"><h3 className="card-h"><i className="fas fa-terminal" /> Sync Result:</h3>{busy !== 'sync' && <button type="button" className="combo-x" aria-label="Close sync result" onClick={() => setSync(null)}><i className="fa-solid fa-xmark" /></button>}</div><pre>{busy === 'sync' && <i className="fas fa-spinner fa-spin" />} {sync}</pre></section>}
      {ncp && <div className="notice notice-warn"><i className="fas fa-circle-exclamation" /> <span>{page.is_owner ? 'You are not eligible for Twitch Channel Points (only Affiliates and Partners can use channel points).' : `${page.channel} is not eligible for Twitch Channel Points (only Affiliates and Partners can use channel points).`}</span></div>}
      {page.sync_message && <div className="notice notice-warn"><i className="fas fa-triangle-exclamation" /> <span>{page.sync_message}</span></div>}
      <div className="notice notice-info cr-info"><i className="fas fa-rotate" />
        <div><b>Syncing your Twitch channel point rewards:</b>
          <p>Sync imports your current Twitch channel point rewards into Specter. It can take up to 30 seconds.</p>
          <p><b>Important:</b> Rewards you delete on Twitch are not removed here by a sync. Delete them here too to keep everything up to date.</p>
          <button type="button" className="btn" disabled={ncp || busy === 'sync'} title={ncp ? 'Channel Points unavailable - sync disabled' : undefined} onClick={doSync}><i className={busy === 'sync' ? 'fas fa-spinner fa-spin' : 'fas fa-rotate'} /> Sync Rewards</button></div></div>
      <div className="notice notice-warn"><i className="fas fa-triangle-exclamation" />
        <div><b>Important Change: Fortune, Lotto, and TTS Variables</b><p><b>(fortune)</b>, <b>(lotto)</b> and <b>(tts)</b> are now text variables you can use anywhere in a custom message instead of reward name triggers, so any reward name works.</p></div></div>
      <div className="notice notice-info cr-info"><i className="fas fa-code" />
        <div><b>Custom Variables for Custom Messages</b><p>Reward message variables are documented on the support page.</p><a className="btn" href="https://support.botofthespecter.com/#variables" target="_blank" rel="noopener noreferrer"><i className="fas fa-code" /> View variables</a></div></div>
      <div className="seg mp-tabs" role="tablist" aria-label="Channel rewards">
        <button role="tab" aria-selected={tab === 'rewards'} className={tab === 'rewards' ? 'on' : ''} onClick={() => setTab('rewards')}><i className="fas fa-list" /> View/Edit Channel Point Rewards</button>
        <button role="tab" aria-selected={tab === 'red'} className={tab === 'red' ? 'on' : ''} onClick={() => setTab('red')}><i className="fas fa-history" /> View Recent Redemptions</button>
        <button role="tab" aria-selected={tab === 'create'} className={tab === 'create' ? 'on' : ''} onClick={() => setTab('create')}><i className="fas fa-plus" /> Create Managed Reward</button>
      </div>
      {tab === 'rewards' && <section className="card" aria-label="Rewards">
        <div className="bp-table cr-table"><table>
          <thead><tr><th>Image</th><th>Reward Name</th><th>Custom Message</th><th>Reward Cost</th><th>Manage</th><th>Editing</th><th>Deleting</th></tr></thead>
          <tbody>{ncp ? <tr><td colSpan={7} className="muted bp-empty">Channel Points are not available for this channel.</td></tr>
            : page.rewards.length === 0 ? <tr><td colSpan={7} className="muted bp-empty">No channel point rewards found.</td></tr>
            : page.rewards.map((r) => { const sp = r.synced && r.managed_by === 'specter', ed = editMsg?.id === r.id
              return <tr key={r.id} className={r.synced ? '' : 'cr-unsynced'}>
                <td data-l="Image">{r.image ? <img className="cr-img" src={r.image} alt="Reward Icon" width={56} height={56} /> : <i className="fas fa-image fa-2x muted" aria-label="No image" />}</td>
                <td data-l="Reward Name" className="cr-name">{r.title}</td>
                <td data-l="Custom Message" className="cr-msg">{!r.synced ? <i className="muted">Not Synced</i> : ed ? <><textarea className="input" rows={3} maxLength={500} aria-label={`Custom message for ${r.title}`} value={editMsg.text} onChange={(e) => setEditMsg({ id: r.id, text: e.target.value.replace(/[\r\n]/g, ' ') })} data-autofocus /><Counter n={editMsg.text.length} max={500} /></> : r.custom_message || <span className="muted">-</span>}</td>
                <td data-l="Reward Cost" className="bp-pts">{r.cost.toLocaleString()}</td>
                <td data-l="Manage">{sp ? <span className="pill pill-ok" title="Managed by Specter"><i className="fas fa-check-circle" /> Managed</span>
                  : r.synced ? <button type="button" className="btn btn-ghost cr-warn" title="Convert to Specter-managed reward" aria-label={`Convert ${r.title} to a Specter-managed reward`} onClick={() => setConv(r)}><i className="fas fa-cog" /></button>
                  : <button type="button" className="btn btn-ghost" disabled title="Sync to enable managing" aria-label="Sync to enable managing"><i className="fas fa-times" /></button>}</td>
                <td data-l="Editing"><div className="bp-acts">{!r.synced ? <button type="button" className="btn btn-ghost" disabled title="Sync to enable editing" aria-label="Sync to enable editing"><i className="fas fa-pencil-alt" /></button>
                  : ed ? <><button type="button" className="btn btn-ok" aria-label="Save message" disabled={busy === 'msg' || editMsg.text.length > 500} onClick={() => { setEditMsg(null); act('msg', 'rewards.php', { rewardid: r.id, newCustomMessage: editMsg.text }, 'Custom message saved.') }}><i className={busy === 'msg' ? 'fas fa-spinner fa-spin' : 'fas fa-check'} /></button><button type="button" className="btn btn-danger" aria-label="Cancel" onClick={() => setEditMsg(null)}><i className="fas fa-times" /></button></>
                  : <><button type="button" className="btn btn-ghost" aria-label={`Edit custom message for ${r.title}`} onClick={() => setEditMsg({ id: r.id, text: r.custom_message })}><i className="fas fa-pencil-alt" /></button>
                    {sp && <button type="button" className="btn btn-ghost" title="Edit reward on Twitch" aria-label={`Edit ${r.title} on Twitch`} onClick={() => r.twitch ? setEditR(r) : setToast({ kind: 'err', text: 'Could not find reward data. Try syncing first.' })}><i className="fas fa-cog" /></button>}</>}</div></td>
                <td data-l="Deleting">{r.synced ? <button type="button" className="btn btn-danger" aria-label={`Delete ${r.title}`} onClick={() => setDel(r)}><i className="fas fa-trash-alt" /></button> : <span className="muted">-</span>}</td>
              </tr> })}</tbody></table></div>
      </section>}
      {tab === 'red' && <section className="card" aria-label="Recent Redemptions">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-history" /> Recent Redemptions</h3><button type="button" className="btn btn-ghost" disabled={!reds && !redErr} onClick={loadReds}><i className="fas fa-rotate" /> Refresh</button></div>
        <p className="muted small">The most recent channel point redemptions (last 50). Only rewards managed by Specter have redemption history. Use Manage on the Rewards tab to convert one.</p>
        {reds?.partial && <p className="mp-bad small">Some redemptions could not be loaded from Twitch.</p>}
        <div className="bp-table cr-table"><table>
          <thead><tr><th>User</th><th>Reward</th><th>Input</th><th>Cost</th><th>Status</th><th>Actions</th><th>Redeemed At</th></tr></thead>
          <tbody aria-busy={!reds && !redErr}>{redErr ? <tr><td colSpan={7} className="mp-bad bp-empty">{redErr}</td></tr>
            : !reds ? Array.from({ length: 3 }, (_, i) => <tr key={i} aria-hidden="true">{[50, 60, 70, 30, 40, 50, 60].map((w, j) => <td key={j}><span className="bp-skel" style={{ width: `${w}%` }} /></td>)}</tr>)
            : reds.data.length === 0 ? <tr><td colSpan={7} className="muted bp-empty">No recent redemptions found.</td></tr>
            : reds.data.map((d) => <tr key={d.id}>
              <td data-l="User" className="bp-user">{d.user_name}</td><td data-l="Reward">{d.reward.title}</td>
              <td data-l="Input" className="cr-msg">{d.user_input || <i className="muted">No input</i>}</td><td data-l="Cost" className="bp-pts">{d.reward.cost.toLocaleString()}</td>
              <td data-l="Status"><span className={`pill ${d.status === 'FULFILLED' ? 'pill-ok' : d.status === 'CANCELED' ? 'pill-live' : 'pill-warn'}`}>{d.status}</span></td>
              <td data-l="Actions">{d.status === 'UNFULFILLED' ? <div className="bp-acts">
                <button type="button" className="btn btn-ok" title="Approve Redemption" aria-label={`Approve ${d.user_name}'s redemption`} disabled={busy === 'r' + d.id} onClick={async () => { if (await act('r' + d.id, 'redemption.php', { redemption_id: d.id, reward_id: d.reward.id, status: 'FULFILLED' }, 'Redemption approved.')) loadReds() }}><i className="fas fa-check" /></button>
                <button type="button" className="btn btn-danger" title="Reject (Refund)" aria-label={`Reject and refund ${d.user_name}'s redemption`} disabled={busy === 'r' + d.id} onClick={async () => { if (await act('r' + d.id, 'redemption.php', { redemption_id: d.id, reward_id: d.reward.id, status: 'CANCELED' }, 'Redemption refunded.')) loadReds() }}><i className="fas fa-times" /></button></div> : <span className="muted">-</span>}</td>
              <td data-l="Redeemed At" className="small">{when(d.redeemed_at)}</td></tr>)}</tbody></table></div>
      </section>}
      {tab === 'create' && <section className="card" aria-label="Create Reward">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-plus" /> Create Reward</h3></div>
        <form className="cr-form" onSubmit={create}>
          <RewardFields f={cf} set={(p) => setCf({ ...cf, ...p })} edit={false} />
          <h4 className="cr-h4">Images</h4>
          <p className="muted small"><b>Note:</b> Twitch does not allow uploading images when a reward is created by API. Create the reward first, then upload icons on your <a href="https://dashboard.twitch.tv/viewer-rewards/channel-points/rewards" target="_blank" rel="noreferrer">Twitch Dashboard</a>.</p>
          {cbad && cf !== blank && <p className="mp-bad small" role="alert">{cbad}</p>}
          <div><button type="submit" className="btn" disabled={ncp || !!cbad || busy === 'create'} title={ncp ? 'Channel Points unavailable - creation disabled' : undefined}><i className={busy === 'create' ? 'fas fa-spinner fa-spin' : 'fas fa-plus'} /> {busy === 'create' ? 'Creating...' : 'Create New Reward'}</button></div>
        </form>
      </section>}
      {editR && <EditModal r={editR} onClose={() => setEditR(null)} onSaved={() => { setEditR(null); setToast({ kind: 'ok', text: 'Reward updated on Twitch.' }); load() }} />}
      {del && <Modal title="Delete reward" icon="fas fa-trash" onClose={() => setDel(null)}>
        <p>{del.managed_by === 'specter' ? 'This will remove the reward from both Twitch and the Specter system. Continue?' : 'This will only remove the reward from Specter\'s sync system. The reward stays on Twitch. To re-add it, sync again. Continue?'}</p><p><b>{del.title}</b></p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setDel(null)}>Cancel</button><button type="button" className="btn btn-danger" disabled={busy === 'del'} onClick={() => { setDel(null); act('del', 'rewards.php', { deleteRewardId: del.id }, 'Deleted') }}><i className="fas fa-trash" /> Yes, delete</button></div>
      </Modal>}
      {conv && <Modal title="Manage This Reward?" icon="fas fa-cog" onClose={() => setConv(null)}>
        <p>This reward will be removed from Twitch and recreated so Specter can manage its redemptions. All settings are kept.</p><p><b>{conv.title}</b></p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setConv(null)}>Cancel</button><button type="button" className="btn" disabled={busy === 'conv'} onClick={() => convert(conv)}><i className={busy === 'conv' ? 'fas fa-spinner fa-spin' : 'fas fa-cog'} /> Continue</button></div>
      </Modal>}
      {manual && <Modal title="Manual Action Required" icon="fas fa-triangle-exclamation" onClose={() => setManual(null)}>
        <p>Please remove <b>{manual.title}</b> from Twitch first, then confirm here and Specter will recreate it.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={() => setManual(null)}>Cancel</button><button type="button" className="btn" disabled={busy === 'conv'} onClick={() => { setManual(null); act('conv', 'manage.php', { action: 'complete_manual', reward_id: manual.id }, 'Reward successfully converted to Specter-managed!') }}><i className="fas fa-check" /> I've removed from Twitch</button></div>
      </Modal>}
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}
