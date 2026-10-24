import { useState, type ReactNode } from 'react'

// Port of dashboard/integrations.php: the Fourthwall, Ko-fi and Patreon
// webhook setup steps. Static, no endpoint.
const KEY = <>Add your API key to the end of the URL. You can find it on the <a href="/profile">Profile page</a>.</>
function Url({ u }: { u: string }) {
  const [ok, setOk] = useState(false)
  const copy = () => navigator.clipboard?.writeText(u).then(() => { setOk(true); setTimeout(() => setOk(false), 1500) })
  return <span className="ig-chip"><code className="ig-url" tabIndex={0}>{u}</code><button type="button" className="btn btn-ghost ig-copy" onClick={copy} aria-label={`Copy ${u}`} title="Copy URL"><i className={ok ? 'fas fa-check' : 'fas fa-copy'} /><span>{ok ? 'Copied' : 'Copy'}</span></button></span>
}

function Card({ id, icon, title, note, steps, done }: { id: string; icon: string; title: string; note?: string; steps: ReactNode[]; done: string }) {
  return (
    <section className="card ig-card" id={id} aria-label={title}>
      <h3 className="card-h"><i className={icon} /> {title}</h3>
      {note && <div className="notice notice-info"><i className="fas fa-circle-info" /> <span>{note}</span></div>}
      <ol className="ig-steps">{steps.map((s, i) => <li key={i}>{s}</li>)}</ol>
      <div className="notice notice-ok ig-done"><i className="fas fa-check" /> <span><b>That's it!</b> {done}</span></div>
    </section>
  )
}

export default function Integrations() {
  return (
    <div className="page cmds ig">
      <div className="ig-grid">
        <Card id="fourthwall" icon="fas fa-store" title="Fourthwall Integration" done="Your Fourthwall account is now integrated with Specter." steps={[
          'Login to your Fourthwall admin dashboard.',
          'On the left-hand menu, click Settings.',
          'In the Site Settings page, find and click the For developers link.',
          'Click Create webhook in the webhooks section.',
          <>In the URL field, enter:<Url u="https://api.botofthespecter.com/fourthwall?api_key=" />{KEY}</>,
          <>From the "Add Event" list, choose any or all of the following events:<ul><li>Order placed</li><li>Gift purchase</li><li>Donation</li><li>Subscription purchased</li></ul></>,
        ]} />
        <Card id="kofi" icon="fas fa-coffee" title="Ko-fi Integration" done="Your Ko-fi account is now integrated with Specter." steps={[
          'Log into your Ko-fi account.',
          'When the manage page loads, on the left-hand side, under Stream Alerts, click the three dots where it says More.',
          'In the "More" section, click the API option.',
          <>In the webhook URL field, enter:<Url u="https://api.botofthespecter.com/kofi?api_key=" />{KEY}</>,
          "Once you've entered the URL, click the Update button.",
        ]} />
        <Card id="patreon" icon="fab fa-patreon" title="Patreon Integration" done="Your Patreon account is now integrated with Specter." steps={[
          <>Visit the Dev Portal and register a new webhook request at: <a className="ig-link" href="https://www.patreon.com/portal/registration/register-webhooks" target="_blank" rel="noopener noreferrer">Patreon webhook registration <i className="fas fa-arrow-up-right-from-square" /></a></>,
          <>On the webhook page, paste your URL in the field labeled "Create a new webhook by pasting your URL here", then click the plus icon to save. Enter the following URL:<Url u="https://api.botofthespecter.com/patreon?api_key=" />{KEY}</>,
          <>After the webhook is added, remove the outdated options:<ul><li>"pledges:create"</li><li>"pledges:delete"</li><li>"pledges:update"</li></ul>and enable only the <code>members:pledge:create</code> option.</>,
        ]} />
      </div>
    </div>
  )
}
