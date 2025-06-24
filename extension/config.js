// config.js for BotOfTheSpecter Twitch Extension
window.Twitch.ext.onAuthorized(function(auth) {
    // Load config if available
    window.Twitch.ext.configuration.onChanged(function() {
        if (window.Twitch.ext.configuration.broadcaster) {
            const config = JSON.parse(window.Twitch.ext.configuration.broadcaster.content || '{}');
            document.getElementById('enableCommands').checked = !!config.enableCommands;
        }
    });

    // Save config on form submit
    document.getElementById('configForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const config = {
            enableCommands: document.getElementById('enableCommands').checked
        };
        window.Twitch.ext.configuration.set('broadcaster', '1.0', JSON.stringify(config));
        const status = document.getElementById('saveStatus');
        status.textContent = 'Settings saved!';
        status.classList.remove('is-hidden');
        setTimeout(() => status.classList.add('is-hidden'), 2000);
    });
});
