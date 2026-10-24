import { useState } from 'react'
import Modal from '../../components/Modal'
import Pager from '../../components/Pager'
import { Confirm, LoadErr, Loading, post, ToastBar, useLoad, type Ask, type Toast } from '../ui'

// Port of dashboard/admin/spam_patterns.php: header card (note, Check for Duplicates),
// Add New Spam Pattern, Spam Patterns (n) table with inline Edit / Save / Cancel and Delete.
type Pat = { id: number; spam_pattern: string }
type Dup = { pattern: string; count: number | string; ids: string }
const API = '/api/spam_patterns.php', PER = 50

export default function SpamPatterns() {
  const { data, err, reload, setData } = useLoad<{ patterns: Pat[] }>(API)
  const [pattern, setPattern] = useState(''), [adding, setAdding] = useState(false)
  const [edit, setEdit] = useState<{ id: number; v: string } | null>(null), [busy, setBusy] = useState(0)
  const [page, setPage] = useState(1)
  const [dups, setDups] = useState<Dup[] | null>(null), [checking, setChecking] = useState(false)
  const [toast, setToast] = useState<Toast>(null), [ask, setAsk] = useState<Ask>(null)
  const fail = (x: unknown) => setToast({ kind: 'err', text: (x as Error).message })

  const add = async (e: React.FormEvent) => {
    e.preventDefault(); setAdding(true)
    try {
      const r = await post(API, { add_pattern: 1, pattern })
      if (!r.success) return setToast({ kind: 'err', text: r.message || 'An error occurred while adding the pattern' })
      setPattern(''); setToast({ kind: 'ok', text: r.message || 'Spam pattern added successfully' }); await reload()
    } catch (x) { fail(x) } finally { setAdding(false) }
  }
  const save = async () => {
    if (!edit) return
    if (!edit.v.trim()) return setToast({ kind: 'err', text: 'Pattern cannot be empty' })
    setBusy(edit.id)
    try {
      const r = await post(API, { update_pattern: 1, id: edit.id, pattern: edit.v })
      if (!r.success) return setToast({ kind: 'err', text: r.message || 'An error occurred while updating the pattern' })
      const id = edit.id, v = edit.v.trim()
      setData((d) => ({ patterns: d.patterns.map((p) => (p.id === id ? { ...p, spam_pattern: v } : p)) })); setEdit(null)
      setToast({ kind: 'ok', text: r.message || 'Pattern updated successfully' })
    } catch (x) { fail(x) } finally { setBusy(0) }
  }
  const del = (p: Pat) => setAsk({
    title: 'Delete Pattern?', text: <p>Are you sure you want to delete pattern #{p.id}: "{p.spam_pattern}"?</p>, ok: 'Yes, delete it', danger: true,
    run: async () => {
      try {
        const r = await post(API, { delete_pattern: 1, id: p.id })
        if (!r.success) return setToast({ kind: 'err', text: r.message || 'An error occurred while deleting the pattern' })
        setData((d) => ({ patterns: d.patterns.filter((x) => x.id !== p.id) })); setToast({ kind: 'ok', text: r.message || 'Pattern deleted successfully' })
      } catch (x) { fail(x) }
    },
  })
  const check = async () => {
    setChecking(true)
    try {
      const r = await post<{ success: boolean; message: string; duplicates: Dup[] }>(API, { check_duplicates: 1 })
      if (!r.success) return setToast({ kind: 'err', text: r.message || 'An error occurred while checking for duplicates' })
      setDups(r.duplicates)
    } catch (x) { fail(x) } finally { setChecking(false) }
  }
  const cleanup = async () => {
    try {
      const r = await post<{ success: boolean; message: string; kept_count: number; deleted_count: number }>(API, { cleanup_duplicates: 1 })
      setDups(null)
      if (!r.success) return setToast({ kind: 'err', text: r.message || 'An error occurred while cleaning up duplicates' })
      setToast({ kind: 'ok', text: `Cleanup Complete. Kept: ${r.kept_count} unique patterns. Removed: ${r.deleted_count} duplicates.` }); await reload()
    } catch (x) { fail(x) }
  }

  const list = data?.patterns ?? []
  const pages = Math.max(1, Math.ceil(list.length / PER)), pg = Math.min(page, pages)
  const shown = list.slice((pg - 1) * PER, pg * PER)

  return (
    <div className="cmds adm">
      <ToastBar t={toast} onClose={() => setToast(null)} />
      <Confirm ask={ask} onClose={() => setAsk(null)} />
      {dups && (
        <Modal title={dups.length ? `Found ${dups.length} Duplicates` : 'Clean Database'} icon={dups.length ? 'fas fa-copy' : 'fas fa-check'} onClose={() => setDups(null)} wide>
          {dups.length === 0 ? <p>No duplicate patterns found in the database.</p> : <>
            <div className="bp-table"><table>
              <thead><tr><th>Pattern</th><th>Count</th><th>IDs</th></tr></thead>
              <tbody>{dups.map((d) => <tr key={d.ids}><td className="adm-code">{d.pattern}</td><td>{d.count}</td><td className="adm-sub">{d.ids}</td></tr>)}</tbody>
            </table></div>
            <p className="muted">Click "Clean Up Duplicates" to automatically remove duplicates (keeping the oldest entry).</p>
          </>}
          <div className="modal-acts">
            <button type="button" className="btn btn-ghost" onClick={() => setDups(null)}>Close</button>
            {dups.length > 0 && <button type="button" className="btn btn-danger" onClick={cleanup}><i className="fas fa-broom" /> Clean Up Duplicates</button>}
          </div>
        </Modal>
      )}
      <section className="card">
        <h2 className="card-h"><i className="fas fa-ban" /> Spam Pattern Management</h2>
        <p className="muted">Manage spam patterns for chat moderation</p>
        <div className="notice notice-info adm-gap"><span><strong>Note:</strong> These patterns are used to detect and block spam messages in chat. Patterns may contain special Unicode characters to match obfuscated spam.</span></div>
        <div className="row-btns"><button type="button" className="btn btn-ghost" disabled={checking} onClick={check}><i className="fas fa-copy" /> Check for Duplicates</button></div>
      </section>
      <section className="card">
        <h2 className="card-h">Add New Spam Pattern</h2>
        <form className="adm-form" onSubmit={add}>
          <label className="fld"><span className="fld-label">Spam Pattern</span>
            <input className="input" required placeholder="Enter spam pattern to detect" value={pattern} onChange={(e) => setPattern(e.target.value)} />
            <small className="muted">Enter the text pattern that should be flagged as spam. Special characters are preserved.</small></label>
          <div><button type="submit" className="btn" disabled={adding}><i className="fas fa-plus" /> Add Pattern</button></div>
        </form>
      </section>
      <section className="card">
        <h2 className="card-h">Spam Patterns ({list.length})</h2>
        {err && <LoadErr err={err} retry={reload} />}
        {!data && !err && <Loading />}
        {data && (list.length === 0 ? <div className="notice notice-info">No spam patterns found. Add one above.</div> : <>
          <div className="bp-table adm-left"><table>
            <thead><tr><th className="adm-w-id">ID</th><th>Spam Pattern</th><th>Actions</th></tr></thead>
            <tbody>{shown.map((p) => {
              const ed = edit?.id === p.id
              return (
                <tr key={p.id}>
                  <td><strong>#{p.id}</strong></td>
                  <td className="adm-code">{ed ? <input className="input" autoFocus value={edit.v} onChange={(e) => setEdit({ id: p.id, v: e.target.value })} onKeyDown={(e) => { if (e.key === 'Enter') void save(); if (e.key === 'Escape') setEdit(null) }} /> : p.spam_pattern}</td>
                  <td><div className="bp-acts">{ed ? <>
                    <button type="button" className="btn btn-ok" disabled={busy === p.id} onClick={save}><i className="fas fa-check" /> Save</button>
                    <button type="button" className="btn btn-ghost" onClick={() => setEdit(null)}><i className="fas fa-times" /> Cancel</button>
                  </> : <>
                    <button type="button" className="btn btn-ghost" onClick={() => setEdit({ id: p.id, v: p.spam_pattern })}><i className="fas fa-edit" /> Edit</button>
                    <button type="button" className="btn btn-danger" onClick={() => del(p)}><i className="fas fa-trash" /> Delete</button>
                  </>}</div></td>
                </tr>
              )
            })}</tbody>
          </table></div>
          <Pager page={pg} pages={pages} onPage={setPage} label="Spam pattern pages" />
        </>)}
      </section>
    </div>
  )
}
