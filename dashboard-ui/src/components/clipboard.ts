// Copies text that may still be loading (an overlay URL fetched on click).
// Safari and Firefox drop the click's permission to write the clipboard once
// the click handler awaits a network request, so writeText(await fetch()) can
// fail or leave the clipboard holding whatever was copied before. Handing the
// clipboard a ClipboardItem with a pending Blob keeps the write tied to the
// click. Falls back to writeText, then to a hidden textarea and execCommand.
// Resolves true only when a write is known to have worked.
export async function copyText(value: string | Promise<string>): Promise<boolean> {
  const text = Promise.resolve(value)
  const clip = typeof navigator !== 'undefined' ? navigator.clipboard : undefined
  if (clip?.write && typeof ClipboardItem !== 'undefined') {
    const blob = text.then((t) => new Blob([t], { type: 'text/plain' }))
    blob.catch(() => {}) // a failed fetch is reported below, not as an unhandled rejection
    try {
      await clip.write([new ClipboardItem({ 'text/plain': blob })])
      return true
    } catch { /* try the next way */ }
  }
  const t = await text // a failed fetch rejects here, so the caller can say why
  if (clip?.writeText) {
    try { await clip.writeText(t); return true } catch { /* try the next way */ }
  }
  return legacyCopy(t)
}

function legacyCopy(t: string): boolean {
  const ta = document.createElement('textarea')
  ta.value = t
  ta.setAttribute('readonly', '')
  ta.style.position = 'fixed'; ta.style.top = '0'; ta.style.left = '0'; ta.style.opacity = '0'; ta.style.pointerEvents = 'none'
  const prev = document.activeElement as HTMLElement | null
  document.body.appendChild(ta)
  let ok = false
  try { ta.select(); ok = document.execCommand('copy') } catch { ok = false }
  ta.remove()
  prev?.focus({ preventScroll: true })
  return ok
}
