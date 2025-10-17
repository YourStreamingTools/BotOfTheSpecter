const express = require('express');
const router = express.Router();

// Health check endpoint for cPanel
router.get('/health', (req, res) => {
  res.status(200).send('OK');
});

// Middleware to check if user is logged in
function requireLogin(req, res, next) {
  if (req.session && req.session.username) {
    return next();
  } else {
    res.redirect('/login');
  }
}

// Middleware to check if user is admin
function requireAdmin(req, res, next) {
  if (req.session && req.session.admin) {
    return next();
  } else {
    res.status(403).render('403', { title: 'Access Denied' });
  }
}

// Home page
router.get('/', async (req, res) => {
  try {
    const db = req.app.locals.db;

    // Default stats if database is not available
    let stats = {
      total_categories: 0,
      total_boards: 0,
      active_items: 0,
      completed_items: 0
    };
    if (db) {
      try {
        // Get stats for the homepage
        const [statsResult] = await db.execute(`
          SELECT
            (SELECT COUNT(*) FROM categories) as total_categories,
            (SELECT COUNT(*) FROM boards) as total_boards,
            (SELECT COUNT(*) FROM cards WHERE section != 'Completed') as active_items,
            (SELECT COUNT(*) FROM cards WHERE section = 'Completed') as completed_items
        `);
        stats = statsResult[0];
      } catch (dbError) {
        console.warn('Database query failed, using default stats:', dbError.message);
      }
    }

    res.render('index', {
      title: 'BotOfTheSpecter Roadmap',
      bodyClass: 'bg-gradient-to-br from-blue-600 to-blue-800 text-white',
      navWidth: 'max-w-7xl',
      user: req.session,
      stats: stats
    });
  } catch (error) {
    console.error('Error loading homepage:', error);
    res.status(500).render('500', { title: 'Server Error' });
  }
});

// Board page
router.get('/board/:id', requireLogin, async (req, res) => {
  try {
    const db = req.app.locals.db;
    const boardId = req.params.id;

    // Get board info
    const [boardResult] = await db.execute(
      'SELECT * FROM boards WHERE id = ?',
      [boardId]
    );

    if (boardResult.length === 0) {
      return res.status(404).render('404', { title: 'Board Not Found' });
    }

    const board = boardResult[0];

    res.render('board', {
      title: board.name,
      bodyClass: 'bg-blue-600',
      navWidth: 'max-w-full',
      navCenter: `
        <div class="flex items-center gap-4">
          <div id="board-title" class="text-xl font-semibold">${board.name}</div>
        </div>
      `,
      extraScripts: '<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>',
      extraHead: `
        <style>
          .board { display: flex; overflow-x: auto; padding: 20px; min-height: calc(100vh - 80px); gap: 20px; }
          .list { min-width: 300px; background: #364152; color: #fff; border-radius: 8px; padding: 15px; flex-shrink: 0; }
          .list h5 { color: #fff; }
          .card { background: white; margin-bottom: 12px; padding: 12px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.12); cursor: grab; transition: all 0.2s; }
          .card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
          .card:active { cursor: grabbing; }
          .add-card { color: #5e6c84; cursor: pointer; padding: 10px; font-weight: 500; transition: all 0.2s; }
          .add-card:hover { background: rgba(0,0,0,0.1); border-radius: 4px; }
          .edit-only { display: none; }
        </style>
      `,
      user: req.session,
      board: board
    });
  } catch (error) {
    console.error('Error loading board:', error);
    res.status(500).render('500', { title: 'Server Error' });
  }
});

// Admin panel
router.get('/admin', requireLogin, requireAdmin, async (req, res) => {
  try {
    res.render('admin', {
      title: 'Admin Panel',
      bodyClass: 'bg-gradient-to-br from-blue-600 to-blue-800 text-white',
      navWidth: 'max-w-7xl',
      user: req.session
    });
  } catch (error) {
    console.error('Error loading admin panel:', error);
    res.status(500).render('500', { title: 'Server Error' });
  }
});

// Login page
router.get('/login', (req, res) => {
  if (req.session && req.session.username) {
    return res.redirect('/');
  }

  res.render('login', {
    title: 'Login',
    bodyClass: 'bg-gradient-to-br from-blue-600 to-blue-800 text-white min-h-screen flex items-center justify-center',
    user: null
  });
});

// Logout
router.get('/logout', (req, res) => {
  req.session.destroy((err) => {
    if (err) {
      console.error('Error destroying session:', err);
    }
    res.redirect('/');
  });
});

module.exports = router;