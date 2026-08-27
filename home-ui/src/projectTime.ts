const LAUNCH = new Date('2023-10-17T11:54:58+11:00')

function plural(n: number, word: string): string {
  return n + ' ' + word + (n === 1 ? '' : 's')
}

export function projectTimeHtml(): { since: string; elapsed: string | null } {
  const now = new Date()
  let y = now.getFullYear() - LAUNCH.getFullYear()
  let m = now.getMonth() - LAUNCH.getMonth()
  let d = now.getDate() - LAUNCH.getDate()
  let h = now.getHours() - LAUNCH.getHours()
  let min = now.getMinutes() - LAUNCH.getMinutes()
  if (min < 0) {
    min += 60
    h -= 1
  }
  if (h < 0) {
    h += 24
    d -= 1
  }
  if (d < 0) {
    const prev = new Date(now.getFullYear(), now.getMonth(), 0).getDate()
    d += prev
    m -= 1
  }
  if (m < 0) {
    m += 12
    y -= 1
  }
  const parts: string[] = []
  if (y) parts.push(plural(y, 'year'))
  if (m) parts.push(plural(m, 'month'))
  if (d) parts.push(plural(d, 'day'))
  if (h) parts.push(plural(h, 'hour'))
  if (min) parts.push(plural(min, 'minute'))
  return {
    since: 'Project has been running since 17th October 2023, 11:54:58 AEDT',
    elapsed: parts.length ? "As of now, it's been " + parts.join(', ') + ' since launch.' : null,
  }
}
