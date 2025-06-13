// Dashboard JavaScript functionality

document.addEventListener('DOMContentLoaded', function() {
    // Navbar burger menu toggle for mobile
    const navbarBurgers = Array.prototype.slice.call(document.querySelectorAll('.navbar-burger'), 0);
    if (navbarBurgers.length > 0) {
        navbarBurgers.forEach(el => {
            el.addEventListener('click', () => {
                const target = document.getElementById(el.dataset.target);
                el.classList.toggle('is-active');
                target.classList.toggle('is-active');
            });
        });
    }
    
    // Close notification buttons
    const closeButtons = Array.prototype.slice.call(document.querySelectorAll('.notification .delete'), 0);
    closeButtons.forEach(button => {
        const notification = button.parentNode;
        button.addEventListener('click', () => {
            notification.parentNode.removeChild(notification);
        });
    });
    
    // Bot selector dropdown functionality
    const botSelectorDropdown = document.getElementById('botSelector');
    if (botSelectorDropdown) {
        const trigger = botSelectorDropdown.querySelector('.dropdown-trigger');
        trigger.addEventListener('click', () => {
            botSelectorDropdown.classList.toggle('is-active');
        });
        
        // Close dropdown when clicking elsewhere on the page
        document.addEventListener('click', (event) => {
            if (!botSelectorDropdown.contains(event.target)) {
                botSelectorDropdown.classList.remove('is-active');
            }
        });
        
        // Bot selection handling
        const botOptions = document.querySelectorAll('.bot-option');
        botOptions.forEach(option => {
            option.addEventListener('click', (e) => {
                e.preventDefault();
                
                // Update selected bot text
                document.getElementById('selectedBot').textContent = option.textContent;
                
                // Remove active class from all options
                botOptions.forEach(opt => opt.classList.remove('is-active'));
                
                // Add active class to selected option
                option.classList.add('is-active');
                
                // Close dropdown
                botSelectorDropdown.classList.remove('is-active');
                
                // Redirect to the bot page with the selected bot
                window.location.href = `bot.php?bot=${option.dataset.value}`;
            });
        });
    }
    
    // Status control buttons
    const forceOnlineBtn = document.getElementById('forceOnline');
    const forceOfflineBtn = document.getElementById('forceOffline');
    
    if (forceOnlineBtn) {
        forceOnlineBtn.addEventListener('click', function() {
            const botStatus = document.getElementById('botStatus');
            sendStreamEvent('STREAM_ONLINE');
            botStatus.textContent = 'STATUS: ONLINE';
            botStatus.style.color = '#24c760';
        });
    }
    
    if (forceOfflineBtn) {
        forceOfflineBtn.addEventListener('click', function() {
            const botStatus = document.getElementById('botStatus');
            sendStreamEvent('STREAM_OFFLINE');
            botStatus.textContent = 'STATUS: OFFLINE';
            botStatus.style.color = '#e9d96c';
        });
    }
    
    // Function to send a stream event
    function sendStreamEvent(eventType) {
        fetch('notify_event.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `event=${eventType}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log(`${eventType} event sent successfully`);
            } else {
                console.error(`Failed to send ${eventType} event: ${data.message}`);
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }
});

// Function to create toast notifications
function showNotification(message, type = 'info', duration = 3000) {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification is-${type} is-light`;
    notification.style.position = 'fixed';
    notification.style.top = '1rem';
    notification.style.right = '1rem';
    notification.style.zIndex = '9999';
    notification.style.minWidth = '300px';
    notification.style.maxWidth = '500px';
    notification.style.opacity = '0';
    notification.style.transition = 'opacity 0.3s ease-in-out';
    
    // Add close button
    const closeButton = document.createElement('button');
    closeButton.className = 'delete';
    closeButton.addEventListener('click', () => {
        notification.style.opacity = '0';
        setTimeout(() => {
            document.body.removeChild(notification);
        }, 300);
    });
    
    // Add message
    const messageElement = document.createElement('p');
    messageElement.textContent = message;
    
    // Assemble notification
    notification.appendChild(closeButton);
    notification.appendChild(messageElement);
    
    // Add to document
    document.body.appendChild(notification);
    
    // Trigger animation
    setTimeout(() => {
        notification.style.opacity = '1';
    }, 10);
    
    // Auto-remove after duration
    setTimeout(() => {
        notification.style.opacity = '0';
        setTimeout(() => {
            if (notification.parentNode) {
                document.body.removeChild(notification);
            }
        }, 300);
    }, duration);
}
