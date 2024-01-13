function showPopup() {
    // Create a new HTML element to display the popup content
    var popup = document.createElement("div");
    popup.innerHTML =   "<h3>About BotOfTheSpecter</h3>" +
                        "<p>BotOfTheSpecter is an advanced Twitch bot designed to enhance your streaming experience, offering a suite of tools for community interaction, channel management, and analytics.</p>" +
                        "<p>Join our vibrant community and take your streaming to the next level. Get support, share tips, and engage with fellow enthusiasts on our <a href='https://discord.com/invite/ANwEkpauHJ' target='_blank'>Discord server</a>.</p>" +
                        "<p>Interested in the technical side? Explore our features and contribute on our <a href='https://github.com/YourStreamingTools/BotOfTheSpecter' target='_blank'>GitHub page</a>.</p>";
  
    // Apply some CSS styles to the popup
    popup.style.position = "fixed";
    popup.style.top = "50%";
    popup.style.left = "50%";
    popup.style.transform = "translate(-50%, -50%)";
    popup.style.width = "400px";
    popup.style.padding = "20px";
    popup.style.backgroundColor = "#222222";
    popup.style.borderRadius = "5px";
    popup.style.boxShadow = "0 0 10px rgba(0, 0, 0, 0.5)";
    popup.style.zIndex = "9999";
  
    // Create a close button for the popup
    var closeButton = document.createElement("button");
    closeButton.innerHTML = "Close";
    closeButton.style.marginTop = "10px";
    closeButton.style.padding = "5px 10px";
    closeButton.style.backgroundColor = "#dc3545";
    closeButton.style.color = "#ffffff";
    closeButton.style.borderRadius = "5px";
    closeButton.style.border = "none";
    closeButton.style.cursor = "pointer";
  
    // Add the close button to the popup element
    popup.appendChild(closeButton);
  
    // Add an event listener to the close button to remove the popup when clicked
    closeButton.addEventListener("click", function() {
      popup.parentNode.removeChild(popup);
    });
  
    // Add the popup element to the body of the HTML document
    document.body.appendChild(popup);
  }