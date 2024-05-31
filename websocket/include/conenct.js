// Toggle debug mode
var debug = false;

// Parse query parameters from the URL
const queryParams = new URLSearchParams(window.location.search);
const code = queryParams.get("code");

// Check if the 'code' query parameter is provided
if (code === null) {
    console.log("No Code Provided. Alert will not work.");
} else {
    // Code is provided; proceed with the WebSocket connection
}

// Initialize Socket.IO client
var socket = io();

// Log all events to the console for debugging purposes
socket.onAny((event, ...args) => {
    if (debug) {
        console.log(`got ${event}`);
    }
});

// Handle the 'WELCOME' event from the server
socket.on('WELCOME', function(data) {
    // If no code is provided, do nothing
    if (code === null) { return; }
    
    // Respond with a message including this client's code
    socket.emit("REGISTER", { "code": code });
});

// Handle error events
socket.on('error', console.error.bind(console));

// Log received messages to the console
socket.on('message', console.log.bind(console));
