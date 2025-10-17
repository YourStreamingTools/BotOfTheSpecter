const express = require('express');
const router = express.Router();

// Middleware to check admin access
function requireAdmin(req, res, next) {
  if (req.session && req.session.admin) {
    return next();
  } else {
    res.status(403).json({ error: 'Admin access required' });
  }
}

// Categories API
router.get('/categories', async (req, res) => {
  try {
    const db = req.app.locals.db;

    if (!db) {
      return res.status(503).json({ error: 'Database not available' });
    }

    // Create database if it doesn't exist
    await db.execute('CREATE DATABASE IF NOT EXISTS roadmap');
    await db.execute('USE roadmap');

    // Create categories table if it doesn't exist
    await db.execute(`
      CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY idx_name (name)
      )
    `);

    const [categories] = await db.execute('SELECT * FROM categories ORDER BY created_at DESC');
    res.json(categories);
  } catch (error) {
    console.error('API error in GET /api/categories:', error);
    res.status(500).json({ error: error.message });
  }
});

router.post('/categories', requireAdmin, async (req, res) => {
  try {
    const db = req.app.locals.db;
    const { name, description } = req.body;

    if (!name) {
      return res.status(400).json({ error: 'Name is required' });
    }

    // Insert category
    const [result] = await db.execute(
      'INSERT INTO categories (name, description) VALUES (?, ?)',
      [name, description || '']
    );

    // Create board for this category
    const boardName = `${name} Board`;
    const [boardResult] = await db.execute(
      'INSERT INTO boards (category_id, name, created_by) VALUES (?, ?, ?)',
      [result.insertId, boardName, req.session.username]
    );

    // Create default lists for the board
    const defaultLists = ['Upcoming', 'In Progress', 'Review', 'Completed'];
    for (let i = 0; i < defaultLists.length; i++) {
      await db.execute(
        'INSERT INTO lists (board_id, name, position) VALUES (?, ?, ?)',
        [boardResult.insertId, defaultLists[i], i + 1]
      );
    }

    res.json({
      id: result.insertId,
      name,
      description,
      board_id: boardResult.insertId
    });
  } catch (error) {
    console.error('Error creating category:', error);
    res.status(500).json({ error: error.message });
  }
});

// Boards API
router.get('/boards', async (req, res) => {
  try {
    const db = req.app.locals.db;

    // Create boards table if it doesn't exist
    await db.execute(`
      CREATE TABLE IF NOT EXISTS boards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        created_by VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
      )
    `);

    const [boards] = await db.execute('SELECT * FROM boards ORDER BY created_at DESC');
    res.json(boards);
  } catch (error) {
    console.error('Error fetching boards:', error);
    res.status(500).json({ error: error.message });
  }
});

router.get('/boards/:id', async (req, res) => {
  try {
    const db = req.app.locals.db;
    const boardId = req.params.id;

    // Get board
    const [boardResult] = await db.execute(
      'SELECT * FROM boards WHERE id = ?',
      [boardId]
    );

    if (boardResult.length === 0) {
      return res.status(404).json({ error: 'Board not found' });
    }

    const board = boardResult[0];

    // Get all cards for this board, grouped by section
    const [cardsResult] = await db.execute(
      'SELECT * FROM cards WHERE board_id = ? ORDER BY section, position',
      [boardId]
    );

    // Group cards by section
    const sections = {};
    cardsResult.forEach(card => {
      if (!sections[card.section]) {
        sections[card.section] = [];
      }
      sections[card.section].push(card);
    });

    board.sections = sections;
    res.json(board);
  } catch (error) {
    console.error('Error fetching board:', error);
    res.status(500).json({ error: error.message });
  }
});

