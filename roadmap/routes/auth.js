const express = require('express');
const passport = require('passport');
const router = express.Router();

// Twitch OAuth routes
router.get('/twitch',
  passport.authenticate('oauth2', { scope: ['openid', 'user:read:email'] })
);

router.get('/twitch/callback',
  passport.authenticate('oauth2', { failureRedirect: '/login' }),
  (req, res) => {
    // Successful authentication
    req.session.username = req.user.username;
    req.session.display_name = req.user.display_name;
    req.session.twitch_id = req.user.twitch_id;
    req.session.admin = false; // Set admin status based on your logic

    res.redirect('/');
  }
);

// Login status API
router.get('/status', (req, res) => {
  res.json({
    logged_in: !!(req.session && req.session.username),
    username: req.session ? req.session.username : null,
    display_name: req.session ? req.session.display_name : null,
    admin: !!(req.session && req.session.admin),
    twitch_id: req.session ? req.session.twitch_id : null
  });
});

module.exports = router;