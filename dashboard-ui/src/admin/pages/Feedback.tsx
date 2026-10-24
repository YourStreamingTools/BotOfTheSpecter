import { useState } from 'react'
import Modal from '../../components/Modal'
import { Confirm, LoadErr, Loading, post, ToastBar, useLoad, type Ask, type Toast } from '../ui'

// Port of dashboard/admin/feedback.php: one card with the feedback table
// (bug rows tinted), View Details modal for bug reports, Delete with confirm.
type Fb = { id: number; display_name: string; message: string; is_bug_report: number; bug_category: string | null; severity: string | null; steps_to_reproduce: string | null; expected_behavior: string | null; actual_behavior: string | null; browser_info: string | null; error_message: string | null; created_at: string }
const API = '/api/feedback.php'
const sev = (s: string | null) => (s === 'critical' ? 'pill-err' : s === 'high' || s === 'medium' ? 'pill-warn' : 'pill-info')

export default function Feedback() {
  const { data, err, reload, setData } = useLoad<{ feedback: Fb[] }>(API)
  const [view, setView] = useState<Fb | null>(null)
  const [toast, setToast] = useState<Toast>(null), [ask, setAsk] = useState<Ask>(null)
  const del = (f: Fb) => setAsk({
    title: 'Delete Feedback', text: <p>Are you sure you want to delete feedback from {f.display_name}?</p>, ok: 'Yes, delete it!', danger: true,
    run: async () => {
      try {
        const r = await post<{ success: boolean; msg?: string }>(API, { delete_feedback_id: f.id })
        if (!r.success) return setToast({ kind: 'err', text: r.msg || 'Could not delete feedback.' })
        setData((d) => ({ feedback: d.feedback.filter((x) => x.id !== f.id) })); setToast({ kind: 'ok', text: 'Feedback has been deleted.' })
      } catch (x) { setToast({ kind: 'err', text: (x as Error).message }) }
    },
  })
  const box = (label: string, v: string | null, mono = false) => <div className="fld"><span className="fld-label">{label}</span><div className={`adm-box${mono ? ' adm-code' : ''}`}>{v || 'N/A'}</div></div>

  return (
    <div className="cmds adm">
      <ToastBar t={toast} onClose={() => setToast(null)} />
      <Confirm ask={ask} onClose={() => setAsk(null)} />
      {view && (
        <Modal title="Bug Report Details" icon="fas fa-bug" onClose={() => setView(null)} wide>
          <div className="adm-form">
            <div className="fld"><span className="fld-label">Category:</span><span><span className="pill pill-info">{view.bug_category || 'N/A'}</span></span></div>
            <div className="fld"><span className="fld-label">Severity:</span><span><span className={`pill ${sev(view.severity)}`}>{view.severity || 'N/A'}</span></span></div>
            {box('Steps to Reproduce:', view.steps_to_reproduce)}
            {box('Expected Behavior:', view.expected_behavior)}
            {box('Actual Behavior:', view.actual_behavior)}
            {view.error_message && box('Error Message:', view.error_message, true)}
            {view.bug_category === 'dashboard' && view.browser_info && box('Browser Info:', view.browser_info)}
          </div>
          <button type="button" className="btn modal-ok" onClick={() => setView(null)}>Close</button>
        </Modal>
      )}
      <section className="card">
        <h2 className="card-h"><i className="fas fa-comments" /> Feedback Management</h2>
        <p className="muted adm-gap">View and manage user feedback submissions</p>
        {err && <LoadErr err={err} retry={reload} />}
        {!data && !err && <Loading />}
        {data && (data.feedback.length === 0 ? <div className="notice notice-info">No feedback submissions found.</div> : (
          <div className="bp-table adm-left"><table>
            <thead><tr><th>ID</th><th>Type</th><th>Display Name</th><th>Message/Summary</th><th>Details</th><th>Submitted At</th><th>Actions</th></tr></thead>
            <tbody>{data.feedback.map((f) => (
              <tr key={f.id} className={f.is_bug_report ? 'adm-bug' : ''}>
                <td>{f.id}</td>
                <td>{f.is_bug_report ? <span className="pill pill-err"><i className="fas fa-bug" /> Bug</span> : <span className="pill pill-info"><i className="fas fa-comment" /> Feedback</span>}</td>
                <td>{f.display_name}</td>
                <td className="adm-desc">{f.message}</td>
                <td>{f.is_bug_report ? <button type="button" className="btn btn-ghost" onClick={() => setView(f)}><i className="fas fa-info-circle" /> View Details</button> : <span className="muted">N/A</span>}</td>
                <td>{f.created_at}</td>
                <td><div className="bp-acts"><button type="button" className="btn btn-danger" onClick={() => del(f)}><i className="fas fa-trash" /> Delete</button></div></td>
              </tr>))}</tbody>
          </table></div>
        ))}
      </section>
    </div>
  )
}
