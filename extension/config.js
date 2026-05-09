// config.js for BotOfTheSpecter Twitch Extension
// Currently no broadcaster-configurable settings exist; this file is a graceful no-op
// that still registers the auth handler so the extension reports as configured.
window.Twitch.ext.onAuthorized(function(auth) {
    // Future: read configuration and populate any form controls here.
    window.Twitch.ext.configuration.onChanged(function() {
        // Future: handle config changes here.
    });
});
