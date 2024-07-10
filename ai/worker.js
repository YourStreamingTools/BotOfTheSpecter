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
  
        if (!response.ok) {
          throw new Error('Error fetching AI response: ' + (await response.text()));
        }
  
        return await response.json();
      }
  
      // Function to truncate the response to fit within the character limit
      function truncateResponse(response, limit = 500) {
        if (response.length <= limit) {
          return response;
        }
        return response.substring(0, limit);
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
  
          try {
            const chatResponse = await runAI(chatPrompt);
            console.log('AI response:', chatResponse);
  
            const aiMessage = chatResponse.result?.response ?? 'Sorry, I could not understand your request.';
            const truncatedResponse = truncateResponse(aiMessage);
            console.log('truncatedResponse:', truncatedResponse);
  
            return new Response(JSON.stringify({ text: truncatedResponse }), {
              headers: { 'content-type': 'application/json' },
            });
          } catch (error) {
            console.error('Error processing request:', error);
            return new Response('Error fetching AI response', { status: 500 });
          }
        }
  
        return new Response('Method Not Allowed', { status: 405 });
      }
  
      // Default response
      return new Response('Not found', { status: 404 });
    }
  };