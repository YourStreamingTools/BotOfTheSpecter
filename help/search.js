document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('search-input');
    const resultsDiv = document.getElementById('search-results');
    if (!searchInput || !resultsDiv) {
        return;
    }
    let timeout;
    let abortController = null;
    searchInput.addEventListener('input', function() {
        clearTimeout(timeout);
        const query = this.value.trim();
        if (abortController) {
            abortController.abort();
            abortController = null;
        }
        if (query.length >= 3) {
            timeout = setTimeout(() => {
                abortController = new AbortController();
                fetch(`search.php?q=${encodeURIComponent(query)}&ajax=1`, { signal: abortController.signal })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`Search request failed: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (searchInput.value.trim() !== query) {
                            return;
                        }
                        if (!Array.isArray(data)) {
                            resultsDiv.style.display = 'none';
                            return;
                        }
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
                            resultsDiv.innerHTML = '';
                            resultsDiv.style.display = 'none';
                        }
                    })
                    .catch(error => {
                        if (error.name === 'AbortError') {
                            return;
                        }
                        console.error('Search error:', error);
                        resultsDiv.innerHTML = '';
                        resultsDiv.style.display = 'none';
                    });
            }, 300);
        } else {
            resultsDiv.innerHTML = '';
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