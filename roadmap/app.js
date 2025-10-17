require('dotenv').config();
const express = require('express');
const session = require('express-session');
const mysql = require('mysql2/promise');
const path = require('path');
const passport = require('passport');
const OAuth2Strategy = require('passport-oauth2').Strategy;
const rateLimit = require('express-rate-limit');

const app = express();
const PORT = process.env.PORT || 3000;

// Database configuration
const dbConfig = {
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'roadmap',
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0
};

// Validate critical environment variables
const requiredEnvVars = ['SESSION_SECRET'];
const missingEnvVars = requiredEnvVars.filter(varName => !process.env[varName]);

if (missingEnvVars.length > 0) {
    console.warn(`⚠️  Missing environment variables: ${missingEnvVars.join(', ')}`);
    console.warn('Application may not work correctly without these variables');
}

// Create database connection pool with error handling
let pool;
try {
    pool = mysql.createPool(dbConfig);
    console.log('Database connection pool created successfully');
} catch (error) {
    console.error('Failed to create database connection pool:', error);
    // Continue without database for now - routes will handle gracefully
}

// Middleware
app.use(express.json());
app.use(express.urlencoded({ extended: true }));
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));
app.use(express.static(path.join(__dirname, 'public')));
app.use('/dist', express.static(path.join(__dirname, 'dist')));

// Session configuration
app.use(session({
    secret: process.env.SESSION_SECRET || 'your-secret-key',
    resave: false,
    saveUninitialized: false,
    cookie: {
      secure: false, // Set to true in production with HTTPS
      maxAge: 24 * 60 * 60 * 1000 // 24 hours
    }
}));

// Passport configuration for Twitch OAuth
passport.use(new OAuth2Strategy({
    authorizationURL: 'https://id.twitch.tv/oauth2/authorize',
    tokenURL: 'https://id.twitch.tv/oauth2/token',
    clientID: process.env.TWITCH_CLIENT_ID,
    clientSecret: process.env.TWITCH_CLIENT_SECRET,
    callbackURL: process.env.TWITCH_CALLBACK_URL,
    scope: ['openid', 'user:read:email']
}, async (accessToken, refreshToken, profile, done) => {
    try {
      // Get user info from Twitch API
        const axios = require('axios');
        const userResponse = await axios.get('https://api.twitch.tv/helix/users', {
            headers: {
                'Authorization': `Bearer ${accessToken}`,
                'Client-Id': process.env.TWITCH_CLIENT_ID
            }
        });
        const user = userResponse.data.data[0];
        profile.username = user.login;
        profile.display_name = user.display_name;
        profile.email = user.email;
        profile.twitch_id = user.id;
        return done(null, profile);
    } catch (error) {
        return done(error, null);
    }
}));

passport.serializeUser((user, done) => {
    done(null, user);
});

passport.deserializeUser((user, done) => {
    done(null, user);
});

app.use(passport.initialize());
app.use(passport.session());

// Rate limiting
const limiter = rateLimit({
    windowMs: 15 * 60 * 1000, // 15 minutes
    max: 100 // limit each IP to 100 requests per windowMs
});
app.use(limiter);

// Make database pool available to routes (if available)
if (pool) {
    app.locals.db = pool;
} else {
    console.warn('Database not available - application will run in limited mode');
}

// Routes
app.use('/', require('./routes/index'));
app.use('/api', require('./routes/api'));
app.use('/auth', require('./routes/auth'));

// Error handling middleware
app.use((err, req, res, next) => {
    console.error('Application error:', err.stack);
    // Return HTML for web routes, JSON for API routes
    if (req.path.startsWith('/api/')) {
        res.status(500).json({ error: 'Something went wrong!' });
    } else {
        res.status(500).render('500', { title: 'Server Error', error: process.env.NODE_ENV === 'development' ? err.message : null });
    }
});

// 404 handler
app.use((req, res) => {
    res.status(404).render('404', { title: 'Page Not Found' });
});

// Start server
const server = app.listen(PORT, () => {
    console.log(`🚀 Roadmap app listening on port ${PORT}`);
    console.log(`📝 Environment: ${process.env.NODE_ENV || 'development'}`);
    console.log(`🗄️  Database: ${pool ? 'Connected' : 'Not available'}`);
    console.log(`🔐 Session secret: ${process.env.SESSION_SECRET ? 'Set' : 'Not set'}`);
    console.log(`🐙 Twitch OAuth: ${process.env.TWITCH_CLIENT_ID ? 'Configured' : 'Not configured'}`);
});

module.exports = app;

// Graceful server error handling
server.on('error', (err) => {
    if (err.code === 'EACCES') {
        console.error(`Permission denied: unable to bind to port ${PORT}. Use a non-privileged port (>1024) or let cPanel manage the port.`);
        process.exit(1);
    } else if (err.code === 'EADDRINUSE') {
        console.error(`Port ${PORT} is already in use.`);
        process.exit(1);
    } else {
        console.error('Server error:', err);
        process.exit(1);
    }
});