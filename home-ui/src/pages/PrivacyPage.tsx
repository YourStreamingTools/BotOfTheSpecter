import html from '../content/privacy.html?raw'
import { effectiveDates, withEffectiveDate } from '../content/legal'

const content = withEffectiveDate(html, effectiveDates.privacy)

export default function PrivacyPage() {
  return <div dangerouslySetInnerHTML={{ __html: content }} />
}
