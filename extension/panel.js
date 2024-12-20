window.Twitch.ext.onAuthorized((auth) => {
    var viewerRole = auth.role;
    document.getElementById('viewer-welcome').textContent = "Hello, user, you're a: " + viewerRole + "!";
});