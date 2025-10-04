document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('search-input');
    const resultsDiv = document.getElementById('search-results');
    let timeout;
    searchInput.addEventListener('input', function() {
        clearTimeout(timeout);
        const query = this.value.trim();
        if (query.length >= 3) {
            timeout = setTimeout(() => {
                fetch(`search.php?q=${encodeURIComponent(query)}&ajax=1`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.length > 0) {
                            let html = '<div class="box has-background-dark"><ul class="menu-list">';
                            const displayData = data.slice(0, 3);
                            displayData.forEach(item => {
                                html += `<li><a href="${item.page}" class="has-text-light"><strong>${item.title}</strong><br><small>${item.snippet}</small></a></li>`;
                            });
                            if (data.length > 3) {
                                html += `<li><a href="search.php?q=${encodeURIComponent(query)}" class="has-text-link"><em>See all ${data.length} results...</em></a></li>`;
                            }
                            html += '</ul></div>';
                            resultsDiv.innerHTML = html;
                            resultsDiv.style.display = 'block';
                        } else {
                            resultsDiv.style.display = 'none';
                        }
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                        resultsDiv.style.display = 'none';
                    });
            }, 300);
        } else {
            resultsDiv.style.display = 'none';
        }
    });
    // Hide results when clicking outside
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !resultsDiv.contains(e.target)) {
            resultsDiv.style.display = 'none';
        }
    });
});