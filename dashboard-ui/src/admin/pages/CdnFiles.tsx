import { useEffect, useRef, useState } from 'react'
import Modal from '../../components/Modal'
import { LoadErr, Loading, ToastBar, useLoad, type Toast } from '../ui'

// Port of dashboard/admin/cdn_files.php: store select, breadcrumb, Upload / New Folder (super admin),
// listing with folders (per-user root shows the matching user or a No account badge) and files
// with Copy URL, Rename and Delete (typed DELETE confirm, as before).
type Data = { is_super: boolean; stores: { key: string; name: string }[]; allowed_exts: string[]; L: Record<string, string> }
type F = { key: string; basename: string; size: string; last_modified: string | number; served_url: string }
type List = { ok: boolean; error?: string; folders: string[]; files: F[]; per_user_root: boolean; folder_users: Record<string, { display_name: string; avatar: string } | null> }
type Dlg = { kind: 'delete'; key: string; folder: boolean; name: string } | { kind: 'rename'; key: string; name: string } | { kind: 'mkdir' } | { kind: 'overwrite'; file: File } | null
const API = '/api/cdn_files.php'
async function call(data: Record<string, string>, file?: File) {
  const fd = new FormData(); Object.entries(data).forEach(([k, v]) => fd.append(k, v)); if (file) fd.append('file', file)
  const r = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' }); return r.json()
}
const when = (t: string | number) => { const d = new Date(typeof t === 'number' ? t * 1000 : t); return isNaN(+d) ? String(t) : d.toLocaleString() }

