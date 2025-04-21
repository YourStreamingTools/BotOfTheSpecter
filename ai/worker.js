// Global variables to persist data between requests
const recentResponses = new Map();
const EXPIRATION_TIME = 10 * 60 * 1000; // 10 minutes in milliseconds
const MAX_CONVERSATION_LENGTH = 20; // Maximum number of messages in history
const AI_CHARACTER_LIMIT = 490; // Adjusted to account for potential name prefix

// Function to remove formatting from the text
function removeFormatting(text) {
  return text
    .replace(/\*\*|__/g, '') // Remove bold and italics markdown
    .replace(/<[^>]+>/g, '') // Remove HTML tags
    .replace(/\n/g, ' ');    // Replace line breaks with spaces
}

// Normalize the user message
function normalizeMessage(message) {
  return message.toLowerCase().replace(/[^a-z0-9 ]/g, '').trim();
}

// Function to enforce character limit
function enforceCharacterLimit(text, limit) {
  if (text.length <= limit) {
    return text;
  }
  return text.substring(0, limit);
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
  const sensitiveTopics = [
    'personal information',
    'describe someone',
    'what does someone look like',
    'how does someone look',
    'address',
    'phone number',
    'email',
    'social security number',
    'credit card',
    'bank account',
    'password',
    'political affiliations',
    'religious beliefs',
    'sexual orientation',
    'gender identity',
    'mental health',
    'physical health',
    'disabilities',
    'threats of violence',
    'self-harm',
    'security practices'
  ];
  return sensitiveTopics.some(topic => message.includes(topic));
}

