export default function NotFound({ title }: { title?: string | null }) {
  return (
    <section className="card">
      {title && <p className="muted">{title}</p>}
      <h2>Coming soon</h2>
    </section>
  )
}
