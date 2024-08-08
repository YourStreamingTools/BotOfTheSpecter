addEventListener('fetch', event => {
    event.respondWith(handleRequest(event.request))
})

async function handleRequest(request) {
    const url = new URL(request.url)
    if (url.pathname === '/heartbeat') {
        try {
            const response = await fetch('https://websocket.botofthespecter.com:8080/heartbeat', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                }
            })
            if (!response.ok) {
                throw new Error(`Network response was not ok: ${response.statusText}`)
            }
            const data = await response.json()
            return new Response(JSON.stringify(data), {
                headers: { 'Content-Type': 'application/json' }
            })
        
        } catch (error) {
            return new Response(JSON.stringify({ status: 'NOT_OK', error: error.message }), {
                headers: { 'Content-Type': 'application/json' },
                status: 500
            })
        }
    }
    return new Response('Not found', { status: 404 })
}
