<?php
// Header include file for all roadmap pages
// This file should be included at the top of each page after session_start()
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - Roadmap' : 'BotOfTheSpecter Roadmap'; ?></title>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="stylesheet" href="dist/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php if (isset($extra_scripts)) echo $extra_scripts; ?>
    <?php if (isset($extra_head)) echo $extra_head; ?>
</head>
<body class="<?php echo isset($body_class) ? $body_class : 'bg-gradient-to-br from-blue-600 to-blue-800 text-white'; ?>">
    <nav style="background-color: #364152;" class="text-white shadow-lg">
        <div class="<?php echo isset($nav_width) ? $nav_width : 'max-w-7xl'; ?> mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
            <div class="flex items-center gap-6">
                <a href="index.php" class="text-2xl font-bold text-white">Roadmap</a>
                <a href="index.php" class="text-white hover:text-blue-300 font-medium transition-colors duration-200">
                    <i class="fas fa-home mr-1"></i>HOME
                </a>
            </div>
            <?php if (isset($nav_center)) echo $nav_center; ?>
            <div id="user-info" class="text-sm flex items-center gap-4 text-white">
                <!-- User info will be inserted here by JavaScript -->
            </div>
        </div>
    </nav>