// Cards API
router.post('/cards', requireAdmin, async (req, res) => {
  try {
    const db = req.app.locals.db;
    const { board_id, title, section, description } = req.body;

    if (!board_id || !title) {
      return res.status(400).json({ error: 'Board ID and title are required' });
    }

    // Create cards table if it doesn't exist
    await db.execute(`
      CREATE TABLE IF NOT EXISTS cards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        board_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        section VARCHAR(50) DEFAULT 'Upcoming',
        position INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
      )
    `);

    // Get the highest position for this section
    const [positionResult] = await db.execute(
      'SELECT MAX(position) as max_pos FROM cards WHERE board_id = ? AND section = ?',
      [board_id, section || 'Upcoming']
    );

    const position = (positionResult[0].max_pos || 0) + 1;

    const [result] = await db.execute(
      'INSERT INTO cards (board_id, title, description, section, position) VALUES (?, ?, ?, ?, ?)',
      [board_id, title, description || '', section || 'Upcoming', position]
    );

    res.json({
      id: result.insertId,
      board_id,
      title,
      description,
      section: section || 'Upcoming',
      position
    });
  } catch (error) {
    console.error('Error creating card:', error);
    res.status(500).json({ error: error.message });
  }
});

// Lists API
router.post('/lists', requireAdmin, async (req, res) => {
  try {
    const db = req.app.locals.db;
    const { board_id, name, position } = req.body;

    if (!board_id || !name) {
      return res.status(400).json({ error: 'Board ID and name are required' });
    }

    // Create lists table if it doesn't exist
    await db.execute(`
      CREATE TABLE IF NOT EXISTS lists (
        id INT AUTO_INCREMENT PRIMARY KEY,
        board_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        position INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
      )
    `);

    const [result] = await db.execute(
      'INSERT INTO lists (board_id, name, position) VALUES (?, ?, ?)',
      [board_id, name, position || 0]
    );

    res.json({
      id: result.insertId,
      board_id,
      name,
      position: position || 0
    });
  } catch (error) {
    console.error('Error creating list:', error);
    res.status(500).json({ error: error.message });
  }
});

// Update API (for moving cards, etc.)
router.post('/update', requireAdmin, async (req, res) => {
  try {
    const db = req.app.locals.db;
    const { type, card_id, section, position } = req.body;

    if (type === 'move_card') {
      if (!card_id || !section) {
        return res.status(400).json({ error: 'Card ID and section are required' });
      }

      await db.execute(
        'UPDATE cards SET section = ?, position = ? WHERE id = ?',
        [section, position || 0, card_id]
      );

      res.json({ success: true });
    } else {
      res.status(400).json({ error: 'Unknown update type' });
    }
  } catch (error) {
    console.error('Error updating:', error);
    res.status(500).json({ error: error.message });
  }
});

// Category stats API
router.get('/category-stats', async (req, res) => {
  try {
    const db = req.app.locals.db;

    if (req.query.id) {
      // Get stats for a specific category
      const categoryId = req.query.id;
      const [stats] = await db.execute(`
        SELECT
          c.name,
          COUNT(DISTINCT b.id) as boards_count,
          COUNT(DISTINCT ca.id) as total_cards,
          COUNT(DISTINCT CASE WHEN ca.section = 'Completed' THEN ca.id END) as completed_cards,
          COUNT(DISTINCT CASE WHEN ca.section != 'Completed' THEN ca.id END) as active_cards
        FROM categories c
        LEFT JOIN boards b ON c.id = b.category_id
        LEFT JOIN cards ca ON b.id = ca.board_id
        WHERE c.id = ?
        GROUP BY c.id, c.name
      `, [categoryId]);

      res.json(stats[0] || {});
    } else {
      // Get stats for all categories
      const [stats] = await db.execute(`
        SELECT
          c.id,
          c.name,
          COUNT(DISTINCT b.id) as boards_count,
          COUNT(DISTINCT ca.id) as total_cards,
          COUNT(DISTINCT CASE WHEN ca.section = 'Completed' THEN ca.id END) as completed_cards,
          COUNT(DISTINCT CASE WHEN ca.section != 'Completed' THEN ca.id END) as active_cards
        FROM categories c
        LEFT JOIN boards b ON c.id = b.category_id
        LEFT JOIN cards ca ON b.id = ca.board_id
        GROUP BY c.id, c.name
        ORDER BY c.created_at DESC
      `);

      res.json(stats);
    }
  } catch (error) {
    console.error('Error fetching category stats:', error);
    res.status(500).json({ error: error.message });
  }
});

