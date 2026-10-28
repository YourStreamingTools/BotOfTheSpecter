// Effective dates for the legal pages. The page HTML keeps the
// original PHP tag, which is replaced with these values when rendered.
export const effectiveDates = {
  privacy: 'October 29, 2026',
  terms: 'July 9, 2026',
}

const TAG = /<\?php echo \$effectiveDate; \?>/g

export function withEffectiveDate(html: string, date: string): string {
  return html.replace(TAG, date)
}
