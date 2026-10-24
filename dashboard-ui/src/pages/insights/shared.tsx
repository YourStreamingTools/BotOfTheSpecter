import type { ReactNode } from 'react'
import Modal from '../../components/Modal'

// Yes / no dialog in the theme, standing in for the old SweetAlert / confirm() prompts.
export function Confirm({ title, children, yes, danger = true, busy, onYes, onClose, only }: { title: string; children: ReactNode; yes: string; danger?: boolean; busy?: boolean; only?: boolean; onYes: () => void; onClose: () => void }) {
  return <Modal title={title} icon="fas fa-triangle-exclamation" onClose={onClose}>
    <div className="in-confirm">{children}</div>
    <div className="modal-acts">
      {!only && <button type="button" className="btn btn-ghost cm-btn" onClick={onClose} disabled={busy}>Cancel</button>}
      <button type="button" className={`btn ${danger ? 'btn-danger' : ''} cm-btn`} onClick={() => { onClose(); onYes() }} disabled={busy} data-autofocus>{busy ? <i className="fas fa-spinner fa-spin" /> : null} {yes}</button>
    </div>
  </Modal>
}

export const notOk = (e: unknown) => (e as Error).message !== 'http_401'
