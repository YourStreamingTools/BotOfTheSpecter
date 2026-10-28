import html from '../content/terms.html?raw'
import { effectiveDates, withEffectiveDate } from '../content/legal'

const content = withEffectiveDate(html, effectiveDates.terms)

export default function TermsPage() {
  return <div dangerouslySetInnerHTML={{ __html: content }} />
}
