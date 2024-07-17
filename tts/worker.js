addEventListener('fetch', event => {
    event.respondWith(handleRequest(event.request, event))
  })
  
  const ttsStore = new Map()
  
  async function handleRequest(request, env) {
    const { pathname } = new URL(request.url)
    const channelMatch = pathname.match(/^\/([^\/]+)/)
  
    if (request.method === 'POST' && channelMatch) {
      const channelName = channelMatch[1]
      let requestBody
      try {
        requestBody = await request.json()
      } catch (e) {
        return new Response('Invalid JSON', { status: 400 })
      }
  
      const text = requestBody.text
      if (!text) {
        return new Response(JSON.stringify({ error: 'Text field is required' }), {
          status: 400,
          headers: { 'Content-Type': 'application/json' },
        })
      }
  
      // Fetch TTS
      const ttsResponse = await fetch('https://texttospeech.googleapis.com/v1/text:synthesize', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${env.GOOGLE_CLOUD_API_KEY}`,
        },
        body: JSON.stringify({
          input: { text },
          voice: { languageCode: 'en-US', name: 'en-US-Wavenet-D' },
          audioConfig: { audioEncoding: 'MP3' },
        }),
      })
  
      if (!ttsResponse.ok) {
        return new Response(JSON.stringify({ error: 'Failed to fetch TTS data' }), {
          status: 500,
          headers: { 'Content-Type': 'application/json' },
        })
      }
  
      const ttsData = await ttsResponse.json()
      const audioContent = ttsData.audioContent
  
      // Store the audio content in the map
      ttsStore.set(channelName, audioContent)
  
      return new Response(
        JSON.stringify({ message: 'TTS data stored successfully' }),
        {
          headers: { 'Content-Type': 'application/json' },
        }
      )
    } else if (request.method === 'GET' && channelMatch) {
      const channelName = channelMatch[1]
  
      if (pathname.endsWith('/audio')) {
        // Retrieve the audio content for the channel
        const audioContent = ttsStore.get(channelName)
        if (!audioContent) {
          return new Response(JSON.stringify({ message: 'No audio available' }), {
            status: 404,
            headers: { 'Content-Type': 'application/json' },
          })
        }
  
        // Clear the audio content after fetching to avoid repeated playback
        ttsStore.delete(channelName)
  
        return new Response(
          JSON.stringify({ audioContent }),
          {
            headers: { 'Content-Type': 'application/json' },
          }
        )
      } else {
        // Serve the HTML page for the given channel
        return new Response(`
          <!DOCTYPE html>
          <html lang="en">
          <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>TTS Player for ${channelName}</title>
          </head>
          <body>
            <h1>Channel: ${channelName}</h1>
            <audio id="audioPlayer" controls></audio>
  
            <script>
              const channelName = '${channelName}'
              const pollInterval = 5000 // Poll every 5 seconds
  
              async function fetchAndPlayAudio() {
                try {
                  const response = await fetch(\`https://\${location.host}/\${channelName}/audio\`)
                  if (!response.ok) {
                    console.log('No new audio')
                    return
                  }
  
                  const data = await response.json()
                  if (data.audioContent) {
                    const audioPlayer = document.getElementById('audioPlayer')
                    audioPlayer.src = 'data:audio/mp3;base64,' + data.audioContent
                    audioPlayer.play()
                  }
                } catch (error) {
                  console.error('Error fetching audio:', error)
                }
              }
  
              setInterval(fetchAndPlayAudio, pollInterval)
            </script>
          </body>
          </html>
        `, {
          headers: { 'Content-Type': 'text/html' }
        })
      }
    } else {
      return new Response('Not found', { status: 404 })
    }
  }