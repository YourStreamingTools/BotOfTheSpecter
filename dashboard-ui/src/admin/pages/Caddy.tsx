import { useEffect, useState } from 'react'
import Modal from '../../components/Modal'
import { LoadErr, Loading, post, useLoad } from '../ui'

// Port of dashboard/admin/caddy.php: status and version, sites, TLS, upstreams, redacted config,
// and (super admins only) the admin API console, config load, reload and restart, each behind a
// typed CONFIRM / RESTART prompt as before. Nothing is called on load except the version check.
type Site = { hosts: { h: string; kind: string }[]; type: string; listen: string[]; target: string }
type Data = { is_super: boolean; ssh_ready: boolean; up: boolean; sites: Site[]; tls: { acme_email: string; dns_provider: string; policies: number }; upstreams: unknown[]; config: string; L: Record<string, string> }
const API = '/api/caddy.php'

export default function Caddy() {
  const { data, err, reload } = useLoad<Data>(API)
  const [version, setVersion] = useState<string | null>(null), [out, setOut] = useState('-'), [showCfg, setShowCfg] = useState(false)
  const [method, setMethod] = useState('GET'), [path, setPath] = useState('/config/'), [body, setBody] = useState('')
  const [fmt, setFmt] = useState('json'), [cfg, setCfg] = useState(''), [busy, setBusy] = useState(false)
  const [dlg, setDlg] = useState<{ title: string; text: string; word: string; run: () => Promise<void> } | null>(null), [typed, setTyped] = useState('')
  useEffect(() => {
    if (!data?.ssh_ready) return
    post<{ ok: boolean; version?: string; error?: string }>(API, { action: 'caddy_version' }).then((r) => setVersion(r.ok ? r.version || '' : r.error || 'unknown')).catch(() => setVersion('unknown'))
  }, [data?.ssh_ready])
  if (err) return <LoadErr err={err} retry={reload} />
  if (!data) return <Loading />
  const L = data.L, l = (k: string) => L[k] ?? k
  const show = (o: unknown) => setOut(typeof o === 'string' ? o : JSON.stringify(o, null, 2))
  const call = async (d: Record<string, string>) => { setBusy(true); try { show(await post(API, d)) } catch (e) { show((e as Error).message) } finally { setBusy(false) } }
  const confirm = (word: string, run: () => Promise<void>) => { setTyped(''); setDlg({ title: l(word === 'RESTART' ? 'restart_confirm_title' : 'destructive_confirm_title'), text: l(word === 'RESTART' ? 'restart_confirm_text' : 'destructive_confirm_text'), word, run }) }
  const badJson = (s: string) => { if (s.trim() === '') return false; try { JSON.parse(s); return false } catch { show(l('err_invalid_json')); return true } }
  const types: Record<string, string> = { php: l('type_php'), reverse_proxy: l('type_reverse_proxy'), redirect: l('type_redirect'), static: l('type_static'), response: l('type_response'), other: l('type_other') }
  const counts: Record<string, number> = {}; const listen = new Set<string>(); let hostTotal = 0
  data.sites.forEach((s) => { hostTotal += s.hosts.length; counts[s.type] = (counts[s.type] || 0) + 1; s.listen.forEach((x) => listen.add(x)) })
  const summary = l('sites_summary').replace('%1$d', String(hostTotal)).replace('%2$d', String(data.sites.length)).replace('%3$s', listen.size ? [...listen].join(', ') : '-')
  const noSsh = data.ssh_ready ? undefined : l('err_ssh_not_configured')

  return (
    <div className="cmds adm">
      <section className="card">
        <h2 className="card-h"><i className="fas fa-server" /> {l('page_title')}</h2>
        {!data.is_super && <div className="note adm-gap"><i className="fas fa-eye" /> {l('readonly_notice')}</div>}
        <div className="adm-inline"><span className={`pill ${data.up ? 'pill-ok' : 'pill-err'}`}>{data.up ? l('status_running') : l('status_unreachable')}</span>
          <span className="muted">{l('version_label')}: <span aria-busy={data.ssh_ready && version === null}>{!data.ssh_ready ? '-' : version ?? '\u2026'}</span></span></div>
      </section>
      <section className="card">
        <h2 className="card-h">{l('sites_heading')}</h2>
        <p className="muted adm-gap">{l('sites_intro')}</p>
        {data.sites.length > 0 && <><p className="adm-gap"><strong>{summary}</strong></p>
          <div className="adm-inline adm-gap">{Object.entries(counts).map(([c, n]) => <span key={c} className="pill">{n} &times; {types[c] || c}</span>)}</div></>}
        <div className="bp-table adm-left"><table>
          <thead><tr><th>{l('th_hosts')}</th><th>{l('th_type')}</th></tr></thead>
          <tbody>{data.sites.length === 0 ? <tr><td colSpan={2} className="muted">{l('no_data')}</td></tr> : data.sites.map((s, i) => (
            <tr key={i}><td className="adm-wrap">{s.hosts.length === 0 ? <span className="muted">-</span> : s.hosts.map((h, j) => <span key={j}>{j > 0 && ', '}{h.kind === 'fallback' ? <><code>*</code> <span className="muted">({l('host_fallback')})</span></> : h.kind === 'wildcard' ? <>{h.h} <span className="muted">({l('host_all_subdomains')})</span></> : h.h}</span>)}</td>
              <td><span className={`pill ${s.type === 'php' ? 'pill-ok' : ''}`}>{types[s.type] || s.type}</span>{s.target && <span className="muted"> &rarr; {s.target}</span>}</td></tr>))}</tbody>
        </table></div>
      </section>
      <section className="card">
        <h2 className="card-h">{l('tls_heading')}</h2>
        <p>{l('tls_email')}: <strong>{data.tls.acme_email}</strong></p>
        <p>{l('tls_dns_provider')}: <strong>{data.tls.dns_provider}</strong></p>
        <p>{l('tls_policies')}: <strong>{data.tls.policies}</strong></p>
      </section>
      <section className="card">
        <h2 className="card-h">{l('upstreams_heading')}</h2>
        {data.upstreams && (data.upstreams as unknown[]).length ? <pre className="adm-log adm-out">{JSON.stringify(data.upstreams, null, 4)}</pre> : <span className="muted">{l('upstreams_none')}</span>}
      </section>
      <section className="card">
        <div className="card-head"><h2 className="card-h">{l('config_heading')}</h2><button type="button" className="btn btn-ghost" aria-expanded={showCfg} onClick={() => setShowCfg(!showCfg)}>{l('config_toggle')}</button></div>
        {showCfg && <pre className="adm-log adm-out adm-tall">{data.config}</pre>}
      </section>
      {data.is_super && <section className="card">
        <h2 className="card-h"><i className="fas fa-sliders" /> {l('control_heading')}</h2>
        <div className="note note-warn adm-gap"><i className="fas fa-triangle-exclamation" /> {l('control_drift_notice')}</div>
        <h3 className="adm-h3">{l('console_heading')}</h3>
        <div className="adm-inline adm-gap">
          <select className="input adm-sel" value={method} aria-label="Method" onChange={(e) => setMethod(e.target.value)}>{['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => <option key={m}>{m}</option>)}</select>
          <input className="input adm-grow" value={path} aria-label={l('console_path')} onChange={(e) => setPath(e.target.value)} />
          <button type="button" className="btn" disabled={busy} onClick={() => { if (badJson(body)) return; const d = { action: 'api_call', method, path: path.trim(), body }; if (method === 'GET') call(d); else confirm('CONFIRM', () => call(d)) }}>{l('console_send')}</button>
        </div>
        <textarea className="input mono" rows={4} placeholder={l('console_body')} value={body} onChange={(e) => setBody(e.target.value)} />
        <hr className="adm-hr" />
        <h3 className="adm-h3">{l('load_heading')}</h3>
        <div className="adm-inline adm-gap">
          <label className="adm-inline"><span>{l('load_format')}:</span><select className="input adm-sel" value={fmt} onChange={(e) => setFmt(e.target.value)}><option value="json">application/json</option><option value="caddyfile">text/caddyfile</option></select></label>
          <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => call({ action: 'adapt_config', config: cfg, format: fmt })}>{l('load_validate')}</button>
          <button type="button" className="btn btn-danger" disabled={busy} onClick={() => { if (fmt === 'json' && badJson(cfg)) return; confirm('CONFIRM', () => call({ action: 'load_config', config: cfg, format: fmt })) }}>{l('load_apply')}</button>
        </div>
        <textarea className="input mono" rows={6} value={cfg} onChange={(e) => setCfg(e.target.value)} />
        <hr className="adm-hr" />
        <h3 className="adm-h3">{l('reload_heading')}</h3>
        <div className="adm-inline">
          <button type="button" className="btn btn-warn" disabled={busy || !data.ssh_ready} title={noSsh} onClick={() => confirm('CONFIRM', () => call({ action: 'reload_caddy' }))}><i className="fas fa-rotate" /> {l('reload_btn')}</button>
          <button type="button" className="btn btn-danger" disabled={busy || !data.ssh_ready} title={noSsh} onClick={() => confirm('RESTART', () => call({ action: 'restart_caddy' }))}><i className="fas fa-power-off" /> {l('restart_btn')}</button>
        </div>
      </section>}
      <section className="card"><pre className="adm-log adm-out adm-tall" aria-live="polite">{out}</pre></section>
      {dlg && <Modal title={dlg.title} icon="fas fa-triangle-exclamation" onClose={() => setDlg(null)}>
        <p className="adm-gap">{dlg.text}</p>
        <label className="fld"><span className="fld-label"><strong>{dlg.word}</strong></span><input className="input" autoFocus value={typed} onChange={(e) => setTyped(e.target.value)} /></label>
        <div className="modal-acts">
          <button type="button" className="btn btn-ghost" onClick={() => setDlg(null)}>{l('cancel_btn')}</button>
          <button type="button" className="btn btn-danger" disabled={typed.trim() !== dlg.word} onClick={() => { const r = dlg.run; setDlg(null); r() }}>{l('confirm_btn')}</button>
        </div>
      </Modal>}
    </div>
  )
}
