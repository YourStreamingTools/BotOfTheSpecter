# Support portal (React)

Public UI for support.botofthespecter.com. Vite builds a static bundle into `./support/app/`. PHP serves that shell from `index.php`, `tickets.php`, and `beta.php`. Login and SSO stay PHP (`login.php` / `logout.php`). Tickets and beta mutate through `/api/*.php` with the session cookie and CSRF token.

```
cd support-ui
npm install
npm run build
```

No Node process on web1 at runtime. Hash URLs (`/index.php#spotify`) still select the matching guide.
