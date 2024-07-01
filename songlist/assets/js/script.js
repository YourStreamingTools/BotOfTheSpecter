function loadContent(page) {
    const urlParams = new URLSearchParams(window.location.search);
    const username = urlParams.get('username') || '';

    if (page === 'songs') {
        $.ajax({
            url: `../songlistapi/get_songs.php?username=${username}`,
            method: 'GET',
            success: function(data) {
                const songs = JSON.parse(data);
                let content = `
                    <h1 class="title">Song List</h1>
                    <table class="table is-fullwidth">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Artist</th>
                                <th>YouTube Link</th>
                            </tr>
                        </thead>
                        <tbody>`;
                songs.forEach(song => {
                    content += `
                        <tr>
                            <td>${song.title}</td>
                            <td>${song.artist}</td>
                            <td>${song.youtube_id ? `<a href="https://www.youtube.com/watch?v=${song.youtube_id}" target="_blank">Watch</a>` : 'N/A'}</td>
                        </tr>`;
                });
                content += `
                        </tbody>
                    </table>`;
                $('#content').html(content);
            }
        });
    } else if (page === 'add_song') {
        const content = `
            <h1 class="title">Add Song</h1>
            <form id="addSongForm">
                <div class="field">
                    <label class="label">Song Title</label>
                    <div class="control">
                        <input class="input" type="text" name="title" placeholder="Song Title" required>
                    </div>
                </div>
                <div class="field">
                    <label class="label">Artist</label>
                    <div class="control">
                        <input class="input" type="text" name="artist" placeholder="Artist" required>
                    </div>
                </div>
                <div class="field">
                    <label class="label">YouTube Link</label>
                    <div class="control">
                        <input class="input" type="url" name="youtube_url" placeholder="YouTube Link">
                    </div>
                </div>
                <div class="field">
                    <div class="control">
                        <button class="button is-primary" type="submit">Add Song</button>
                    </div>
                </div>
            </form>`;
        $('#content').html(content);

        $('#addSongForm').on('submit', function(e) {
            e.preventDefault();
            $.ajax({
                url: `../songlistapi/add_song.php?username=${username}`,
                method: 'POST',
                data: $(this).serialize(),
                success: function(data) {
                    const response = JSON.parse(data);
                    if (response.status === 'success') {
                        alert('Song added successfully!');
                        loadContent('songs');
                    }
                }
            });
        });
    }
}

$(document).ready(function() {
    loadContent('songs');
});
