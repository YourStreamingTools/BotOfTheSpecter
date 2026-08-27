import html from '../content/privacy.html?raw'

export default function PrivacyPage() {
  return <div dangerouslySetInnerHTML={{ __html: html }} />
}
