import html from '../content/terms.html?raw'

export default function TermsPage() {
  return <div dangerouslySetInnerHTML={{ __html: html }} />
}
