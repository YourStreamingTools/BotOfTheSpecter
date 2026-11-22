// Effective dates for the legal pages. The page HTML keeps the
// original PHP tag, which is replaced with these values when rendered.
export const effectiveDates = {
  privacy: 'November 23, 2026',
  terms: 'November 22, 2026',
}

const TAG = /<\?php echo \$effectiveDate; \?>/g

export function withEffectiveDate(html: string, date: string): string {
  return html.replace(TAG, date)
}
