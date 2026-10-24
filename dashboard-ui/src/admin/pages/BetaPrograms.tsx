import { useState } from 'react'
import Modal from '../../components/Modal'
import { Confirm, LoadErr, Loading, post, ToastBar, useLoad, type Ask, type Toast } from '../ui'

// Port of dashboard/admin/beta_programs.php: one card (title, New Program, intro, table)
// plus the Create / Edit modal. Endpoints answer { success, msg }.
type Prog = { id: number; slug: string; name: string; description: string | null; is_active: number; created: string }
type R = { success: boolean; msg?: string; is_active?: number }
const API = '/api/beta_programs.php'

export default function BetaPrograms() {
  const { data, err, reload, setData } = useLoad<{ programs: Prog[] }>(API)
  const [form, setForm] = useState<{ id: number; slug: string; name: string; desc: string } | null>(null)
  const [saving, setSaving] = useState(false), [busy, setBusy] = useState(0)
  const [toast, setToast] = useState<Toast>(null), [ask, setAsk] = useState<Ask>(null)
  const fail = (x: unknown) => setToast({ kind: 'err', text: (x as Error).message })

  const save = async (e: React.FormEvent) => {
    e.preventDefault(); if (!form) return; setSaving(true)
    try {
      const body: Record<string, string | number> = { save_program: 1, edit_id: form.id, name: form.name.trim(), description: form.desc.trim() }
      if (form.id === 0) body.slug = form.slug.trim()
      const r = await post<R>(API, body)
      if (!r.success) return setToast({ kind: 'err', text: r.msg || 'Something went wrong.' })
      setForm(null); setToast({ kind: 'ok', text: r.msg || 'Saved' }); await reload()
    } catch (x) { fail(x) } finally { setSaving(false) }
  }
  const toggle = async (p: Prog) => {
    setBusy(p.id)
    try {
      const r = await post<R>(API, { toggle_program: 1, program_id: p.id })
      if (!r.success) return setToast({ kind: 'err', text: r.msg || 'Toggle failed.' })
      setData((d) => ({ programs: d.programs.map((x) => (x.id === p.id ? { ...x, is_active: r.is_active ?? 0 } : x)) }))
    } catch (x) { fail(x) } finally { setBusy(0) }
  }
  const del = (p: Prog) => setAsk({
    title: 'Delete Program', text: <p>Delete <strong>{p.name}</strong>? This cannot be undone.</p>, ok: 'Yes, delete', danger: true,
    run: async () => {
      try {
        const r = await post<R>(API, { delete_program: 1, program_id: p.id })
        if (!r.success) return setToast({ kind: 'err', text: `Cannot Delete. ${r.msg || 'Delete failed.'}` })
        setToast({ kind: 'ok', text: r.msg || 'Deleted' }); await reload()
      } catch (x) { fail(x) }
    },
  })

  return (
    <div className="cmds adm">
      <ToastBar t={toast} onClose={() => setToast(null)} />
      <Confirm ask={ask} onClose={() => setAsk(null)} />
      {form && (
        <Modal title={form.id ? 'Edit Beta Program' : 'Create Beta Program'} icon="fa-solid fa-flask" onClose={() => setForm(null)}>
          <form className="adm-form" onSubmit={save}>
            {form.id === 0 && <label className="fld"><span className="fld-label">Slug <small className="muted">(lowercase, letters, numbers, hyphens, underscores)</small></span>
              <input className="input" data-autofocus placeholder="e.g. streaming" pattern="[a-z0-9_\-]+" minLength={2} maxLength={50} value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })} /></label>}
            <label className="fld"><span className="fld-label">Name</span>
              <input className="input" required maxLength={100} placeholder="e.g. Streaming Beta" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
            <label className="fld"><span className="fld-label">Description</span>
              <textarea className="input" rows={3} placeholder="Optional description shown to users on the support portal" value={form.desc} onChange={(e) => setForm({ ...form, desc: e.target.value })} /></label>
            <div className="modal-acts">
              <button type="button" className="btn btn-ghost" onClick={() => setForm(null)}>Cancel</button>
              <button type="submit" className="btn" disabled={saving}>Save</button>
            </div>
          </form>
        </Modal>
      )}
      <section className="card">
        <div className="card-head">
          <h2 className="card-h"><i className="fa-solid fa-flask" /> Beta Programs</h2>
          <button type="button" className="btn" onClick={() => setForm({ id: 0, slug: '', name: '', desc: '' })}><i className="fa-solid fa-plus" /> New Program</button>
        </div>
        <p className="muted adm-gap">Manage beta programs. Each program has a unique slug that gates access via the <code>beta_programs</code> JSON column on the <code>users</code> table. Users can request access through the <a href="https://support.botofthespecter.com/beta.php" target="_blank" rel="noopener">support portal</a>.</p>
        {err && <LoadErr err={err} retry={reload} />}
        {!data && !err && <Loading />}
        {data && (data.programs.length === 0 ? <div className="notice notice-info">No beta programs have been created yet.</div> : (
          <div className="bp-table adm-left"><table>
            <thead><tr><th>Slug</th><th>Name</th><th>Description</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
            <tbody>{data.programs.map((p) => (
              <tr key={p.id}>
                <td><code>{p.slug}</code></td>
                <td>{p.name}</td>
                <td className="adm-desc">{p.description ?? ''}</td>
                <td><span className={`pill ${p.is_active ? 'pill-ok' : 'pill-err'}`}>{p.is_active ? 'Active' : 'Inactive'}</span></td>
                <td>{p.created}</td>
                <td><div className="bp-acts">
                  <button type="button" className="btn btn-ghost" title="Edit" aria-label={`Edit ${p.name}`} onClick={() => setForm({ id: p.id, slug: p.slug, name: p.name, desc: p.description ?? '' })}><i className="fa-solid fa-pen" /></button>
                  <button type="button" className="btn btn-ghost" title="Toggle active" aria-label={`Toggle ${p.name} active`} disabled={busy === p.id} onClick={() => toggle(p)}><i className={`fa-solid ${p.is_active ? 'fa-eye-slash' : 'fa-eye'}`} /></button>
                  <button type="button" className="btn btn-danger" title="Delete" aria-label={`Delete ${p.name}`} onClick={() => del(p)}><i className="fa-solid fa-trash" /></button>
                </div></td>
              </tr>))}</tbody>
          </table></div>
        ))}
      </section>
    </div>
  )
}
