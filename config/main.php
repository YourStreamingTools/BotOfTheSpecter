<?php
$dashboardVersion = '5.0.0';
$maintenanceMode = false; // Set to true to enable maintenance mode
$maintenanceMessage = 'System migration in progress &mdash; your bot may briefly appear offline while it is moved to new server infrastructure. It is not actually offline; normal service will resume shortly.'; // Shown on the dashboard while maintenanceMode is true. Always English, edit here only.
$streamersconnect_api_key = '';
return [
    'dashboardVersion' => $dashboardVersion,
    'maintenanceMode' => $maintenanceMode,
    'maintenanceMessage' => $maintenanceMessage,
    'streamersconnect_api_key' => $streamersconnect_api_key,
];