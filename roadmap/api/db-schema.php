<?php
/**
 * Database Schema Management
 * Defines all required tables, columns, and indexes
 * Used by the database admin panel to validate and auto-create schema
 */

// Define all tables and their required columns
$DATABASE_SCHEMA = [
    'categories' => [
        'columns' => [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'name' => 'VARCHAR(255) NOT NULL',
            'description' => 'TEXT',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
        ],
        'indexes' => [
            'idx_name' => 'UNIQUE KEY idx_name (name)'
        ]
    ],
    'boards' => [
        'columns' => [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'category_id' => 'INT NOT NULL',
            'name' => 'VARCHAR(255) NOT NULL',
            'created_by' => 'VARCHAR(255)',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'FOREIGN KEY (category_id)' => 'REFERENCES categories(id) ON DELETE CASCADE'
        ],
        'indexes' => [
            'idx_category' => 'KEY idx_category (category_id)',
            'idx_created_at' => 'KEY idx_created_at (created_at)'
        ]
    ],
    'lists' => [
        'columns' => [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'board_id' => 'INT NOT NULL',
            'name' => 'VARCHAR(255) NOT NULL',
            'position' => 'INT DEFAULT 0',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'FOREIGN KEY (board_id)' => 'REFERENCES boards(id) ON DELETE CASCADE'
        ],
        'indexes' => [
            'idx_board' => 'KEY idx_board (board_id)',
            'idx_position' => 'KEY idx_position (position)'
        ]
    ],
    'cards' => [
        'columns' => [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'list_id' => 'INT NOT NULL',
            'title' => 'VARCHAR(255) NOT NULL',
            'description' => 'TEXT',
            'position' => 'INT DEFAULT 0',
            'due_date' => 'DATE',
            'labels' => 'VARCHAR(255)',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'FOREIGN KEY (list_id)' => 'REFERENCES lists(id) ON DELETE CASCADE'
        ],
        'indexes' => [
            'idx_list' => 'KEY idx_list (list_id)',
            'idx_position' => 'KEY idx_position (position)',
            'idx_created_at' => 'KEY idx_created_at (created_at)'
        ]
    ]
];

?>