// Completed items API
router.get('/completed-items', async (req, res) => {
  try {
    const db = req.app.locals.db;

    const [items] = await db.execute(`
      SELECT
        ca.*,
        b.name as board_name,
        c.name as category_name
      FROM cards ca
      JOIN boards b ON ca.board_id = b.id
      JOIN categories c ON b.category_id = c.id
      WHERE ca.section = 'Completed'
      ORDER BY ca.created_at DESC
      LIMIT 6
    `);

    res.json(items);
  } catch (error) {
    console.error('Error fetching completed items:', error);
    res.status(500).json({ error: error.message });
  }
});

// Beta items API
router.get('/beta-items', async (req, res) => {
  try {
    const db = req.app.locals.db;

    const [items] = await db.execute(`
      SELECT
        ca.*,
        b.name as board_name,
        c.name as category_name
      FROM cards ca
      JOIN boards b ON ca.board_id = b.id
      JOIN categories c ON b.category_id = c.id
      WHERE ca.section = 'In Progress' OR ca.section = 'Review'
      ORDER BY ca.created_at DESC
      LIMIT 6
    `);

    res.json(items);
  } catch (error) {
    console.error('Error fetching beta items:', error);
    res.status(500).json({ error: error.message });
  }
});

// Get board by category ID
router.get('/get-board', async (req, res) => {
  try {
    const db = req.app.locals.db;
    const categoryId = req.query.category_id;

    if (!categoryId) {
      return res.status(400).json({ error: 'category_id required' });
    }

    const [boardResult] = await db.execute(
      'SELECT id FROM boards WHERE category_id = ? LIMIT 1',
      [categoryId]
    );

    if (boardResult.length > 0) {
      res.json({ board: { id: boardResult[0].id } });
    } else {
      res.json({ board: null });
    }
  } catch (error) {
    console.error('Error getting board:', error);
    res.status(500).json({ error: error.message });
  }
});

// Clear completed items (admin only)
router.post('/clear-completed-items', requireAdmin, async (req, res) => {
  try {
    const db = req.app.locals.db;

    // Get count before deletion
    const [countResult] = await db.execute(
      "SELECT COUNT(*) as cnt FROM cards WHERE section = 'Completed'"
    );

    // Delete completed items
    await db.execute("DELETE FROM cards WHERE section = 'Completed'");

    res.json({
      success: true,
      message: 'All completed items have been cleared',
      deleted_count: countResult[0].cnt
    });
  } catch (error) {
    console.error('Error clearing completed items:', error);
    res.status(500).json({ error: error.message });
  }
});

// Database admin API
router.get('/db-admin', requireAdmin, async (req, res) => {
  try {
    const db = req.app.locals.db;

    // Get database status
    const status = {
      database_exists: false,
      tables: {},
      issues: []
    };

    // Check if database exists
    try {
      await db.execute('USE roadmap');
      status.database_exists = true;
    } catch (error) {
      status.issues.push('Database does not exist');
    }

    if (status.database_exists) {
      // Check tables
      const tables = ['categories', 'boards', 'lists', 'cards'];
      for (const table of tables) {
        try {
          const [result] = await db.execute(`SHOW TABLES LIKE '${table}'`);
          status.tables[table] = result.length > 0;
        } catch (error) {
          status.tables[table] = false;
          status.issues.push(`Table '${table}' check failed`);
        }
      }
    }

    res.json(status);
  } catch (error) {
    console.error('Error checking database:', error);
    res.status(500).json({ error: error.message });
  }
});

router.post('/db-admin', requireAdmin, async (req, res) => {
  try {
    const db = req.app.locals.db;

    // Create database and tables
    await db.execute('CREATE DATABASE IF NOT EXISTS roadmap');
    await db.execute('USE roadmap');

    // Create tables
    const createStatements = [
      `CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY idx_name (name)
      )`,
      `CREATE TABLE IF NOT EXISTS boards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        created_by VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
      )`,
      `CREATE TABLE IF NOT EXISTS lists (
        id INT AUTO_INCREMENT PRIMARY KEY,
        board_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        position INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
      )`,
      `CREATE TABLE IF NOT EXISTS cards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        board_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        section VARCHAR(50) DEFAULT 'Upcoming',
        position INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
      )`
    ];

    for (const statement of createStatements) {
      await db.execute(statement);
    }

    res.json({ success: true, message: 'Database schema created successfully' });
  } catch (error) {
    console.error('Error creating database schema:', error);
    res.status(500).json({ error: error.message });
  }
});

module.exports = router;