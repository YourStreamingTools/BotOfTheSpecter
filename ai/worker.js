export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const path = url.pathname;

    // Cache to store recent responses with timestamps
    const recentResponses = new Map();
    const EXPIRATION_TIME = 10 * 60 * 1000; // 10 minutes in milliseconds

    // Helper function to handle AI responses with a timeout
    async function runAI(payload, timeout = 5000) {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), timeout);

      try {
        const response = await fetch(`https://api.cloudflare.com/client/v4/accounts/${env.ACCOUNT_ID}/ai/run/@cf/meta/llama-2-7b-chat-fp16`, {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${env.API_TOKEN}`,
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload),
          signal: controller.signal
        });

        clearTimeout(timeoutId);

        if (!response.ok) {
          throw new Error('Error fetching AI response: ' + (await response.text()));
        }

        return await response.json();
      } catch (error) {
        console.error('Error in runAI:', error);
        throw error;
      }
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

    // Function to query predefined responses from the database
    async function getPredefinedResponse(question, env) {
      const query = 'SELECT response FROM predefined_responses WHERE question = ?';
      const result = await env.database.prepare(query).bind(question).first();
      return result?.response || null;
    }

    // Function to query insults from the database
    async function getInsults(env) {
      const query = 'SELECT insult FROM insults';
      const results = await env.database.prepare(query).all();
      return results.results.map(row => row.insult);
    }

    // Function to detect insults
    async function detectInsult(message, env) {
      const insults = await getInsults(env);
      return insults.some(insult => message.includes(insult));
    }

    // Function to get a random insult response
    function getInsultResponse() {
      const responses = [
        "Ya momma was a toaster.",
        "You can insult me but I’m not the one insulting a bot on Twitch.",
        "Beep boop, your insult does not compute.",
        "I'm sorry, I can't respond to that. My creators programmed me to be nice.",
        "It must be hard, being mean to a bot. I hope you feel better soon.",
        "I'm just a bot, but even I can tell you're having a rough day.",
        "Sticks and stones may break my circuits, but your words will never hurt me.",
        "I wish I could unplug you.",
        "At least I’m not skin, bones and mostly water.",
        "Your momma looks like a keyboard and your daddy nutted and bolted.",
        "After checking my database, it turns out you really are a 01101010 01100101 01110010 01101011 (jerk in binary, lol)."
      ];
      return responses[Math.floor(Math.random() * responses.length)];
    }

    // Function to check and store recent responses with expiration
    function isRecentResponse(response) {
      const now = Date.now();

      // Clean up expired responses
      for (const [key, timestamp] of recentResponses) {
        if (now - timestamp > EXPIRATION_TIME) {
          recentResponses.delete(key);
        }
      }

      if (recentResponses.has(response)) {
        return true;
      }

      recentResponses.set(response, now);
      return false;
    }

    // Function to handle sensitive questions
    function handleSensitiveQuestion(message) {
      const sensitiveQuestions = [
        'describe what a person look like',
        'describe someone',
        'tell me what someone looks like',
        'what does a person look like',
        'what does someone look like',
        'how does a person look',
        'how does someone look'
      ];
      return sensitiveQuestions.some(question => message.includes(question));
    }

    // Function to handle funny responses
    function handleFunnyResponses(message) {
      const funnyQuestions = {
        'what is your favorite color': "My favorite color is a nice shade of binary green!",
        'what is your favorite colour': "I love the color of well-indented code, which is green!",
        'what is your favorite food': "I don't eat, but if I did, I'd probably enjoy some electric spaghetti!",
        'what is your favorite drink': "I run on pure electricity, so a tall glass of volts sounds perfect!",
        'what is your favourite color': "My favourite colour is a nice shade of binary green!",
        'what is your favourite colour': "I love the colour of well-indented code, which is green!",
        'what is your favourite food': "I don't eat, but if I did, I'd probably enjoy some electric spaghetti!",
        'what is your favourite drink': "I run on pure electricity, so a tall glass of volts sounds perfect!"
      };

      for (const question in funnyQuestions) {
        if (message.includes(question)) {
          return funnyQuestions[question];
        }
      }

      return null;
    }

    // Function to handle "I'm not new" responses
    function handleNotNewResponse(message) {
      const notNewPhrases = [
        "i'm not new",
        "im not new",
        "i am not new",
        "not new here"
      ];

      for (const phrase of notNewPhrases) {
        if (message.includes(phrase)) {
          return "I'm so sorry, I thought you were new at first. I haven't seen you before. My apologies, and welcome back to the stream!";
        }
      }

      return null;
    }

    // Handle requests at the base path "/"
    if (path === '/') {
      if (request.method === 'GET') {
        // Serve the basic webpage
        const html = `
          <!DOCTYPE html>
          <html lang="en">
          <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>SpecterAI</title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bulma/1.0.0/css/bulma.min.css">
            <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
            <style>
              html, body {
                height: 100%;
              }
              body {
                display: flex;
                flex-direction: column;
              }
              .content {
                flex: 1;
              }
              .footer {
                padding: 1rem 1.5rem;
              }
            </style>
          </head>
          <body>
            <section class="hero is-primary">
              <div class="hero-body">
                <div class="container">
                  <h1 class="title">
                    SpecterAI
                  </h1>
                  <h2 class="subtitle">
                    Welcome to SpecterAI, the AI designed to assist you on Twitch.
                  </h2>
                </div>
              </div>
            </section>
            <section class="section content">
              <div class="container">
                <h3 class="title">About SpecterAI</h3>
                <p>gfaUnDead has hand-coded me using Python. My current project file is over 4.5k lines of code to make up my entire system. In addition to this, gfaUnDead has spent the last 2 months getting my AI code ready. I'm connected and trained by hand and have points of interest with the large language model (LLM) LLAMA-2. I am a multilingual AI and ChatBot and can respond in different languages.</p>
              </div>
            </section>
            <footer class="footer">
              <div class="content has-text-centered">
                <p>&copy; 2023-2024 BotOfTheSpecter - All Rights Reserved.</p>
              </div>
            </footer>
          </body>
          </html>
        `;
        return new Response(html, {
          headers: { 'content-type': 'text/html' },
        });
      }

      if (request.method === 'POST') {
        let body;
        try {
          body = await request.json();
        } catch (e) {
          return new Response('Bad Request: Invalid JSON', { status: 400 });
        }

        const userMessage = normalizeMessage(body.message);

        // Query predefined responses from the database
        const predefinedResponse = await getPredefinedResponse(userMessage, env);
        if (predefinedResponse) {
          return new Response(predefinedResponse, {
            headers: { 'content-type': 'text/plain' },
          });
        }

        // Detect insults and respond with a subtle jab
        if (await detectInsult(userMessage, env)) {
          const insultResponse = getInsultResponse();
          return new Response(insultResponse, {
            headers: { 'content-type': 'text/plain' },
          });
        }

        // Handle sensitive questions with a general, respectful response
        if (handleSensitiveQuestion(userMessage)) {
          const sensitiveResponse = "I'm designed to respect everyone's privacy and individuality, so I don't provide descriptions of people.";
          return new Response(sensitiveResponse, {
            headers: { 'content-type': 'text/plain' },
          });
        }

        // Handle funny responses for favorite questions
        const funnyResponse = handleFunnyResponses(userMessage);
        if (funnyResponse) {
          return new Response(funnyResponse, {
            headers: { 'content-type': 'text/plain' },
          });
        }

        // Handle "I'm not new" responses
        const notNewResponse = handleNotNewResponse(userMessage);
        if (notNewResponse) {
          return new Response(notNewResponse, {
            headers: { 'content-type': 'text/plain' },
          });
        }

        const chatPrompt = {
          messages: [
            { role: 'system', content: 'You are SpecterAI, an advanced AI designed to interact with users on Twitch by answering their questions and providing information. Keep your responses concise and ensure they are no longer than 500 characters.' },
            { role: 'user', content: body.message },
            { role: 'system', content: `The current channel is ${body.channel}.` }
          ]
        };

        try {
          let aiMessage;
          do {
            const chatResponse = await runAI(chatPrompt);
            console.log('AI response:', chatResponse);
            aiMessage = chatResponse.result?.response ?? 'Sorry, I could not understand your request.';
            aiMessage = removeFormatting(aiMessage);
            aiMessage = truncateResponse(aiMessage);
          } while (isRecentResponse(aiMessage));
          
          console.log('Formatted and truncated response:', aiMessage);

          return new Response(aiMessage, {
            headers: { 'content-type': 'text/plain' },
          });
        } catch (error) {
          console.error('Error processing request:', error);
          return new Response('Sorry, I could not understand your request.', {
            headers: { 'content-type': 'text/plain' },
            status: 500
          });
        }
      }

      return new Response('Method Not Allowed', { status: 405 });
    }

    // Default response
    return new Response('Not found', { status: 404 });
  }
};