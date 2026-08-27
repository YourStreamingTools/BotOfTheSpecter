export default function FeedbackPage() {
  return (
    <div className="hs-prose" role="main" aria-labelledby="feedback-heading">
      <h1 id="feedback-heading">Feedback Has Moved</h1>
      <p className="hs-prose-subtitle">
        Our feedback and bug reporting system has been moved to our unified support ticket system.
      </p>
      <div className="hs-form-card" style={{ textAlign: 'center' }}>
        <p>To submit feedback, bug reports, or feature requests, please visit our new support portal:</p>
        <p>
          <a className="hs-btn hs-btn-primary" href="https://support.botofthespecter.com" target="_blank" rel="noopener">
            <i className="fa-solid fa-ticket" /> Go to Support Portal
          </a>
        </p>
      </div>
      <aside className="hs-card hs-feedback-sidebar">
        <h3>
          <i className="fa-solid fa-circle-question" /> Need help?
        </h3>
        <p>
          Join our{' '}
          <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener">
            <i className="fa-brands fa-discord" /> Public Discord Server
          </a>{' '}
          for live support and discussions.
        </p>
        <hr />
        <p>
          <strong>Privacy:</strong> See our <a href="/privacy-policy.php">Privacy Policy</a>.
        </p>
        <p>
          <strong>Terms:</strong> See our <a href="/terms-of-service.php">Terms of Service</a>.
        </p>
      </aside>
    </div>
  )
}
