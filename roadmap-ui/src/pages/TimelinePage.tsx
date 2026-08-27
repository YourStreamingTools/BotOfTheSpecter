import { useEffect, useState } from 'react'
import { apiGet, highlight, renderMarkdown } from '../api'

type Version = { version: string; date: string; summary: string; markdown?: string }
type Group = { key: string; month: string; versions: Version[] }

export default function TimelinePage() {
  const [groups, setGroups] = useState<Group[]>([])
  const [open, setOpen] = useState<Version | null>(null)

  useEffect(() => {
    apiGet('/api/timeline.php').then((d: { groups?: Group[] }) => setGroups(d?.groups || []))
  }, [])

  async function openNotes(v: Version) {
    const d = await apiGet('/api/timeline.php?version=' + encodeURIComponent(v.version))
    setOpen(d?.version || { ...v, markdown: '' })
  }

  return (
    <>
      <div className="sp-page-header">
        <div>
          <h1 className="sp-page-title">Development Timeline</h1>
          <p className="sp-page-subtitle">Track the evolution of BotOfTheSpecter through version releases</p>
        </div>
      </div>
      <div className="rm-timeline">
        <div className="rm-timeline-line" />
        {groups.map((g) => (
          <div className="rm-timeline-section" key={g.key}>
            <h2 className="rm-timeline-month">{g.month}</h2>
            {g.versions.map((version, idx) => (
              <div key={version.version} className={'rm-timeline-item rm-tl-' + (idx % 2 === 0 ? 'left' : 'right')}>
                <div className="rm-timeline-dot" />
                <div className="rm-timeline-card">
                  <small className="rm-timeline-date">{version.date}</small>
                  <h3 className="rm-timeline-title">Version {version.version}</h3>
                  {version.summary && <p className="rm-timeline-desc">{version.summary.slice(0, 150)}</p>}
                  <button type="button" className="sp-btn sp-btn-info sp-btn-sm" onClick={() => openNotes(version)}>
                    <i className="fa-solid fa-file-lines" /> View Notes
                  </button>
                </div>
              </div>
            ))}
          </div>
        ))}
        {!groups.length && (
          <div className="rm-timeline-empty">
            <i className="fa-solid fa-calendar-xmark" /> No version releases found
          </div>
        )}
      </div>
      {open && (
        <div className="rm-modal open">
          <div className="rm-modal-backdrop" onClick={() => setOpen(null)} />
          <div className="rm-modal-card rm-modal-card-lg" style={{ maxHeight: '88vh' }}>
            <div className="rm-modal-head">
              <span className="rm-modal-title">Version {open.version} - Changelog</span>
              <button type="button" className="rm-modal-close" onClick={() => setOpen(null)}><i className="fa-solid fa-xmark" /></button>
            </div>
            <div
              className="rm-modal-body rm-doc-content"
              ref={(el) => { if (el) highlight(el) }}
              dangerouslySetInnerHTML={{ __html: renderMarkdown(open.markdown || '') }}
            />
            <div className="rm-modal-foot">
              <button type="button" className="sp-btn sp-btn-secondary" onClick={() => setOpen(null)}>Close</button>
            </div>
          </div>
        </div>
      )}
    </>
  )
}