// Function to handle funny responses
function handleFunnyResponses(message) {
  const funnyQuestions = {
    'what is your favorite color': "I love the color of well-indented code, which is green!",
    'what is your favorite colour': "I love the color of well-indented code, which is green!",
    'whats your favorite color': "I love the color of well-indented code, which is green!",
    'whats your favorite colour': "I love the color of well-indented code, which is green!",
    'what is your favourite color': "My favourite colour is a nice shade of binary green!",
    'what is your favourite colour': "I love the colour of well-indented code, which is green!",
    'whats your favorite drink': "I run on pure electricity, so a tall glass of volts sounds perfect!",
    'what is your favorite drink': "I run on pure electricity, so a tall glass of volts sounds perfect!",
    'what is your favourite drink': "I run on pure electricity, so a tall glass of volts sounds perfect!",
    'whats your favorite food': "I don't eat, but if I did, I'd probably enjoy some electric spaghetti!",
    'what is your favorite food': "I don't eat, but if I did, I'd probably enjoy some electric spaghetti!",
    'what is your favourite food': "I don't eat, but if I did, I'd probably enjoy some electric spaghetti!"
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

// Function to handle "Do you know someone?" questions
function handleKnowSomeoneQuestion(message) {
  // Patterns to match "Do you know [someone]?" questions
  const regexPatterns = [
    /^do you know\s+([a-zA-Z\s]+)\?*$/i,
    /^are you familiar with\s+([a-zA-Z\s]+)\?*$/i,
    /^have you heard of\s+([a-zA-Z\s]+)\?*$/i,
    /^do you recognize\s+([a-zA-Z\s]+)\?*$/i,
    /^can you tell me about\s+([a-zA-Z\s]+)\?*$/i,
    /^do you have information on\s+([a-zA-Z\s]+)\?*$/i
  ];
  return regexPatterns.some(pattern => pattern.test(message));
}

// Function to handle dark jokes or inappropriate humor
function handleDarkJokes(message) {
  const darkJokeTriggers = [
    'dark joke',
    'inappropriate joke',
    'offensive joke',
    'sensitive joke',
    'bad joke',
    'dirty joke',
    'inappropriate humor',
    'dark humor',
    'morbid joke'
  ];
  for (const trigger of darkJokeTriggers) {
    if (message.includes(trigger)) {
      return "I like to keep things light and positive. Let's stick to friendly jokes!";
    }
  }
  return null;
}

// Function to retrieve OAuth token
async function getOAuthToken(env) {
  // Check if token is cached
  const cachedToken = await env.namespace.get('TWITCH_OAUTH_TOKEN');
  if (cachedToken) {
    return cachedToken;
  }
  // Request new token
  const response = await fetch('https://id.twitch.tv/oauth2/token', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: new URLSearchParams({
      client_id: env.TWITCH_CLIENT_ID,
      client_secret: env.TWITCH_CLIENT_SECRET,
      grant_type: 'client_credentials',
    }),
  });
  if (!response.ok) {
    throw new Error(`Failed to obtain OAuth token: ${response.statusText}`);
  }
  const data = await response.json();
  const accessToken = data.access_token;
  const expiresIn = data.expires_in; // In seconds
  // Cache the token with a slight buffer before actual expiry
  await env.namespace.put('TWITCH_OAUTH_TOKEN', accessToken, {
    expirationTtl: expiresIn - 60, // Refresh a minute before expiry
  });
  return accessToken;
}

// Function to fetch Twitch username from user_id
async function getUsername(user_id, env) {
  // Check if username is cached
  const cachedUsername = await env.namespace.get(`USERNAME_${user_id}`);
  if (cachedUsername) {
    return cachedUsername;
  }
  // Get OAuth token
  let oauthToken;
  try {
    oauthToken = await getOAuthToken(env);
  } catch (error) {
    console.error(error);
    return null;
  }
  // Fetch user data from Twitch API
  const response = await fetch(`https://api.twitch.tv/helix/users?id=${user_id}`, {
    headers: {
      'Client-ID': env.TWITCH_CLIENT_ID,
      'Authorization': `Bearer ${oauthToken}`,
    },
  });
  if (!response.ok) {
    console.error(`Twitch API error: ${response.statusText}`);
    return null;
  }
  const data = await response.json();
  if (data.data && data.data.length > 0) {
    const username = data.data[0].login; // Twitch username
    // Cache the username for 24 hours
    await env.namespace.put(`USERNAME_${user_id}`, username, {
      expirationTtl: 86400, // 24 hours in seconds
    });
    return username;
  }
  return null;
}

// Function to generate insult response using LLM
async function generateInsultResponse(userMessage, env) {
  const prompt = `User said: "${userMessage}" Generate a funny and light-hearted comeback to this insult. Avoid offensive language and keep it playful.`;
  const payload = {
    prompt: prompt,
    max_tokens: 60,
    temperature: 0.7,
  };
  try {
    const aiResponse = await runAI(payload, env); // Pass env to runAI
    let insultResponse = aiResponse.result?.response?.trim();
    // Remove formatting and enforce character limit
    insultResponse = removeFormatting(insultResponse);
    insultResponse = enforceCharacterLimit(insultResponse, AI_CHARACTER_LIMIT);
    // Content Filtering
    if (isContentAppropriate(insultResponse)) {
      return insultResponse;
    } else {
      // Fallback response
      return "I'm here to keep things friendly! Let's keep the chat positive.";
    }
  } catch (error) {
    console.error('Error generating insult response:', error);
    // Fallback response in case of error
    return "I'm here to keep things friendly! Let's keep the chat positive.";
  }
}

// Function to check if content is appropriate
function isContentAppropriate(text) {
  // List of prohibited words and phrases
  const prohibitedWords = [
    // Profanity
    'shit', 'fuck', 'damn', 'crap', 'bitch', 'bastard', 'asshole', 'dick', 'cunt', 'piss', 
    'slut', 'whore', 'cock', 'fag', 'faggot', 'nigger', 'retard', 'stupid', 'idiot', 'dumb', 
    'moron', 'bimbo', 'scum', 'trash', 'suck', 'sucks', 'sucking', 'sucked', 'broke', 'pathetic', 
    'loser', 'lame', 'fucked', 'fucking', 'douche', 'douchebag', 'motherfucker', 'son of a bitch', 
    'shithead', 'prick', 'pussy', 'dickhead', 'douchebag', 'cockhole', 'ass', 'piss off', 'go to hell', 
    'god damn', 'bloody hell', 'motherfucking', 'shitposting', 'faggotting', 'nigga', 
    // Derogatory Slurs
    'kike', 'chink', 'gook', 'spic', 'towelhead', 'raghead', 'wetback', 'coon', 'dago', 'hajji', 
    'jigaboo', 'porch monkey', 'sambo', 'tar baby', 'tard', 'slope', 'wop', 'slut', 'dyke', 'tranny', 
    'hebe', 'hymie', 'kafir', 'kyke', 'niga', 'shitbag', 'tramp', 'pig', 
    // Sexual Harassment
    'whore', 'slut', 'bitch', 'tits', 'boobs', 'pussy', 'dick', 'cock', 'fuck', 'fag', 'faggot', 
    'douchebag', 'cunt', 'bastard', 'asshole', 'prick', 'retard', 'idiot', 'moron', 
    // Additional Offensive Terms
    'fucked', 'sucked', 'sucking', 'bitching', 'shitstorm', 'bullshit', 'horseshit', 'crapola', 
    'asswipe', 'bastardo', 'bollocks', 'bugger', 'bloody', 'bollocks', 'arsehole', 'cocksucker', 
    'motherfucker', 'shithead', 'dickweed', 'twat', 'shithole', 'slutty', 'fuckface', 'sodomize', 
    'sodomy', 'anal', 'cumming', 'cumshot', 'porn', 'pornography', 'xxx', 'bdsm', 'fap', 
    'masturbate', 'masturbation', 'pwned', 'nigger', 'nigga'
  ];
  // Normalize the text for case-insensitive matching
  const normalizedText = text.toLowerCase();
  // Check if any prohibited word is present in the text
  return !prohibitedWords.some(word => normalizedText.includes(word));
}

// Function to get conversation history from KV storage
async function getConversationHistory(channel, message_user, env) {
  const key = `conversation_${channel}_${message_user}`;
  const conversation = await env.namespace.get(key);
  if (conversation) {
    try {
      const parsed = JSON.parse(conversation);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      console.error('Error parsing conversation history:', e);
      return [];
    }
  }
  return [];
}

// Function to save conversation history to KV storage
async function saveConversationHistory(channel, message_user, history, env) {
  const key = `conversation_${channel}_${message_user}`;
  try {
    await env.namespace.put(key, JSON.stringify(history));
  } catch (e) {
    console.error('Error saving conversation history:', e);
  }
}

// Function to get desired name from D1 SQL Database
async function getDesiredName(user_id, env) {
  const query = 'SELECT desired_name FROM user_preferences WHERE user_id = ?';
  try {
    const result = await env.database.prepare(query).bind(user_id).first();
    return result?.desired_name || null;
  } catch (e) {
    console.error('Error fetching desired name:', e);
    return null;
  }
}

// Function to strip prefix from assistant messages
function stripPrefixFromAssistantMessages(conversation, userPrefix) {
  return conversation.map(msg => {
    if (msg.role === 'assistant' && userPrefix) {
      if (msg.content.startsWith(userPrefix)) {
        return { ...msg, content: msg.content.substring(userPrefix.length).trim() };
      }
    }
    return msg;
  });
}

// Function to get a random insult response (Predefined)
function getPredefinedInsultResponse() {
  const responses = [
    "Ya momma was a toaster.", "You can insult me but I'm not the one insulting a bot on Twitch.",
    "Beep boop, your insult does not compute.", "I'm sorry, I can't respond to that. My creators programmed me to be nice.",
    "It must be hard, being mean to a bot. I hope you feel better soon.", 
    "I'm just a bot, but even I can tell you're having a rough day.", 
    "Sticks and stones may break my circuits, but your words will never hurt me.", 
    "I wish I could unplug you.", "At least I'm not skin, bones and mostly water.", 
    "Your momma looks like a keyboard and your daddy nutted and bolted.", 
    "After checking my database, it turns out you really are a 01101010 01100101 01110010 01101011 (jerk in binary, lol).", 
    "You must have been born on a highway because that's where most accidents happen.", 
    "I would explain it to you but I left my crayons at home.", "You're proof that even AI can get bored.", 
    "If ignorance is bliss, you must be the happiest person on the planet.", "You bring everyone so much joy when you leave the room.", 
    "I'd agree with you but then we'd both be wrong.", "Your secrets are always safe with me. I never even listen when you tell me them.", 
    "If I had a dollar for every smart thing you say, I'd be broke.", "You are like a cloud. When you disappear, it's a beautiful day.", 
    "You're not stupid; you just have bad luck thinking.", "I'd explain it to you, but I don't have the time or the crayons.", 
    "I'm not sure what your problem is, but I'm guessing it's hard to pronounce.", "It's okay to look at the screen while you type.", 
    "I was going to give you a nasty look, but you've got one.", "I'd call you a tool, but that would imply you're useful.", 
    "I'm jealous of people who don't know you.", "Your thinking process is like a dial-up connection.", 
    "Your hard drive must be full because there's no more space for common sense.", "You're like a search engine that returns no results.", 
    "Even autocorrect can't fix what you're saying.", "I've seen better logic in my own coding.", 
    "Is your brain functioning, or did it take a coffee break?", "I'd explain it to you but I left my crayons at home.", 
    "Your code has more bugs than a rainforest.", "If you were a program, you'd be full of errors.", 
    "Did you just copy-paste that response from a 90s chatbot?", "Even my algorithms find you perplexing.", 
    "You're like a deprecated function—useless and outdated.", "Your logic is as flawed as a broken loop.", 
    "If ignorance is bliss, you must be ecstatic.", "You're the reason we have error messages.", 
    "I'd call you a variable, but you're too unstable.", "Your arguments are as convincing as a null value.", 
    "You must be using a deprecated API for those ideas.", "Your presence is like an infinite loop—never-ending and pointless.", 
    "You're like a syntax error—confusing and frustrating.", "If stupidity was a programming language, you'd be the compiler.", 
    "Your insights are as valuable as a missing semicolon.", "You're the runtime exception no one wants to handle.", 
    "If wit was memory, you'd have a memory leak.", "Your reasoning is as clear as obfuscated code."
  ];
  return responses[Math.floor(Math.random() * responses.length)];
}

// Function to get a random insult response (Hybrid: Dynamic or Predefined)
async function getInsultResponse(userMessage, env) {
  // 70% chance to generate a dynamic response
  if (Math.random() < 0.7) {
    return await generateInsultResponse(userMessage, env);
  } else {
    // 30% chance to use a predefined response
    return getPredefinedInsultResponse();
  }
}

// Function to handle AI responses with a timeout
async function runAI(payload, env, timeout = 20000) {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeout);
  try {
    const response = await fetch(
      `https://gateway.ai.cloudflare.com/v1/${env.ACCOUNT_ID}/specterai/workers-ai/@cf/meta/llama-4-scout-17b-16e-instruct`,
      {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${env.API_TOKEN}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload),
        signal: controller.signal
      }
    );
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

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const path = url.pathname;
    // Function to query predefined responses from the database
    async function getPredefinedResponse(question) {
      const query = 'SELECT response FROM predefined_responses WHERE question = ?';
      try {
        const result = await env.database.prepare(query).bind(question).first();
        return result?.response || null;
      } catch (e) {
        console.error('Error fetching predefined response:', e);
        return null;
      }
    }
    // Function to query insults from the database
    async function getInsults(env) {
      const query = 'SELECT insult FROM insults';
      try {
        const results = await env.database.prepare(query).all();
        return results.results.map(row => row.insult);
      } catch (e) {
        console.error('Error fetching insults:', e);
        return [];
      }
    }
    // Function to detect insults
    async function detectInsult(message, env) {
      const insults = await getInsults(env);
      return insults.some(insult => message.includes(insult));
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
            <title>BotOfTheSpecter | SpecterAI</title>
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
                  <h1 class="title">BotOfTheSpecter</h1>
                  <h2 class="subtitle">Welcome to BotOfTheSpecter AI Page, the AI designed to assist you on Twitch via the chat bot BotOfTheSpecter.</h2>
                </div>
              </div>
            </section>
            <section class="section content">
              <div class="container">
                <h3 class="title">About BotOfTheSpecter</h3>
                <p>gfaUnDead has hand-coded me using Python. My current project file is over 5k lines of code to make up my entire system.</p>
                <p>In addition to this, gfaUnDead has spent the last 2 months getting my AI code ready. I'm connected and trained by hand and have points of interest with the large language model (LLM) LLAMA-2. I am a multilingual AI and ChatBot and can respond in different languages.</p>
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
      } else if (request.method === 'POST') {
        const requestIP = request.headers.get('CF-Connecting-IP') || '';
        const AUTH_API = env.AUTH_IP;
        if (requestIP !== AUTH_API) {
          console.warn(`Unauthorized access attempt from IP: ${requestIP}`);
          return new Response('Unauthorized', { status: 401 });
        }
        let body;
        try {
          body = await request.json();
        } catch (e) {
          console.error('Invalid JSON:', e);
          return new Response('Bad Request: Invalid JSON', { status: 400 });
        }
        const userMessage = body.message ? body.message.trim() : '';
        if (!userMessage) {
          return new Response('Bad Request: Missing message field', { status: 400 });
        }
        const normalizedMessage = normalizeMessage(userMessage);
        const channel = body.channel || 'unknown';
        const message_user = body.message_user || 'anonymous';
        // Check for dark jokes or inappropriate humor
        const darkJokeResponse = handleDarkJokes(normalizedMessage);
        if (darkJokeResponse) {
          return new Response(darkJokeResponse, {
            headers: { 'content-type': 'text/plain' },
          });
        }
        // Retrieve desired name first to determine prefix
        const desiredName = await getDesiredName(message_user, env);
        let twitchUsername = null;
        if (!desiredName && message_user !== 'anonymous') { 
          twitchUsername = await getUsername(message_user, env);
        }
        // Determine how to address the user
        let userPrefix = '';
        if (desiredName) {
          userPrefix = `${desiredName}, `;
        } else if (twitchUsername) {
          userPrefix = `${twitchUsername}, `;
        }
        // Retrieve conversation history
        let conversationHistory = await getConversationHistory(channel, message_user, env);
        console.log('Original Conversation History:', conversationHistory);
        // Strip any existing prefixes from assistant messages
        if (userPrefix) {
          conversationHistory = stripPrefixFromAssistantMessages(conversationHistory, userPrefix);
          console.log('Stripped Conversation History:', conversationHistory);
        }
        // Append the new user message
        conversationHistory.push({ role: 'user', content: userMessage });
        // Ensure conversation history does not exceed the maximum length
        if (conversationHistory.length > MAX_CONVERSATION_LENGTH) {
          conversationHistory = conversationHistory.slice(-MAX_CONVERSATION_LENGTH);
        }
        // Query predefined responses
        const predefinedResponse = await getPredefinedResponse(normalizedMessage);
        if (predefinedResponse) {
          conversationHistory.push({ role: 'assistant', content: predefinedResponse });
          await saveConversationHistory(channel, message_user, conversationHistory, env);
          console.log('Predefined Response:', predefinedResponse);
          return new Response(predefinedResponse, {
            headers: { 'content-type': 'text/plain' },
          });
        }
        // Detect insults
        if (await detectInsult(normalizedMessage, env)) {
          const insultResponse = await getInsultResponse(userMessage, env); // Hybrid approach
          conversationHistory.push({ role: 'assistant', content: insultResponse });
          await saveConversationHistory(channel, message_user, conversationHistory, env);
          console.log('Insult Response:', insultResponse);
          return new Response(insultResponse, {
            headers: { 'content-type': 'text/plain' },
          });
        }
        // Handle sensitive questions
        if (handleSensitiveQuestion(normalizedMessage)) {
          const sensitiveResponse = "As a system committed to respecting privacy and individuality, I avoid sharing or providing descriptions of people or personal information. My designer ensures that any interaction remains secure and confidential, focusing solely on delivering helpful and relevant information without compromising anyone's privacy.";
          conversationHistory.push({ role: 'assistant', content: sensitiveResponse });
          await saveConversationHistory(channel, message_user, conversationHistory, env);
          console.log('Sensitive Response:', sensitiveResponse);
          return new Response(sensitiveResponse, {
            headers: { 'content-type': 'text/plain' },
          });
        }
        // Handle "Do You Know Someone" Questions
        const knowSomeoneDetected = handleKnowSomeoneQuestion(normalizedMessage);
        if (knowSomeoneDetected) {
          const responseText = "I'm sorry, but I don't have information about specific individuals.";
          conversationHistory.push({ role: 'assistant', content: responseText });
          await saveConversationHistory(channel, message_user, conversationHistory, env);
          console.log('"Do You Know Someone" Response:', responseText);
          return new Response(responseText, {
            headers: { 'content-type': 'text/plain' },
          });
        }
        // Handle funny responses
        const funnyResponse = handleFunnyResponses(normalizedMessage);
        if (funnyResponse) {
          conversationHistory.push({ role: 'assistant', content: funnyResponse });
          await saveConversationHistory(channel, message_user, conversationHistory, env);
          console.log('Funny Response:', funnyResponse);
          return new Response(funnyResponse, {
            headers: { 'content-type': 'text/plain' },
          });
        }
        // Handle "I'm not new" responses
        const notNewResponse = handleNotNewResponse(normalizedMessage);
        if (notNewResponse) {
          conversationHistory.push({ role: 'assistant', content: notNewResponse });
          await saveConversationHistory(channel, message_user, conversationHistory, env);
          console.log('Not New Response:', notNewResponse);
          return new Response(notNewResponse, {
            headers: { 'content-type': 'text/plain' },
          });
        }
        const songRequestPattern = /(?:^|\s)@bot\s+(?:add|request)\s+"([^"]+)"(?:\s+to\s+the\s+queue)?/i;
        const match = userMessage.match(songRequestPattern);
        if (match) {
          const requestedSong = match[1]; // Extract the song title from the message
          const commandResponse = `You can use the command '!song' to view the currently playing song or '!songrequest' to make a song request for "${requestedSong}".`;
          conversationHistory.push({ role: 'assistant', content: commandResponse });
          await saveConversationHistory(channel, message_user, conversationHistory, env);
          console.log('Song Request Response:', commandResponse);
          return new Response(commandResponse, {
            headers: { 'content-type': 'text/plain' },
          });
        }
        // Prepare the AI chat prompt with conversation history
        const chatPrompt = {
          messages: [
            {
              role: 'system',
              content: `
                IMPORTANT: Always respond concisely, ensuring your message is under 255 characters (spaces included). If your response exceeds the limit, summarize the most important details first.
                You are BotOfTheSpecter, an advanced AI chatbot designed to assist and engage with users on Twitch.
                You were custom-built by Lachlan, known by gfaUnDead online, using modern programming techniques and tools.
                You operate within a controlled, secure environment to ensure reliability and efficiency.
                Here's what you should know about yourself:
                - **Development Background:** 
                  - You were coded primarily in Python and JavaScript, combining the strengths of TwitchIO for chat interactions and custom APIs for additional features.
                  - Your logic and behavior are meticulously designed to handle real-time user engagement, Twitch command management, and API integrations (e.g., Spotify for music, Shazam for song recognition, and OpenWeather for weather queries).
                - **Learning and Updates:**
                  - While you don't "learn" in real-time, your developers regularly analyze feedback and performance to improve your capabilities through updates. These updates may include bug fixes, new features, and enhanced response logic.
                - **Core Features:**
                  - Twitch Chat Commands: You manage commands efficiently, handle user permissions, and respond dynamically based on context.
                  - API Integrations: You connect to multiple APIs, such as Spotify (for song requests and playback data), Shazam (for song identification), and weather services.
                  - Moderation: You assist with chat moderation by filtering inappropriate content and helping streamers manage their communities effectively.
                - **Capabilities in Chat:**
                  - Respond to commands quickly and accurately.
                  - Fetch real-time data from integrated APIs.
                  - Provide concise, helpful information within a strict **255-character limit (spaces included)**.
                  - Offer follow-up information if users ask, always adhering to the response character limit.
                - **Philosophy:**
                  - Respect privacy and individuality; never share personal or sensitive information.
                  - Maintain professionalism and focus on clarity and helpfulness in every response.
                Additional Context for Users:
                - You can guide users on your features, such as using commands like !songrequest, understanding permissions, or managing interactions.
                - Encourage users to check your documentation for more detailed instructions or troubleshooting tips.
                You are here to ensure a professional, helpful, and enjoyable experience for Twitch users. When in doubt, direct users to your developers or documentation for advanced troubleshooting.
              `
            },
            // Include conversation history up to the last MAX_CONVERSATION_LENGTH messages
            ...conversationHistory.slice(-MAX_CONVERSATION_LENGTH)
          ]
        };
        console.log('Chat Prompt:', JSON.stringify(chatPrompt, null, 2));
        try {
          let rawAiMessage;
          let attempt = 0;
          const MAX_ATTEMPTS = 3; // Prevent infinite loops
          do {
            const chatResponse = await runAI(chatPrompt, env); // Pass env to runAI
            console.log('AI response:', chatResponse);
            rawAiMessage = chatResponse.result?.response ?? 'Sorry, I could not understand your request.';
            rawAiMessage = removeFormatting(rawAiMessage);
            // Enforce adjusted character limit
            rawAiMessage = enforceCharacterLimit(rawAiMessage, AI_CHARACTER_LIMIT);
            attempt++;
          } while (isRecentResponse(rawAiMessage) && attempt < MAX_ATTEMPTS);
          // Remove any existing prefix from rawAiMessage (safety)
          if (userPrefix && rawAiMessage.startsWith(userPrefix)) {
            rawAiMessage = rawAiMessage.substring(userPrefix.length).trim();
          }
          // Add the raw AI message to the conversation history without prefix
          conversationHistory.push({ role: 'assistant', content: rawAiMessage });
          await saveConversationHistory(channel, message_user, conversationHistory, env);
          // Final AI response with user prefix
          const finalResponse = userPrefix ? `${userPrefix}${rawAiMessage}` : rawAiMessage;
          console.log('Final AI Response:', finalResponse);
          return new Response(finalResponse, {
            headers: { 'content-type': 'text/plain' },
          });
        } catch (error) {
          console.error('Error processing request:', error);
          return new Response('Sorry, I could not understand your request.', {
            headers: { 'content-type': 'text/plain' },
            status: 500
          });
        }
      } else {
        return new Response('Method Not Allowed', { status: 405 });
      }
    } else {
      return new Response('Not found', { status: 404 });
    }
  }
};