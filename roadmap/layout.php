<?php 
function uuidv4() { 
    return bin2hex(random_bytes(2)); 
} 
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark" class="theme-dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - BotOfTheSpecter Roadmap' : 'BotOfTheSpecter Roadmap'; ?></title>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/custom.css?v=<?php echo uuidv4(); ?>">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar is-dark is-fixed-top">
        <div class="navbar-brand">
            <div class="navbar-item">
                <figure class="image is-32x32" style="margin-right: 1rem;">
                    <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter Logo" style="border-radius: 50%;">
                </figure>
                <span class="title is-5">BotOfTheSpecter Roadmap</span>
            </div>
        </div>
        <div class="navbar-menu">
            <div class="navbar-start">
                <a class="navbar-item" href="index.php">
                    <span class="icon-text">
                        <span class="icon"><i class="fas fa-home"></i></span>
                        <span>Home</span>
                    </span>
                </a>
            </div>
            <div class="navbar-end">
                <?php if (isset($_SESSION['username'])): ?>
                    <div class="navbar-item has-dropdown is-hoverable">
                        <a class="navbar-link">
                            <span class="icon-text">
                                <span class="icon"><i class="fas fa-user-circle"></i></span>
                                <span><?php echo htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['username']); ?></span>
                            </span>
                        </a>
                        <div class="navbar-dropdown is-right">
                            <?php if ($_SESSION['admin'] ?? false): ?>
                                <a class="navbar-item" disabled>
                                    <span class="tag is-warning">ADMIN</span>
                                </a>
                                <hr class="navbar-divider">
                            <?php endif; ?>
                            <a class="navbar-item" href="logout.php">
                                <span class="icon-text">
                                    <span class="icon"><i class="fas fa-sign-out-alt"></i></span>
                                    <span>Logout</span>
                                </span>
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="navbar-item">
                        <div class="buttons">
                            <a class="button is-primary" href="login.php">
                                <span class="icon-text">
                                    <span class="icon"><i class="fas fa-sign-in-alt"></i></span>
                                    <span>Login</span>
                                </span>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>
    <!-- Main Content -->
    <main>
        <section class="section" style="margin-top: 3.25rem;">
            <div class="container">
                <?php if (isset($pageContent)): ?>
                    <?php echo $pageContent; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <!-- Footer -->
    <footer class="footer">
        <div class="content has-text-centered">
            <span class="has-text-weight-bold">BotOfTheSpecter Roadmap</span> - A comprehensive Twitch bot platform for streamers.
            <p style="margin-top: 1rem; font-size: 0.875rem;">
                &copy; 2024 BotOfTheSpecter. All rights reserved.
            </p>
        </div>
    </footer>
    <?php if (isset($extraJS)): ?>
        <?php foreach ($extraJS as $js): ?>
            <script src="<?php echo htmlspecialchars($js); ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>