export default function CdnFiles() {
  const { data, err, reload } = useLoad<Data>(API)
  const [store, setStore] = useState(''), [folder, setFolder] = useState(''), [list, setList] = useState<List | null>(null), [lErr, setLErr] = useState('')
  const [toast, setToast] = useState<Toast | null>(null), [dlg, setDlg] = useState<Dlg>(null), [val, setVal] = useState(''), [busy, setBusy] = useState(false)
  const fileIn = useRef<HTMLInputElement>(null), queue = useRef<File[]>([])
  const cur = store || data?.stores[0]?.key || ''
  const load = async () => { if (!cur) return; setList(null); setLErr(''); try { const r: List = await call({ action: 'list', store: cur, folder }); if (!r.ok) throw new Error(r.error); setList(r) } catch (e) { setLErr((e as Error).message || 'Error') } }
  useEffect(() => { load() }, [cur, folder]) // eslint-disable-line react-hooks/exhaustive-deps
  if (err) return <LoadErr err={err} retry={reload} />
  if (!data) return <Loading />
  const L = data.L, l = (k: string) => L[k] ?? k, sup = data.is_super
  const go = (f: string) => setFolder(f)
  const crumbs = folder ? folder.split('/') : []
  const upload = async (file: File, overwrite = false) => {
    setBusy(true)
    try {
      const r = await call({ action: 'upload', store: cur, folder, ...(overwrite ? { overwrite: '1' } : {}) }, file)
      if (r.exists && !overwrite) { setDlg({ kind: 'overwrite', file }); return false }
      setToast(r.ok ? { kind: 'ok', text: `${file.name}: ${l('upload_done')}` } : { kind: 'err', text: `${file.name}: ${r.error || l('err_upload_failed')}` })
    } catch { setToast({ kind: 'err', text: l('err_upload_failed') }) } finally { setBusy(false) }
    return true
  }
  const runQueue = async () => { while (queue.current.length) { const f = queue.current.shift()!; if (!(await upload(f))) return } await load() }
  const close = () => { setDlg(null); setVal('') }
  const confirm = async () => {
    if (!dlg) return
    if (dlg.kind === 'overwrite') { const f = dlg.file; close(); await upload(f, true); await runQueue(); return }
    if (dlg.kind !== 'delete' && !val.trim()) { setToast({ kind: 'err', text: l('err_name_empty') }); return }
    setBusy(true)
    try {
      const r = dlg.kind === 'delete' ? await call({ action: 'delete', store: cur, key: dlg.key, is_folder: dlg.folder ? '1' : '0' })
        : dlg.kind === 'rename' ? await call({ action: 'rename', store: cur, old_key: dlg.key, new_name: val.trim() })
        : await call({ action: 'mkdir', store: cur, folder, name: val.trim() })
      if (r.ok) { close(); await load() } else setToast({ kind: 'err', text: r.error || l(dlg.kind === 'delete' ? 'err_delete_failed' : dlg.kind === 'rename' ? 'err_rename_failed' : 'err_mkdir_failed') })
    } catch { setToast({ kind: 'err', text: l('err_missing_param') }) } finally { setBusy(false) }
  }
  const copy = async (u: string) => { try { await navigator.clipboard.writeText(u); setToast({ kind: 'ok', text: l('url_copied') }) } catch { setToast({ kind: 'err', text: u }) } }

  return (
    <div className="cmds adm">
      <section className="card">
        <h2 className="card-h"><i className="fas fa-folder-open" /> {l('page_title')}</h2>
        {!sup && <div className="notice notice-info adm-gap"><i className="fas fa-eye" /> {l('readonly_notice')}</div>}
        {data.stores.length === 0 ? <div className="notice notice-warn"><i className="fas fa-exclamation-triangle" /> {l('err_no_stores')}</div> : <>
          <div className="adm-inline adm-gap">
            <label className="adm-inline"><span className="fld-label">{l('store_label')}</span>
              <select className="input adm-sel" value={cur} onChange={(e) => { setStore(e.target.value); setFolder('') }}>{data.stores.map((s) => <option key={s.key} value={s.key}>{s.name}</option>)}</select></label>
            <nav className="adm-crumbs" aria-label="breadcrumb">
              <button type="button" className="btn btn-ghost" disabled={!folder} onClick={() => go('')}><i className="fas fa-home" /> {l('root_label')}</button>
              {crumbs.map((c, i) => <span key={i}><i className="fas fa-chevron-right muted" /> <button type="button" className="btn btn-ghost" disabled={i === crumbs.length - 1} onClick={() => go(crumbs.slice(0, i + 1).join('/'))}>{c}</button></span>)}
            </nav>
            {sup && <span className="adm-inline adm-push">
              <button type="button" className="btn" disabled={busy} onClick={() => fileIn.current?.click()}><i className="fas fa-upload" /> {l('upload_btn')}</button>
              <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => setDlg({ kind: 'mkdir' })}><i className="fas fa-folder-plus" /> {l('mkdir_btn')}</button>
              <input ref={fileIn} type="file" multiple hidden accept={data.allowed_exts.map((x) => '.' + x).join(',')} onChange={(e) => { queue.current = Array.from(e.target.files || []); e.target.value = ''; runQueue() }} />
            </span>}
          </div>
          {lErr && <div className="notice notice-error adm-gap">{lErr} <button type="button" className="btn btn-ghost" onClick={load}>Retry</button></div>}
          <div className="bp-table adm-left"><table>
            <thead><tr><th>{l('th_name')}</th><th>{l('th_size')}</th><th>{l('th_modified')}</th><th>{l('th_actions')}</th></tr></thead>
            <tbody>{!list ? (lErr ? null : <tr><td colSpan={4}><span className="bp-skel" /> {l('loading')}</td></tr>)
              : list.folders.length + list.files.length === 0 ? <tr><td colSpan={4} className="muted">{l('empty')}</td></tr> : <>
              {list.folders.map((seg) => { const path = folder ? `${folder}/${seg}` : seg, u = list.per_user_root ? list.folder_users[seg] : undefined; return (
                <tr key={'d' + seg}><td><div className="adm-user">
                  <button type="button" className="btn btn-ghost adm-folder" onClick={() => go(path)}><i className="fas fa-folder adm-amber" /> {u?.avatar && <img src={u.avatar} alt="" width={24} height={24} loading="lazy" />} {seg}</button>
                  {u && <><span className="muted">{u.display_name}</span><a className="btn btn-ghost adm-ib" href={`/users?search=${encodeURIComponent(seg)}`} title={l('view_user')} aria-label={`${l('view_user')} ${seg}`}><i className="fas fa-external-link-alt" /></a></>}
                  {u === null && <span className="pill pill-warn" title={l('orphaned_badge')}><i className="fas fa-exclamation-triangle" /> {l('orphaned_badge')}</span>}
                </div></td><td><span className="pill">{l('folder_label')}</span></td><td>-</td>
                  <td>{sup && <button type="button" className="btn btn-danger adm-ib" title={l('delete_btn')} aria-label={`${l('delete_btn')} ${seg}`} onClick={() => setDlg({ kind: 'delete', key: `${cur}/${path}/`, folder: true, name: seg })}><i className="fas fa-trash" /></button>}</td></tr>) })}
              {list.files.map((f) => (
                <tr key={f.key}><td><i className="fas fa-file muted" /> {f.basename}</td><td>{f.size}</td><td>{when(f.last_modified)}</td>
                  <td><div className="adm-inline">
                    <button type="button" className="btn btn-ghost adm-ib" title={l('copy_url_btn')} aria-label={`${l('copy_url_btn')} ${f.basename}`} onClick={() => copy(f.served_url)}><i className="fas fa-link" /></button>
                    {sup && <><button type="button" className="btn btn-ghost adm-ib" title={l('rename_btn')} aria-label={`${l('rename_btn')} ${f.basename}`} onClick={() => { setVal(f.basename); setDlg({ kind: 'rename', key: f.key, name: f.basename }) }}><i className="fas fa-pen" /></button>
                      <button type="button" className="btn btn-danger adm-ib" title={l('delete_btn')} aria-label={`${l('delete_btn')} ${f.basename}`} onClick={() => setDlg({ kind: 'delete', key: f.key, folder: false, name: f.basename })}><i className="fas fa-trash" /></button></>}
                  </div></td></tr>))}</>}</tbody>
          </table></div></>}
      </section>
      {dlg && <Modal title={dlg.kind === 'delete' ? `${l('delete_btn')}: ${dlg.name}` : dlg.kind === 'rename' ? l('rename_prompt') : dlg.kind === 'mkdir' ? l('mkdir_prompt') : l('overwrite_btn')} icon={dlg.kind === 'delete' ? 'fas fa-trash' : 'fas fa-pen'} onClose={close}>
        {dlg.kind === 'delete' && <><p>{l(dlg.folder ? 'confirm_delete_folder' : 'confirm_delete_file')}</p>
          <label className="fld"><span className="fld-label">{l('type_to_confirm')}: <strong>{l('type_delete_keyword')}</strong></span><input className="input" value={val} autoFocus onChange={(e) => setVal(e.target.value)} /></label></>}
        {(dlg.kind === 'rename' || dlg.kind === 'mkdir') && <label className="fld"><span className="fld-label">{dlg.kind === 'rename' ? l('rename_prompt') : l('mkdir_prompt')}</span><input className="input" value={val} autoFocus onChange={(e) => setVal(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') confirm() }} /></label>}
        {dlg.kind === 'overwrite' && <p><strong>{dlg.file.name}</strong>: {l('confirm_overwrite')}</p>}
        <div className="modal-acts">
          <button type="button" className="btn btn-ghost" onClick={() => { queue.current = []; close() }}>{l('cancel_btn')}</button>
          <button type="button" className={`btn ${dlg.kind === 'delete' || dlg.kind === 'overwrite' ? 'btn-danger' : ''}`} disabled={busy || (dlg.kind === 'delete' && val !== l('type_delete_keyword'))} onClick={confirm}>{dlg.kind === 'overwrite' ? l('overwrite_btn') : dlg.kind === 'delete' ? l('delete_btn') : l('confirm_btn')}</button>
        </div>
      </Modal>}
      {toast && <ToastBar t={toast} onClose={() => setToast(null)} />}
    </div>
  )
}
