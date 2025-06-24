window.Twitch.ext.onAuthorized((auth) => {
    var viewerRole = auth.role;
    var channelId = auth.channelId;
    var userId = auth.userId;

    // Helper to check if userId is a real Twitch user ID (all digits)
    function isRealTwitchUserId(id) {
        return /^\d+$/.test(id);
    }

    // Display role, user ID, and username (if available)
    const infoDiv = document.createElement('div');
    infoDiv.className = 'notification is-info';
    let userIdDisplay = userId ? userId : 'Not shared yet';
    let usernameDisplay = '<span id="twitch-username">';
    if (!userId) {
        usernameDisplay += 'Not shared yet';
    } else if (!isRealTwitchUserId(userId)) {
        usernameDisplay += 'Not available until you allow identity sharing in the Twitch extension panel settings.';
    } else {
        usernameDisplay += 'Loading...';
    }
    usernameDisplay += '</span>';
    infoDiv.innerHTML = `<strong>Role:</strong> ${viewerRole}<br><strong>User ID:</strong> ${userIdDisplay}<br><strong>Username:</strong> ${usernameDisplay}`;
    document.body.appendChild(infoDiv);

    // Only fetch username if userId is a real Twitch user ID
    if (userId && isRealTwitchUserId(userId)) {
        fetch(`https://api.twitch.tv/helix/users?id=${userId}`, {
            headers: {
                'Client-ID': '235p5vscijus08oac5gtwkx1gvzvs1',
                'Authorization': `Bearer ${auth.token}`
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.data && data.data.length > 0) {
                document.getElementById('twitch-username').textContent = data.data[0].display_name;
            } else {
                document.getElementById('twitch-username').textContent = 'Unknown';
            }
        })
        .catch(() => {
            document.getElementById('twitch-username').textContent = 'Error';
        });
    }

    // Create a container for dynamic content
    const dynamicContainer = document.createElement('div');
    dynamicContainer.id = 'dynamic-content';
    document.body.appendChild(dynamicContainer);
    // Create options/buttons like the members page
    const options = [
        { key: 'commands', label: 'Custom Commands', enabled: true },
        { key: 'lurkers', label: 'Lurkers', enabled: true },
        { key: 'typos', label: 'Typo Counts', enabled: true },
        { key: 'deaths', label: 'Deaths Overview', enabled: true },
        { key: 'hugs', label: 'Hug Counts', enabled: true },
        { key: 'kisses', label: 'Kiss Counts', enabled: true },
        { key: 'highfives', label: 'High-Five Counts', enabled: true },
        { key: 'custom', label: 'Custom Counts', enabled: true },
        { key: 'userCounts', label: 'User Counts', enabled: true },
        { key: 'rewardCounts', label: 'Reward Counts', enabled: true },
        { key: 'watchTime', label: 'Watch Time', enabled: true },
        { key: 'quotes', label: 'Quotes', enabled: true },
        { key: 'todos', label: 'To-Do Items', enabled: true }
    ];
    const btnContainer = document.createElement('div');
    btnContainer.className = 'buttons is-centered mb-4';
    options.forEach(opt => {
        const btn = document.createElement('button');
        btn.className = 'button is-info';
        btn.textContent = opt.label;
        btn.onclick = () => {
            // Clear previous content
            dynamicContainer.innerHTML = '';
            if (opt.key === 'commands') {
                // --- API call for commands (commented out for now) ---
                /*
                fetch(`https://apit.botofthespecter.com/extention/commands?channel_id=${channelId}`)
                    .then(response => response.json())
                    .then(data => {
                        // Display custom commands here
                    })
                    .catch(err => {
                        // Handle error
                    });
                */
                dynamicContainer.innerHTML = '<h3>Custom Commands</h3><p>API call will be enabled when ready.</p>';
            } else if (opt.key === 'lurkers') {
                // --- API call for lurkers (commented out for now) ---
                /*
                fetch(`https://apit.botofthespecter.com/extention/lurkers?channel_id=${channelId}`)
                    .then(response => response.json())
                    .then(data => {
                        // Display lurkers here
                    })
                    .catch(err => {
                        // Handle error
                    });
                */
                dynamicContainer.innerHTML = '<h3>Lurkers</h3><p>API call will be enabled when ready.</p>';
            } else if (opt.key === 'typos') {
                /*
                fetch(`https://apit.botofthespecter.com/extention/typos?channel_id=${channelId}`)
                    .then(response => response.json())
                    .then(data => {
                        // Display typos here
                    })
                    .catch(err => {
                        // Handle error
                    });
                */
                dynamicContainer.innerHTML = '<h3>Typo Counts</h3><p>API call will be enabled when ready.</p>';
            } else if (opt.key === 'deaths') {
                /*
                fetch(`https://apit.botofthespecter.com/extention/deaths?channel_id=${channelId}`)
                    .then(response => response.json())
                    .then(data => {
                        // Display deaths here
                    })
                    .catch(err => {
                        // Handle error
                    });
                */
                dynamicContainer.innerHTML = '<h3>Deaths Overview</h3><p>API call will be enabled when ready.</p>';
            } else if (opt.key === 'hugs') {
                /*
                fetch(`https://apit.botofthespecter.com/extention/hugs?channel_id=${channelId}`)
                    .then(response => response.json())
                    .then(data => {
                        // Display hugs here
                    })
                    .catch(err => {
                        // Handle error
                    });
                */
                dynamicContainer.innerHTML = '<h3>Hug Counts</h3><p>API call will be enabled when ready.</p>';
            } else if (opt.key === 'kisses') {
                /*
                fetch(`https://apit.botofthespecter.com/extention/kisses?channel_id=${channelId}`)
                    .then(response => response.json())
                    .then(data => {
                        // Display kisses here
                    })
                    .catch(err => {
                        // Handle error
                    });
                */
                dynamicContainer.innerHTML = '<h3>Kiss Counts</h3><p>API call will be enabled when ready.</p>';
            } else if (opt.key === 'highfives') {
                /*
                fetch(`https://apit.botofthespecter.com/extention/highfives?channel_id=${channelId}`)
                    .then(response => response.json())
                    .then(data => {
                        // Display highfives here
                    })
                    .catch(err => {
                        // Handle error
                    });
                */
                dynamicContainer.innerHTML = '<h3>High-Five Counts</h3><p>API call will be enabled when ready.</p>';
            } else if (opt.key === 'custom') {
                /*
                fetch(`https://apit.botofthespecter.com/extention/custom?channel_id=${channelId}`)
                    .then(response => response.json())
                    .then(data => {
                        // Display custom counts here
                    })
                    .catch(err => {
                        // Handle error
                    });
                */
                dynamicContainer.innerHTML = '<h3>Custom Counts</h3><p>API call will be enabled when ready.</p>';
            } else if (opt.key === 'userCounts') {
                /*
                fetch(`https://apit.botofthespecter.com/extention/userCounts?channel_id=${channelId}`)
                    .then(response => response.json())
                    .then(data => {
                        // Display user counts here
                    })
                    .catch(err => {
                        // Handle error
                    });
                */
                dynamicContainer.innerHTML = '<h3>User Counts</h3><p>API call will be enabled when ready.</p>';
            } else if (opt.key === 'rewardCounts') {
                /*
                fetch(`https://apit.botofthespecter.com/extention/rewardCounts?channel_id=${channelId}`)
                    .then(response => response.json())
                    .then data => {
                        // Display reward counts here
                    })
                    .catch(err => {
                        // Handle error
                    });
                */
                dynamicContainer.innerHTML = '<h3>Reward Counts</h3><p>API call will be enabled when ready.</p>';
            } else if (opt.key === 'watchTime') {
                /*
                fetch(`https://apit.botofthespecter.com/extention/watchTime?channel_id=${channelId}`)
                    .then(response => response.json())
                    .then(data => {
                        // Display watch time here
                    })
                    .catch(err => {
                        // Handle error
                    });
                */
                dynamicContainer.innerHTML = '<h3>Watch Time</h3><p>API call will be enabled when ready.</p>';
            } else if (opt.key === 'quotes') {
                /*
                fetch(`https://apit.botofthespecter.com/extention/quotes?channel_id=${channelId}`)
                    .then(response => response.json())
                    .then(data => {
                        // Display quotes here
                    })
                    .catch(err => {
                        // Handle error
                    });
                */
                dynamicContainer.innerHTML = '<h3>Quotes</h3><p>API call will be enabled when ready.</p>';
            } else if (opt.key === 'todos') {
                /*
                fetch(`https://apit.botofthespecter.com/extention/todos?channel_id=${channelId}`)
                    .then(response => response.json())
                    .then(data => {
                        // Display todos here
                    })
                    .catch(err => {
                        // Handle error
                    });
                */
                dynamicContainer.innerHTML = '<h3>To-Do Items</h3><p>API call will be enabled when ready.</p>';
            }
        };
        btnContainer.appendChild(btn);
    });
    document.body.appendChild(btnContainer);
    // Add a button to go to the members page
    const membersBtn = document.createElement('a');
    membersBtn.href = 'https://members.botofthespecter.com/';
    membersBtn.className = 'button is-link mt-4';
    membersBtn.textContent = 'Go to Members Page';
    document.body.appendChild(membersBtn);

    // Utility to decode JWT and extract user_id (TUID)
    function getUserID(token) {
      try {
        const payload = JSON.parse(atob(token.split('.')[1]));
        return payload.user_id;
      } catch (e) {
        return null;
      }
    }

    const tuid = getUserID(auth.token);
    // Use broadcaster ID for deterministic A/B split
    const broadcasterId = auth.channelId;
    if (parseInt(broadcasterId) % 2 === 0) {
      // Even: show green button, hide red
      document.getElementById('redButton').style.display = 'none';
      document.getElementById('greenButton').style.display = 'block';
    } else {
      // Odd: show red button, hide green
      document.getElementById('redButton').style.display = 'block';
      document.getElementById('greenButton').style.display = 'none';
    }

    // Example: Track button clicks (replace with your analytics or backend call)
    document.getElementById('redButton').onclick = function() {
      console.log('Red button clicked');
      // TODO: send event to analytics/backend
    };
    document.getElementById('greenButton').onclick = function() {
      console.log('Green button clicked');
      // TODO: send event to analytics/backend
    };
});