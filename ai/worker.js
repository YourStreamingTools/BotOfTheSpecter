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

    // Function to remove formatting from the text
    function removeFormatting(text) {
      return text
        .replace(/\*\*|__/g, '') // Remove bold and italics markdown
        .replace(/<[^>]+>/g, '') // Remove HTML tags
        .replace(/\n/g, ' ');    // Replace line breaks with spaces
    }

    // Function to truncate the response to fit within the character limit
    function truncateResponse(response, limit = 500) {
      if (response.length <= limit) {
        return response;
      }
      return response.substring(0, limit);
    }

    // Normalize the user message
    function normalizeMessage(message) {
      return message.toLowerCase().replace(/[^a-z0-9 ]/g, '').trim();
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

        const userMessage = normalizeMessage(body.message);

        // Custom responses for specific questions
        const predefinedResponses = {
          "who built you": "gfaUnDead has hand-coded me using Python. My current project file is over 4.5k lines of code to make up my entire system. In addition to this, gfaUnDead has spent the last 2 months getting my AI code ready. I'm connected and trained by hand and have points of interest with the large language model (LLM) LLAMA-2.",
          // Add more predefined responses here
        };

        if (predefinedResponses[userMessage]) {
          return new Response(predefinedResponses[userMessage], {
            headers: { 'content-type': 'text/plain' },
          });
        }

        const chatPrompt = {
          messages: [
            { role: 'system', content: 'You are SpecterAI, an advanced AI designed to interact with users on Twitch by answering their questions and providing information. Keep your responses concise and ensure they are no longer than 500 characters.' },
            { role: 'user', content: body.message }
          ]
        };

        try {
          const chatResponse = await runAI(chatPrompt);
          console.log('AI response:', chatResponse);

          let aiMessage = chatResponse.result?.response ?? 'Sorry, I could not understand your request.';
          aiMessage = removeFormatting(aiMessage);
          aiMessage = truncateResponse(aiMessage);
          console.log('Formatted and truncated response:', aiMessage);

          return new Response(aiMessage, {
            headers: { 'content-type': 'text/plain' },
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