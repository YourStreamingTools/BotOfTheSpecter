<?php
// GET without refresh_data: same scan as the old first render.
header('Content-Type: application/json');
echo json_encode(fetchAllEventSubConnections($conn, $clientID));
exit;
