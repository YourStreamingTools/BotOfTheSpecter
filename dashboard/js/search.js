function searchFunction() {
    var input = document.getElementById("searchInput");
    if (!input) return;
    var filter = input.value.toLowerCase();
    var table = document.getElementById("commandsTable");
    if (!table) return;
    var tr = table.getElementsByTagName("tr");
    for (var i = 0; i < tr.length; i++) {
        var tds = tr[i].getElementsByTagName("td");
        if (tds.length < 2) continue;
        var tdCommand = tds[0];
        var tdResponse = tds[1];
        var commandText = tdCommand.textContent || tdCommand.innerText;
        var responseText = tdResponse.textContent || tdResponse.innerText;
        if (
            commandText.toLowerCase().indexOf(filter) > -1 ||
            responseText.toLowerCase().indexOf(filter) > -1
        ) {
            tr[i].style.display = "";
        } else {
            tr[i].style.display = "none";
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var input = document.getElementById("searchInput");
    var table = document.getElementById("commandsTable");
    if (input && table) {
        var isPlaylist = table.querySelector("th .fa-music") !== null;
        if (isPlaylist) {
            input.addEventListener("keyup", function() {
                var filter = input.value.toLowerCase();
                var tr = table.getElementsByTagName("tr");
                for (var i = 0; i < tr.length; i++) {
                    var tds = tr[i].getElementsByTagName("td");
                    if (tds.length < 2) continue;
                    var tdTitle = tds[1];
                    var titleText = tdTitle.textContent || tdTitle.innerText;
                    tr[i].style.display = titleText.toLowerCase().indexOf(filter) > -1 ? "" : "none";
                }
            });
        } else {
            input.addEventListener("keyup", searchFunction);
        }
    }
});
