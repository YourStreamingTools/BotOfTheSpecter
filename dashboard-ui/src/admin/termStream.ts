// Shared client for public-admin/api/terminal_stream.php (port of admin/terminal_stream.php):
// POST validates the command and returns a one-shot stream id, then an EventSource reads
// stdout / stderr / done events. The CSRF token comes from GET /api/terminal.php.
export type StreamStart = { ok: boolean; stream?: string; needs_confirm?: boolean; error?: string }
let csrf = ''
export async function terminalCsrf(): Promise<string> {
  if (csrf) return csrf
  const r = await fetch('/api/terminal.php', { credentials: 'same-origin' })
  const d = await r.json(); csrf = d.csrf || ''; return csrf
}
export async function startStream(server: string, command: string, opts: { safe?: boolean; confirmed?: boolean } = {}): Promise<StreamStart> {
  const body = new URLSearchParams({ server, command, safe: opts.safe === false ? '0' : '1', confirmed: opts.confirmed ? '1' : '0' })
  const r = await fetch('/api/terminal_stream.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': await terminalCsrf() }, body })
  return r.json()
}
export function openStream(id: string, on: { out: (t: string, err?: boolean) => void; done: (d: Record<string, unknown>) => void; lost: () => void }) {
  const es = new EventSource(`/api/terminal_stream.php?stream=${encodeURIComponent(id)}`)
  const parse = (e: MessageEvent) => { try { return JSON.parse(e.data) } catch { return e.data } }
  es.addEventListener('stdout', (e) => on.out(String(parse(e as MessageEvent))))
  es.addEventListener('message', (e) => on.out(String(parse(e as MessageEvent))))
  es.addEventListener('stderr', (e) => on.out(String(parse(e as MessageEvent)), true))
  es.addEventListener('done', (e) => { es.close(); const d = parse(e as MessageEvent); on.done(typeof d === 'object' && d ? d : {}) })
  es.onerror = () => { if (es.readyState !== EventSource.OPEN) { es.close(); on.lost() } }
  return () => es.close()
}
export const stripAnsi = (s: string) => s.replace(/\x1b\[[0-9;?]*[A-Za-z]/g, '') // eslint-disable-line no-control-regex
