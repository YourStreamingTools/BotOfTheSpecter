function showOBSInfo() {
    // Create a new HTML element to display the popup content
    var popup = document.createElement("div");
    popup.innerHTML = "<p>The ToDo List is fully compatible with any streaming software:<br>OBS, SLOBS, xSplit, Wirecast, etc.</p>" +
                      "<p>All you have to do it add the following link followed by your API key on the profile page into a browser source and it works:<br><code>https://overlay.botofthespecter.com/todolist.php?code=API_KEY_HERE</code></p>" +
                      "<p>If you wish to define a working category please add it like this:<br><code>todolist.php?code=API_KEY_HERE&category=1</code><br>(where ID 1 is called Default defined on the <a href='categories.php'>categories</a> page.</p>";

    // Apply dark mode CSS styles to the popup
    popup.style.position = "fixed";
    popup.style.top = "50%";
    popup.style.left = "50%";
    popup.style.transform = "translate(-50%, -50%)";
    popup.style.width = "600px";
    popup.style.padding = "20px";
    popup.style.backgroundColor = "#444444";
    popup.style.color = "#ffffff";
    popup.style.borderRadius = "5px";
    popup.style.boxShadow = "0 0 10px rgba(0, 0, 0, 0.5)";
    popup.style.zIndex = "9999";

    // Style the links for dark mode
    var links = popup.querySelectorAll('a');
    links.forEach(function(link) {
        link.style.color = "#3498DB";
    });

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