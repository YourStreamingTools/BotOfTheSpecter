<?php
// dashboard/generate_handoff.php
// ----------------------------------------------------------------
// Backwards-compatible shim. The "View Docs" link in the dashboard
// still points here; we now bounce through home/sso.php which is
// the sole token issuer. The user's shared .botofthespecter.com
// cookie carries them through home/sso.php transparently.
//
// Once every link to this file has been retargeted to /sso.php,
// this file can be deleted.
// ----------------------------------------------------------------

header('Location: https://botofthespecter.com/sso.php?target=support');
exit;
