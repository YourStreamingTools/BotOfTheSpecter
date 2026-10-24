// Shared Prev / page numbers / Next control. Hidden when everything fits on one page.
// Long runs collapse to 1 ... n-1 n n+1 ... last.
export default function Pager({ page, pages, onPage, label = 'Pages', busy = false }: { page: number; pages: number; onPage: (p: number) => void; label?: string; busy?: boolean }) {
  if (pages <= 1) return null
  const nums: (number | '...')[] = []
  for (let p = 1; p <= pages; p++) {
    if (p === 1 || p === pages || Math.abs(p - page) <= 1) nums.push(p)
    else if (nums[nums.length - 1] !== '...') nums.push('...')
  }
  return (
    <nav className="pager" aria-label={label} aria-busy={busy}>
      <button type="button" className="btn btn-ghost" disabled={page <= 1 || busy} onClick={() => onPage(page - 1)} aria-label="Previous page"><i className="fas fa-chevron-left" /> <span className="pager-txt">Prev</span></button>
      {nums.map((n, i) => n === '...' ? <span key={'g' + i} className="pager-gap muted" aria-hidden="true">...</span>
        : <button key={n} type="button" className={`btn ${n === page ? '' : 'btn-ghost'} pager-num`} aria-current={n === page ? 'page' : undefined} aria-label={`Page ${n}`} disabled={busy && n !== page} onClick={() => n !== page && onPage(n)}>{n}</button>)}
      <button type="button" className="btn btn-ghost" disabled={page >= pages || busy} onClick={() => onPage(page + 1)}aria-label="Next page"><span className="pager-txt">Next</span> <i className="fas fa-chevron-right" /></button>
    </nav>
  )
}
