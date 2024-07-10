export default {
    async fetch(request, env) {
      const url = new URL(request.url);
      const path = url.pathname;
  
      // Helper function to handle AI responses
      async function runAI(payload) {
        const response = await fetch(`https://api.cloudflare.com/client/v4/accounts/${env.ACCOUNT_ID}/ai/run/@cf/meta/llama-2-7b-chat-int8`, {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${env.API_TOKEN}`,
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload)
        });
        return await response.json();
      }
  
      // Handle requests at the base path "/"
      if (path === '/') {
        if (request.method === 'GET') {
          return new Response('SpecterAI is running.', { status: 200 });
        }
  
        if (request.method === 'POST') {
          let body;
          try {
            body = await request.json();
          } catch (e) {
            return new Response('Bad Request: Invalid JSON', { status: 400 });
          }
  
          const userMessage = body.message;
          const chatPrompt = {
            messages: [
              { role: 'system', content: 'You are SpecterAI, an advanced AI designed to interact with users on Twitch by answering their questions and providing information.' },
              { role: 'user', content: userMessage }
            ]
          };
          const chatResponse = await runAI(chatPrompt);
  
          return new Response(JSON.stringify(chatResponse), {
            headers: { 'content-type': 'application/json' },
          });
        }
  
        return new Response('Method Not Allowed', { status: 405 });
      }
  
      // Default response
      return new Response('Not found', { status: 404 });
    }
  };  