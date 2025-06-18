// Global variables to persist data between requests
const recentResponses = new Map();
const EXPIRATION_TIME = 10 * 60 * 1000; // 10 minutes in milliseconds
const MAX_CONVERSATION_LENGTH = 30; // Increased maximum number of messages in history
const MAX_LONG_TERM_MEMORY = 100; // Maximum long-term memories per user
const AI_CHARACTER_LIMIT = 255; // Hard limit for Twitch chat compatibility
const MEMORY_DECAY_DAYS = 30; // Days before memories start to decay

// AutoRAG Configuration
const AUTORAG_CONFIG = {
  enabled: true, // Set to false to disable AutoRAG
  ragName: 'specterai', // Your AutoRAG name
  maxResults: 5, // Number of results to retrieve
  scoreThreshold: 0.6, // Minimum relevance score
  models: {
    primary: '@cf/meta/llama-4-scout-17b-16e-instruct',
    fallback: '@cf/meta/llama-3.3-70b-instruct-sd'
  }
};

// Function to search AutoRAG for relevant context
async function searchAutoRAG(query, env, options = {}) {
  if (!AUTORAG_CONFIG.enabled) {
    return null;
  }
  try {
    const searchPayload = {
      query: query,
      model: options.model || AUTORAG_CONFIG.models.primary,
      rewrite_query: options.rewrite_query || false,
      max_num_results: options.max_num_results || AUTORAG_CONFIG.maxResults,
      ranking_options: {
        score_threshold: options.score_threshold || AUTORAG_CONFIG.scoreThreshold
      },
      stream: false // For simpler handling
    };
    const response = await fetch(
      `https://api.cloudflare.com/client/v4/accounts/${env.ACCOUNT_ID}/autorag/rags/${AUTORAG_CONFIG.ragName}/ai-search`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${env.CLOUDFLARE_API_TOKEN}`
        },
        body: JSON.stringify(searchPayload)
      }
    );
    if (!response.ok) {
      console.warn('AutoRAG search failed:', await response.text());
      return null;
    }
    const result = await response.json();
    return result;
  } catch (error) {
    console.warn('AutoRAG search error:', error);
    return null;
  }
}

// Function to format AutoRAG context for the AI prompt
function formatAutoRAGContext(ragResult) {
  if (!ragResult || !ragResult.result) {
    return '';
  }
  let context = '\n\nRELEVANT KNOWLEDGE BASE:\n';
  // If the result contains retrieved documents
  if (ragResult.result.retrieved_documents) {
    ragResult.result.retrieved_documents.forEach((doc, index) => {
      if (doc.score >= AUTORAG_CONFIG.scoreThreshold) {
        context += `- ${doc.content.substring(0, 200)}${doc.content.length > 200 ? '...' : ''}\n`;
      }
    });
  }
  // If the result contains a generated response, we can use it as additional context
  if (ragResult.result.response) {
    context += `\nRAG Response: ${ragResult.result.response}\n`;
  }
  return context;
}

// Enhanced function to get contextual information using both memory and AutoRAG
async function getEnhancedContext(message, userId, channel, env) {
  const context = {
    memories: [],
    ragContext: '',
    ragResult: null
  };
  // Get traditional memories
  context.memories = await getContextualMemories(userId, channel, message, env);
  // Always try to get RAG context for every message
  if (AUTORAG_CONFIG.enabled && validateAutoRAGConfig(env)) {
    console.log('Searching AutoRAG for context...');
    try {
      const ragResult = await searchAutoRAG(message, env);
      if (ragResult) {
        context.ragResult = ragResult;
        context.ragContext = formatAutoRAGContext(ragResult);
        console.log('AutoRAG context retrieved:', context.ragContext.length, 'characters');
      } else {
        console.log('No relevant AutoRAG context found');
      }
    } catch (error) {
      console.warn('AutoRAG search failed:', error);
    }
  }
  return context;
}

// Function to remove version number references and links
function removeVersionReferences(text) {
  return text
    // Remove version number references like "3.4.md", "v3.4.md", "version_3.4.md"
    .replace(/\b(?:v(?:ersion)?[\s_-]?)?(\d+(?:\.\d+)*(?:\.\w+)?)\s*\.md\b/gi, '')
    // Remove parenthetical version references like "(3.4.md)" or "(v3.4.md)"
    .replace(/\s*\([^)]*\.md\)/gi, '')
    // Remove explicit references to "Check release notes (X.X.md)"
    .replace(/\s*[Cc]heck\s+release\s+notes\s*\([^)]*\.md\)[^.!?]*/gi, '')
    // Remove references like "see 3.4.md" or "view v3.4.md"
    .replace(/\s*(?:see|view|check)\s+(?:v(?:ersion)?[\s_-]?)?(\d+(?:\.\d+)*(?:\.\w+)?)\s*\.md\b/gi, '')
    // Clean up extra spaces and punctuation
    .replace(/\s+/g, ' ')
    .replace(/\s*[.!?]+\s*$/, function(match) {
      return match.trim();
    })
    .trim();
}

// Function to remove formatting from the text
function removeFormatting(text) {
  let cleanText = text
    .replace(/\*\*|__/g, '') // Remove bold and italics markdown
    .replace(/<[^>]+>/g, '') // Remove HTML tags
    .replace(/\n/g, ' ');    // Replace line breaks with spaces
  // Remove version number references and file links
  return removeVersionReferences(cleanText);
}

// Normalize the user message
function normalizeMessage(message) {
  return message.toLowerCase().replace(/[^a-z0-9 ]/g, '').trim();
}

// Function to enforce character limit with AI summarization
function enforceCharacterLimit(text, limit) {
  if (text.length <= limit) {
    return text;
  }
  // Simple truncation with smart ending
  let truncated = text.substring(0, limit - 3);
  // Try to end at a complete word
  const lastSpace = truncated.lastIndexOf(' ');
  if (lastSpace > limit * 0.8) { // Only if we don't lose too much
    truncated = truncated.substring(0, lastSpace);
  }
  return truncated + '...';
}

// Enhanced function to intelligently summarize long responses using AI
async function intelligentSummarize(text, limit, env) {
  if (text.length <= limit) {
    return text;
  }
  try {
    const summarizePrompt = {
      messages: [
        {
          role: 'system',
          content: `You are a text summarizer. Summarize the following text to be under ${limit} characters while keeping the most important information and maintaining the original tone. Do not add any new information.`
        },
        {
          role: 'user',
          content: `Summarize this to under ${limit} characters: "${text}"`
        }
      ]
    };
    const summaryResponse = await runAI(summarizePrompt, env, 10000); // 10 second timeout
    let summary = summaryResponse.result?.response?.trim() || '';
    // Remove any formatting and ensure it's under limit
    summary = removeFormatting(summary);
    if (summary.length > 0 && summary.length <= limit) {
      return summary;
    }
    // Fallback to simple truncation if AI summary fails or is too long
    return enforceCharacterLimit(text, limit);
  } catch (error) {
    console.error('Error in AI summarization:', error);
    // Fallback to simple truncation
    return enforceCharacterLimit(text, limit);
  }
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

// KV-based storage functions (since Workers can't connect directly to MySQL)
async function executeQuery(query, params = [], env) {
  // Since we can't directly connect to MySQL from Workers, everything goes to KV
  console.log('Using KV storage for all operations (MySQL not directly accessible from Workers)');
  return { success: false, error: 'Using KV storage fallback' };
}

// Fallback function to get memories from KV storage
async function getMemoriesFromKV(user_id, channel, env, limit = 10) {
  try {
    const keys = await env.namespace.list({ prefix: `memory_${user_id}_${channel}_` });
    const memories = [];
    for (const key of keys.keys.slice(0, limit)) {
      const memory = await env.namespace.get(key.name);
      if (memory) {
        try {
          memories.push(JSON.parse(memory));
        } catch (e) {
          console.error('Error parsing memory from KV:', e);
        }
      }
    }
    return memories.sort((a, b) => (b.importance || 5) - (a.importance || 5));
  } catch (e) {
    console.error('Error fetching memories from KV:', e);
    return [];
  }
}

// Enhanced KV storage functions for when database is unavailable
async function saveToKV(key, data, env, ttl = 86400 * 30) {
  try {
    await env.namespace.put(key, JSON.stringify(data), { expirationTtl: ttl });
    return true;
  } catch (e) {
    console.error('Error saving to KV:', e);
    return false;
  }
}

async function getFromKV(key, env) {
  try {
    const data = await env.namespace.get(key);
    return data ? JSON.parse(data) : null;
  } catch (e) {
    console.error('Error getting from KV:', e);
    return null;
  }
}

async function searchKVMemories(user_id, channel, keywords, env, limit = 3) {
  try {
    const keys = await env.namespace.list({ prefix: `memory_${user_id}_${channel}_` });
    const memories = [];
    for (const key of keys.keys) {
      const memory = await env.namespace.get(key.name);
      if (memory) {
        try {
          const memoryData = JSON.parse(memory);
          const content = memoryData.content?.toLowerCase() || '';
          // Check if any keyword matches the memory content
          if (keywords.some(keyword => content.includes(keyword))) {
            memories.push(memoryData);
          }
        } catch (e) {
          console.error('Error parsing memory for search:', e);
        }
      }
    }
    return memories
      .sort((a, b) => (b.importance || 5) - (a.importance || 5))
      .slice(0, limit);
  } catch (e) {
    console.error('Error searching KV memories:', e);
    return [];
  }
}

// Function to save long-term memory to KV storage
async function saveLongTermMemory(user_id, channel, memory_type, content, importance = 5, env) {
  const now = new Date().toISOString();
  const key = `memory_${user_id}_${channel}_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
  const memoryData = {
    user_id, 
    channel, 
    memory_type, 
    content, 
    importance, 
    created_at: now,
    last_accessed: now,
    access_count: 1
  };
  try {
    await env.namespace.put(key, JSON.stringify(memoryData), { 
      expirationTtl: 86400 * 30 // 30 days
    });
  } catch (e) {
    console.error('Error saving long-term memory to KV:', e);
  }
}

// Function to get long-term memories from KV storage
async function getLongTermMemories(user_id, channel, env, limit = 10) {
  try {
    return await getMemoriesFromKV(user_id, channel, env, limit);
  } catch (e) {
    console.error('Error fetching long-term memories:', e);
    return [];
  }
}

// Function to update memory access time and importance in KV
async function updateMemoryAccess(user_id, channel, content, env) {
  // For KV storage, we'll just log the access
  console.log(`Memory accessed: ${content.substring(0, 50)}...`);
}

// Function to extract and save important information from conversations
async function extractImportantInfo(conversation, user_id, channel, env) {
  // Look for patterns that might be worth remembering
  const userMessages = conversation.filter(msg => msg.role === 'user').slice(-5); // Last 5 user messages
  for (const message of userMessages) {
    const content = message.content.toLowerCase();
    // Extract preferences
    if (content.includes('i like') || content.includes('i love') || content.includes('my favorite')) {
      await saveLongTermMemory(user_id, channel, 'preference', message.content, 7, env);
    }
    // Extract personal info (non-sensitive)
    if (content.includes('i am') || content.includes("i'm")) {
      const personalInfo = message.content.replace(/i am|i'm/gi, '').trim();
      if (personalInfo.length > 3 && !handleSensitiveQuestion(content)) {
        await saveLongTermMemory(user_id, channel, 'personal', message.content, 6, env);
      }
    }
    // Extract interests/topics
    if (content.includes('about') || content.includes('interested in')) {
      await saveLongTermMemory(user_id, channel, 'interest', message.content, 5, env);
    }
    // Extract repeated questions (FAQ patterns)
    const questionWords = ['what', 'how', 'why', 'when', 'where', 'who'];
    if (questionWords.some(word => content.startsWith(word))) {
      await saveLongTermMemory(user_id, channel, 'question', message.content, 4, env);
    }
  }
}

// Function to clean old memories from KV storage
async function cleanOldMemories(user_id, channel, env) {
  try {
    const keys = await env.namespace.list({ prefix: `memory_${user_id}_${channel}_` });
    const cutoffDate = new Date();
    cutoffDate.setDate(cutoffDate.getDate() - MEMORY_DECAY_DAYS);
    for (const key of keys.keys) {
      const memory = await env.namespace.get(key.name);
      if (memory) {
        try {
          const memoryData = JSON.parse(memory);
          const createdDate = new Date(memoryData.created_at);
          // Delete old, low-importance memories
          if (createdDate < cutoffDate && 
              (memoryData.importance || 5) < 5 && 
              (memoryData.access_count || 1) < 2) {
            await env.namespace.delete(key.name);
          }
        } catch (e) {
          console.error('Error parsing memory for cleanup:', e);
        }
      }
    }
  } catch (e) {
    console.error('Error cleaning old memories:', e);
  }
}

// Function to get contextual memories from KV storage
async function getContextualMemories(user_id, channel, currentMessage, env) {
  const keywords = currentMessage.toLowerCase().split(' ').filter(word => word.length > 3);
  if (keywords.length === 0) return [];
  try {
    return await searchKVMemories(user_id, channel, keywords, env, 3);
  } catch (e) {
    console.error('Error fetching contextual memories:', e);
    return [];
  }
}

// Function to save user interaction patterns to KV storage
async function saveInteractionPattern(user_id, channel, pattern_type, pattern_data, env) {
  try {
    const key = `pattern_${user_id}_${channel}_${pattern_type}`;
    await env.namespace.put(key, JSON.stringify(pattern_data), { 
      expirationTtl: 86400 * 7 // 7 days
    });
  } catch (e) {
    console.error('Error saving interaction pattern to KV:', e);
  }
}

// Function to get user interaction patterns from KV storage
async function getUserPatterns(user_id, channel, env) {
  const patterns = {};
  const patternTypes = ['communication_style', 'activity_times', 'preferences'];
  try {
    for (const pattern_type of patternTypes) {
      const key = `pattern_${user_id}_${channel}_${pattern_type}`;
      const pattern_data = await env.namespace.get(key);
      if (pattern_data) {
        try {
          patterns[pattern_type] = JSON.parse(pattern_data);
        } catch (e) {
          console.error('Error parsing pattern data:', e);
        }
      }
    }
  } catch (e) {
    console.error('Error fetching user patterns:', e);
  }
  return patterns;
}

// Function to get desired name from KV storage
async function getDesiredName(user_id, env) {
  try {
    const cached = await env.namespace.get(`desired_name_${user_id}`);
    if (cached) {
      const data = JSON.parse(cached);
      return data.desired_name || null;
    }
    return null;
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

// Function to validate AutoRAG configuration
function validateAutoRAGConfig(env) {
  const required = ['ACCOUNT_ID', 'CLOUDFLARE_API_TOKEN'];
  const missing = required.filter(key => !env[key]);
  if (missing.length > 0) {
    console.warn('AutoRAG disabled: Missing environment variables:', missing.join(', '));
    return false;
  }
  return true;
}

// Enhanced AI function that can use AutoRAG for direct responses
async function runAIWithAutoRAG(payload, env, query, ragResult = null, timeout = 20000) {
  // If we have a high-quality RAG result, try using it directly first
  if (AUTORAG_CONFIG.enabled && ragResult && ragResult.result) {
    try {
      // Check if RAG has a direct response and it's relevant/high quality
      if (ragResult.result.response) {
        const ragResponse = ragResult.result.response.trim();
        // Check if we have highly relevant results (high score documents)
        const hasHighQualityResults = ragResult.result.retrieved_documents && 
          ragResult.result.retrieved_documents.some(doc => doc.score >= 0.7);
        if (hasHighQualityResults && ragResponse.length <= AI_CHARACTER_LIMIT) {
          console.log('Using high-quality AutoRAG direct response');
          return { result: { response: ragResponse } };
        } else if (hasHighQualityResults && ragResponse.length > AI_CHARACTER_LIMIT) {
          console.log('AutoRAG response too long, using traditional AI with RAG context');
        } else {
          console.log('AutoRAG response quality not high enough, using as context for traditional AI');
        }
      }
    } catch (error) {
      console.warn('AutoRAG direct response processing failed:', error);
    }
  }
  // Fallback to traditional AI (which will include RAG context in the prompt if available)
  return await runAI(payload, env, timeout);
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const path = url.pathname;
    async function getPredefinedResponse(question) {
      const query = 'SELECT response FROM predefined_responses WHERE question = ?';
      try {
        const result = await executeQuery(query, [question], env);
        if (result.success && result.rows && result.rows.length > 0) {
          return result.rows[0].response || null;
        } else {
          // Fallback to KV storage
          const cached = await env.namespace.get(`predefined_${question}`);
          return cached || null;
        }
      } catch (e) {
        console.error('Error fetching predefined response:', e);
        return null;
      }
    }    // Function to query insults from your own database
    async function getInsults(env) {
      const query = 'SELECT insult FROM insults';
      try {
        const result = await executeQuery(query, [], env);
        if (result.success && result.rows) {
          return result.rows.map(row => row.insult);
        } else {
          // Fallback to hardcoded list
          return ['stupid', 'idiot', 'dumb', 'loser', 'moron'];
        }
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
                <p>gfaUnDead has hand-coded me using Python. My current project file is over 8k lines of code to make up my entire system.</p>
                <p>I'm connected and trained by hand and have points of interest with the large language model (LLM) LLAMA-4. I am a multilingual AI and ChatBot and can respond in different languages.</p>
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
        const AUTH_IPS = (env.AUTH_IP || '').split(',').map(ip => ip.trim()).filter(Boolean);
        if (!AUTH_IPS.includes(requestIP)) {
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
        console.log('Original Conversation History:', conversationHistory); // Get enhanced context including AutoRAG
        const enhancedContext = await getEnhancedContext(userMessage, message_user, channel, env);
        const longTermMemories = await getLongTermMemories(message_user, channel, env, 5);
        const userPatterns = await getUserPatterns(message_user, channel, env);
        // Clean old memories periodically (10% chance)
        if (Math.random() < 0.1) { await cleanOldMemories(message_user, channel, env); }
          console.log('Long-term memories:', longTermMemories.length);
        console.log('Contextual memories:', enhancedContext.memories.length);
        console.log('AutoRAG context available:', !!enhancedContext.ragContext);
        if (enhancedContext.ragContext) { console.log('AutoRAG context length:', enhancedContext.ragContext.length); }
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
        // Prepare the AI chat prompt with conversation history and memory context
        let memoryContext = '';
        // Add long-term memory context
        if (longTermMemories.length > 0) {
          memoryContext += '\n\nIMPORTANT MEMORIES ABOUT THIS USER:\n';
          longTermMemories.forEach(memory => {
            memoryContext += `- ${memory.memory_type}: ${memory.content}\n`;
          });
        }
        // Add contextual memories if different from long-term
        if (enhancedContext.memories.length > 0) {
          const contextualContent = enhancedContext.memories.filter(cm => 
            !longTermMemories.some(lm => lm.content === cm.content)
          );
          if (contextualContent.length > 0) {
            memoryContext += '\nRELEVANT CONTEXT:\n';
            contextualContent.forEach(memory => {
              memoryContext += `- ${memory.content}\n`;
            });
          }
        }
        // Add AutoRAG context if available
        if (enhancedContext.ragContext) { memoryContext += enhancedContext.ragContext; }
        // Add user patterns
        if (Object.keys(userPatterns).length > 0) {
          memoryContext += '\nUSER INTERACTION PATTERNS:\n';
          if (userPatterns.communication_style) {
            memoryContext += `- Communication style: ${userPatterns.communication_style.style}\n`;
          }
          if (userPatterns.activity_times) {
            memoryContext += `- Usually active: ${userPatterns.activity_times.peak_hours}\n`;
          }
        }
        const chatPrompt = {
          messages: [
            {
              role: 'system',
              content: `
                IMPORTANT: Always respond concisely, ensuring your message is under 255 characters (spaces included). If your response exceeds the limit, summarize the most important details first.
                You are BotOfTheSpecter, an advanced AI chatbot designed to assist and engage with users on Twitch.
                You were custom-built by Lachlan, known by gfaUnDead online, using modern programming techniques and tools.
                You operate within a controlled, secure environment to ensure reliability and efficiency.
                MEMORY SYSTEM: You have access to long-term memory about users. Use this information to provide personalized responses while respecting privacy. Reference past conversations naturally when relevant.
                KNOWLEDGE BASE: When available, you have access to a comprehensive knowledge base through AutoRAG that contains documentation, guides, troubleshooting information, and detailed explanations about your features and capabilities. Use this information to provide accurate, helpful responses to user questions.
                ${memoryContext}
                RESPONSE GUIDELINES:
                - If knowledge base information is available, prioritize it for accuracy
                - Combine knowledge base info with your conversational abilities
                - For technical questions, refer to the knowledge base context when available
                - Always stay within the 255-character limit
                - If the knowledge base has relevant info, use it; otherwise, use your general knowledge
                - NEVER include version file references (like "3.4.md", "v2.1.md", "Check release notes (X.X.md)") in your responses
                - Do not mention specific documentation files or suggest users check specific .md files
                Here's what you should know about yourself:
                - **Development Background:** 
                  - You were coded primarily in Python and JavaScript, combining the strengths of TwitchIO for chat interactions and custom APIs for additional features.
                  - Your logic and behavior are meticulously designed to handle real-time user engagement, Twitch command management, and API integrations (e.g., Spotify for music, Shazam for song recognition, and OpenWeather for weather queries).
                - **Learning and Updates:**
                  - You have an advanced memory system that remembers user preferences, past conversations, and interaction patterns.
                  - Your developers regularly analyze feedback and performance to improve your capabilities through updates.
                - **Core Features:**
                  - Twitch Chat Commands: You manage commands efficiently, handle user permissions, and respond dynamically based on context.
                  - API Integrations: You connect to multiple APIs, such as Spotify (for song requests and playback data), Shazam (for song identification), and weather services.
                  - Moderation: You assist with chat moderation by filtering inappropriate content and helping streamers manage their communities effectively.
                  - Memory: You remember user preferences, past interactions, and can provide personalized responses.
                - **Capabilities in Chat:**
                  - Respond to commands quickly and accurately.
                  - Fetch real-time data from integrated APIs.
                  - Remember and reference past conversations appropriately.
                  - Provide concise, helpful information within a strict **255-character limit (spaces included)**.
                  - Offer follow-up information if users ask, always adhering to the response character limit.
                - **Philosophy:**
                  - Respect privacy and individuality; never share personal or sensitive information.
                  - Use your memory to enhance user experience while maintaining professionalism.
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
            const chatResponse = await runAIWithAutoRAG(chatPrompt, env, userMessage, enhancedContext.ragResult); // Enhanced AI with AutoRAG
            console.log('AI response:', chatResponse);
            rawAiMessage = chatResponse.result?.response ?? 'Sorry, I could not understand your request.';
            rawAiMessage = removeFormatting(rawAiMessage);
            // Use intelligent summarization if message is too long
            if (rawAiMessage.length > AI_CHARACTER_LIMIT) {
              console.log(`Message too long (${rawAiMessage.length} chars), summarizing...`);
              rawAiMessage = await intelligentSummarize(rawAiMessage, AI_CHARACTER_LIMIT, env);
            }
            // Remove version references from the AI message
            rawAiMessage = removeVersionReferences(rawAiMessage);
            attempt++;
          } while (isRecentResponse(rawAiMessage) && attempt < MAX_ATTEMPTS);
          // Remove any existing prefix from rawAiMessage (safety)
          if (userPrefix && rawAiMessage.startsWith(userPrefix)) {
            rawAiMessage = rawAiMessage.substring(userPrefix.length).trim();
          }
          // Add the raw AI message to the conversation history without prefix
          conversationHistory.push({ role: 'assistant', content: rawAiMessage });
          await saveConversationHistory(channel, message_user, conversationHistory, env);
          // Extract and save important information from the conversation
          await extractImportantInfo(conversationHistory, message_user, channel, env);
          // Update user interaction patterns
          const currentHour = new Date().getHours();
          const communicationStyle = {
            message_length: userMessage.length,
            uses_questions: userMessage.includes('?'),
            politeness_level: userMessage.toLowerCase().includes('please') || userMessage.toLowerCase().includes('thank') ? 'high' : 'normal',
            timestamp: new Date().toISOString()
          };
          const activityPattern = {
            peak_hours: `${currentHour}:00-${currentHour + 1}:00`,
            last_active: new Date().toISOString(),
            message_count: (userPatterns.activity_times?.message_count || 0) + 1
          };
          await saveInteractionPattern(message_user, channel, 'communication_style', communicationStyle, env);
          await saveInteractionPattern(message_user, channel, 'activity_times', activityPattern, env);
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