import { useEffect, useState, type FormEvent } from 'react'
import {
  apiGet,
  apiPost,
  attachUrl,
  highlight,
  renderMarkdown,
  sydneyDate,
  CAT_TAG,
  PRIO_TAG,
  subTag,
  type RoadmapItem,
  type RoadmapSession,
} from '../api'

type Tab = 'description' | 'attachments' | 'activity'
type Attachment = {
  id: number
  file_name: string
  file_path: string
  file_type: string
  file_size_formatted?: string
  uploaded_by: string
  created_at: string
  can_delete?: boolean
  is_image?: boolean | number
}
type Comment = {
  id: number
  username: string
  comment: string
  created_at: string
  profile_image: string | null
}

export default function ItemModal({
  item,
  colors,
  session,
  onClose,
  admin,
}: {
  item: RoadmapItem
  colors: Record<string, string>
  session: RoadmapSession
  onClose: () => void
  admin: boolean
}) {
  const [tab, setTab] = useState<Tab>('description')
  const [attachments, setAttachments] = useState<Attachment[] | null>(null)
  const [comments, setComments] = useState<Comment[] | null>(null)
  const [createdBy, setCreatedBy] = useState<string | null>(null)
  const [commentText, setCommentText] = useState('')
  const [showComment, setShowComment] = useState(false)
  const [zoom, setZoom] = useState<{ src: string; name: string } | null>(null)

  useEffect(() => {
    const u = new URL(window.location.href)
    u.searchParams.set('item', String(item.id))
    history.replaceState({}, '', u.toString())
    return () => {
      const n = new URL(window.location.href)
      n.searchParams.delete('item')
      history.replaceState({}, '', n.toString())
    }
  }, [item.id])

  useEffect(() => {
    loadAtt()
    loadAct()
  }, [item.id])

  function loadAtt() {
    apiGet('/admin/get-attachments.php?item_id=' + item.id).then((data: { success?: boolean; attachments?: Attachment[] }) => {
      setAttachments(data?.success ? (data.attachments || []) : [])
    }).catch(() => setAttachments([]))
  }
  function loadAct() {
    apiGet('/api/activity.php?item_id=' + item.id).then((data: { comments?: Comment[]; created_by?: string }) => {
      setComments(data?.comments || [])
      setCreatedBy(data?.created_by || null)
    }).catch(() => setComments([]))
  }

  async function copyShare() {
    const u = new URL(window.location.href)
    u.searchParams.delete('search')
    u.searchParams.delete('category')
    u.searchParams.set('item', String(item.id))
    try {
      await navigator.clipboard.writeText(u.toString())
    } catch {
      window.prompt('Copy share link:', u.toString())
    }
  }

  async function addComment(e: FormEvent) {
    e.preventDefault()
    await apiPost('/api/admin.php', {
      action: 'add_comment',
      csrf_token: session.csrf_token,
      item_id: item.id,
      comment_text: commentText,
    })
    setCommentText('')
    setShowComment(false)
    loadAct()
  }

  async function delComment(id: number) {
    if (!confirm('Delete this comment?')) return
    const fd = new FormData()
    fd.append('comment_id', String(id))
    fd.append('csrf_token', session.csrf_token)
    await fetch('/admin/delete-comment.php', { method: 'POST', body: fd, credentials: 'same-origin' })
    loadAct()
  }

  async function delAtt(id: number) {
    if (!confirm('Delete this attachment?')) return
    const fd = new FormData()
    fd.append('attachment_id', String(id))
    fd.append('csrf_token', session.csrf_token)
    await fetch('/admin/delete-attachment.php', { method: 'POST', body: fd, credentials: 'same-origin' })
    loadAtt()
  }

  async function upload(files: FileList | null) {
    if (!files?.length) return
    for (const file of Array.from(files)) {
      const fd = new FormData()
      fd.append('file', file)
      fd.append('item_id', String(item.id))
      fd.append('csrf_token', session.csrf_token)
      await fetch('/admin/upload-attachment.php', { method: 'POST', body: fd, credentials: 'same-origin' })
    }
    loadAtt()
  }

  const html = renderMarkdown(item.description || '')

  return (
    <>
      <div className="rm-modal open" id="detailsModal">
        <div className="rm-modal-backdrop" onClick={onClose} />
        <div className="rm-modal-card rm-modal-card-xlg" style={{ height: '88vh' }}>
          <div className="rm-modal-head">
            <span className="rm-modal-title">{item.title}</span>
            <button className="rm-modal-close" type="button" aria-label="Close" onClick={onClose}><i className="fa-solid fa-xmark" /></button>
          </div>
          <div className="rm-modal-tabs">
            {(['description', 'attachments', 'activity'] as Tab[]).map((t) => (
              <button key={t} type="button" className={'rm-modal-tab' + (tab === t ? ' active' : '')} onClick={() => setTab(t)}>
                <i className={'fa-solid ' + (t === 'description' ? 'fa-align-left' : t === 'attachments' ? 'fa-paperclip' : 'fa-comments')} /> {t.charAt(0).toUpperCase() + t.slice(1)}
              </button>
            ))}
          </div>
          <div className="rm-modal-body" style={{ paddingTop: 0 }}>
            {tab === 'description' && (
              <div className="rm-modal-panel active" style={{ paddingTop: '1rem' }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.3rem', marginBottom: '0.6rem' }}>
                  <span className={'rm-tag rm-tag-' + (CAT_TAG[item.category] || 'light')}>{item.category}</span>
                  <span className={'rm-tag rm-tag-' + (PRIO_TAG[item.priority] || 'light')}>{item.priority}</span>
                  {item.subcategories.map((s) => <span key={s} className={'rm-tag rm-tag-' + subTag(s, colors)}>{s}</span>)}
                  {item.website_types.map((w) => <span key={w} className="rm-tag rm-tag-info">{w}</span>)}
                </div>
                <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginBottom: '0.6rem' }}>
                  Created {sydneyDate(item.created_at)}
                  {item.updated_at && item.updated_at !== item.created_at ? ' • Updated ' + sydneyDate(item.updated_at) : ''}
                </p>
                <button type="button" className="sp-btn sp-btn-secondary sp-btn-sm" style={{ marginBottom: '0.75rem' }} onClick={copyShare}>
                  <i className="fa-solid fa-link" /> Copy Share Link
                </button>
                <Markdown html={html} />
              </div>
            )}
            {tab === 'attachments' && (
              <div className="rm-modal-panel active" style={{ paddingTop: '1rem' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.75rem' }}>
                  <h4 style={{ fontSize: '0.9rem', fontWeight: 600 }}>Attachments</h4>
                  {admin && (
                    <label className="sp-btn sp-btn-success sp-btn-sm">
                      <i className="fa-solid fa-plus" /> Add
                      <input type="file" multiple hidden onChange={(e) => { upload(e.target.files); e.target.value = '' }} />
                    </label>
                  )}
                </div>
                {!attachments?.length && <p style={{ color: 'var(--text-muted)', fontSize: '0.875rem', fontStyle: 'italic' }}>No attachments</p>}
                {attachments?.map((att) => {
                  const img = String(att.file_type || '').startsWith('image/')
                  const src = attachUrl(att.file_path)
                  return (
                    <div className="rm-attachment" key={att.id}>
                      <div className="rm-attachment-body">
                        <div className="rm-attachment-meta">{att.file_name} · {att.file_size_formatted} · {att.uploaded_by} · {sydneyDate(att.created_at)}</div>
                        {img
                          ? <img src={src} alt={att.file_name} className="rm-attachment-img zoom-image" onClick={() => setZoom({ src, name: att.file_name })} />
                          : <a href={src} download className="rm-attachment-name"><i className="fa-solid fa-file" /> {att.file_name}</a>}
                      </div>
                      {admin && att.can_delete && (
                        <button type="button" className="sp-btn sp-btn-danger sp-btn-xs sp-btn-icon" title="Delete" onClick={() => delAtt(att.id)}>
                          <i className="fa-solid fa-trash-can" />
                        </button>
                      )}
                    </div>
                  )
                })}
              </div>
            )}
            {tab === 'activity' && (
              <div className="rm-modal-panel active" style={{ paddingTop: '1rem', display: 'flex', flexDirection: 'column', height: '100%' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.75rem' }}>
                  <h4 style={{ fontSize: '0.9rem', fontWeight: 600 }}>Activity</h4>
                  {admin && (
                    <button type="button" className="sp-btn sp-btn-primary sp-btn-sm" onClick={() => setShowComment(true)}>
                      <i className="fa-solid fa-comment" /> Comment
                    </button>
                  )}
                </div>
                <div style={{ flex: 1, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
                  {comments?.map((c) => (
                    <div className="rm-comment" key={c.id}>
                      <div className="rm-comment-header">
                        <div className="rm-comment-author">
                          {c.profile_image
                            ? <img src={c.profile_image} alt={c.username} className="rm-comment-avatar" />
                            : <div className="rm-comment-avatar rm-comment-avatar-fallback">{c.username.slice(0, 1).toUpperCase()}</div>}
                          <span className="rm-comment-username">{c.username}</span>
                        </div>
                        <div className="rm-comment-actions">
                          <span className="rm-comment-time">{sydneyDate(c.created_at)}</span>
                          {admin && (
                            <button type="button" className="sp-btn sp-btn-danger sp-btn-xs sp-btn-icon" title="Delete comment" onClick={() => delComment(c.id)}>
                              <i className="fa-solid fa-trash-can" />
                            </button>
                          )}
                        </div>
                      </div>
                      <div className="rm-comment-text">{c.comment}</div>
                    </div>
                  ))}
                  {createdBy && <p style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>Created by {createdBy}</p>}
                </div>
              </div>
            )}
          </div>
          <div className="rm-modal-foot">
            <button type="button" className="sp-btn sp-btn-secondary" onClick={onClose}>Close</button>
          </div>
        </div>
      </div>
      {showComment && (
        <div className="rm-modal open">
          <div className="rm-modal-backdrop" onClick={() => setShowComment(false)} />
          <div className="rm-modal-card rm-modal-card-sm">
            <div className="rm-modal-head">
              <span className="rm-modal-title"><i className="fa-solid fa-comment" style={{ color: 'var(--accent-hover)', marginRight: '0.4rem' }} />Add Comment</span>
              <button type="button" className="rm-modal-close" onClick={() => setShowComment(false)}><i className="fa-solid fa-xmark" /></button>
            </div>
            <form onSubmit={addComment}>
              <div className="rm-modal-body">
                <div className="sp-form-group">
                  <label className="sp-label">Comment</label>
                  <textarea className="sp-textarea" rows={5} required value={commentText} onChange={(e) => setCommentText(e.target.value)} />
                </div>
              </div>
              <div className="rm-modal-foot">
                <button type="button" className="sp-btn sp-btn-ghost" onClick={() => setShowComment(false)}>Cancel</button>
                <button type="submit" className="sp-btn sp-btn-primary"><i className="fa-solid fa-paper-plane" /> Submit</button>
              </div>
            </form>
          </div>
        </div>
      )}
      {zoom && (
        <div className="rm-modal open">
          <div className="rm-modal-backdrop" onClick={() => setZoom(null)} />
          <div className="rm-modal-card rm-modal-card-xlg" style={{ maxHeight: '95vh', background: 'var(--bg-base)' }}>
            <div className="rm-modal-head">
              <span className="rm-modal-title">{zoom.name}</span>
              <button type="button" className="rm-modal-close" onClick={() => setZoom(null)}><i className="fa-solid fa-xmark" /></button>
            </div>
            <div className="rm-modal-body" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'var(--bg-base)', padding: '2rem' }}>
              <img src={zoom.src} alt="" style={{ maxWidth: '100%', maxHeight: '80vh', objectFit: 'contain' }} />
            </div>
          </div>
        </div>
      )}
    </>
  )
}

function Markdown({ html }: { html: string }) {
  return (
    <div
      className="rm-doc-content"
      style={{ lineHeight: 1.7 }}
      ref={(el) => { if (el) highlight(el) }}
      dangerouslySetInnerHTML={{ __html: html }}
    />
  )
}
