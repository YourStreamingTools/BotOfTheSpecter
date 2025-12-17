<?php
ob_start();
?>
<section class="hero mb-5">
    <div class="hero-body" style="height: 300px;">
        <div class="container" style="height: 100%;">
            <div class="columns is-vcentered" style="height: 100%;">
                <div class="column is-8" style="display: flex; flex-direction: column; justify-content: center;">
                    <h1 class="title is-1 has-text-weight-bold has-text-light">Discord Markdown Guide</h1>
                    <p class="subtitle is-4 has-text-light">Master the art of formatting your Discord messages with rich text styling, code blocks, and advanced formatting techniques.</p>
                    <div class="buttons">
                        <a href="https://discord.com/developers/docs/reference#message-formatting" target="_blank" class="button is-large has-text-light">
                            <span class="icon">
                                <i class="fab fa-discord"></i>
                            </span>
                            <span>Discord Docs</span>
                        </a>
                    </div>
                </div>
                <div class="column is-4 has-text-centered">
                    <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter Logo" style="width: 350px; max-height: 300px; object-fit: contain;" />
                </div>
            </div>
        </div>
    </div>
</section>

<div class="columns">
    <div class="column is-8">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light">
                <h2 id="introduction" class="title is-4 has-text-light">
                    <span class="icon">
                        <i class="fas fa-info-circle"></i>
                    </span>
                    What is Discord Markdown?
                </h2>
                <p>
                    Discord Markdown is a lightweight markup language that allows you to format your messages with various text styles, create code blocks, embed links, and create rich, visually appealing content in your Discord messages and embeds. Understanding Markdown will help you communicate more effectively and make your bot responses stand out in your server.
                </p>
                <div class="columns mt-4 has-text-light">
                    <div class="column has-text-centered">
                        <span class="icon is-large has-text-primary">
                            <i class="fas fa-text-height fa-3x"></i>
                        </span>
                        <h5 class="title is-5">Text Styles</h5>
                        <p>Bold, italic, underline, and strikethrough formatting options.</p>
                    </div>
                    <div class="column has-text-centered">
                        <span class="icon is-large has-text-success">
                            <i class="fas fa-code fa-3x"></i>
                        </span>
                        <h5 class="title is-5">Code Blocks</h5>
                        <p>Syntax highlighted code with language support.</p>
                    </div>
                    <div class="column has-text-centered">
                        <span class="icon is-large has-text-info">
                            <i class="fas fa-link fa-3x"></i>
                        </span>
                        <h5 class="title is-5">Advanced Features</h5>
                        <p>Lists, quotes, mentions, and more formatting options.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light">
                <h2 id="text-styles" class="title is-4 has-text-light">
                    <span class="icon">
                        <i class="fas fa-text-height"></i>
                    </span>
                    Text Styles
                </h2>
                <div class="box has-background-grey-darker">
                    <p class="has-text-light"><strong>Bold Text</strong></p>
                    <p><code>**bold text**</code> or <code>__bold text__</code></p>
                    <p class="mt-2">Result: <strong>bold text</strong></p>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Italic Text</strong></p>
                    <p><code>*italic text*</code> or <code>_italic text_</code></p>
                    <p class="mt-2">Result: <em>italic text</em></p>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Underline Text</strong></p>
                    <p><code>__underline text__</code></p>
                    <p class="mt-2">Result: <u>underline text</u></p>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Strikethrough Text</strong></p>
                    <p><code>~~strikethrough text~~</code></p>
                    <p class="mt-2">Result: <s>strikethrough text</s></p>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Combined Styles</strong></p>
                    <p><code>***bold italic***</code> or <code>__**bold underline**__</code></p>
                    <p class="mt-2">Result: <strong><em>bold italic</em></strong> or <u><strong>bold underline</strong></u></p>
                </div>
            </div>
        </div>

        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light">
                <h2 id="code-blocks" class="title is-4 has-text-light">
                    <span class="icon">
                        <i class="fas fa-code"></i>
                    </span>
                    Code Blocks
                </h2>

                <div class="box has-background-grey-darker">
                    <p class="has-text-light"><strong>Inline Code</strong></p>
                    <p><code>`inline code`</code></p>
                    <p class="mt-2">Use backticks for short code snippets within text.</p>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Code Block (No Syntax Highlighting)</strong></p>
                    <p><code>```</code></p>
                    <p><code>code here</code></p>
                    <p><code>```</code></p>
                    <p class="mt-2">Wraps text in a code block without language-specific highlighting.</p>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Code Block with Syntax Highlighting</strong></p>
                    <p><code>```python</code></p>
                    <p><code>def hello():</code></p>
                    <p><code>&nbsp;&nbsp;&nbsp;&nbsp;print("Hello, World!")</code></p>
                    <p><code>```</code></p>
                    <p class="mt-2">Specify the language after the opening backticks for syntax highlighting.</p>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Supported Languages</strong></p>
                    <p>Common languages: python, javascript, java, cpp, csharp, php, sql, html, css, json, yaml, xml, bash, and many more!</p>
                </div>
            </div>
        </div>

        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light">
                <h2 id="advanced-features" class="title is-4 has-text-light">
                    <span class="icon">
                        <i class="fas fa-star"></i>
                    </span>
                    Advanced Features
                </h2>

                <div class="box has-background-grey-darker">
                    <p class="has-text-light"><strong>Block Quotes</strong></p>
                    <p><code>&gt; This is a quote</code></p>
                    <p class="mt-2">Creates a highlighted quote block. Use multiple <code>&gt;</code> for nested quotes.</p>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Headings</strong></p>
                    <p><code># Heading 1</code></p>
                    <p><code>## Heading 2</code></p>
                    <p><code>### Heading 3</code></p>
                    <p class="mt-2">Use 1-3 hash symbols to create headings of different sizes.</p>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Unordered Lists</strong></p>
                    <p><code>- Item 1</code></p>
                    <p><code>- Item 2</code></p>
                    <p><code>- Item 3</code></p>
                    <p class="mt-2">Use hyphens, asterisks, or plus signs to create list items.</p>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Mentions & Tagging</strong></p>
                    <p><code>&lt;@user_id&gt;</code> - Mention a user</p>
                    <p><code>&lt;@&amp;role_id&gt;</code> - Mention a role</p>
                    <p><code>&lt;#channel_id&gt;</code> - Mention a channel or voice channel</p>
                    <p class="mt-2">Create mentions by using angle brackets with the appropriate prefix and ID. Users can find IDs by right-clicking and selecting "Copy User/Channel/Role ID" (requires Developer Mode enabled).</p>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Emojis</strong></p>
                    <p><code>&lt;:emoji_name:emoji_id&gt;</code> - Custom emoji</p>
                    <p><code>&lt;a:emoji_name:emoji_id&gt;</code> - Animated custom emoji</p>
                    <p><code>😀 🎉 ❤️</code> - Unicode emoji (copy/paste directly)</p>
                    <p class="mt-2">Use custom server emojis with their ID, or paste standard emoji characters directly into messages.</p>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Links</strong></p>
                    <p><code>[Link Text](https://example.com)</code></p>
                    <p class="mt-2">Create clickable links with custom text.</p>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Horizontal Rule</strong></p>
                    <p><code>---</code></p>
                    <p class="mt-2">Creates a separator line between sections.</p>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Timestamps</strong></p>
                    <p><code>&lt;t:1234567890&gt;</code> - Absolute timestamp</p>
                    <p><code>&lt;t:1234567890:R&gt;</code> - Relative timestamp (e.g., "2 hours ago")</p>
                    <p class="mt-2">Displays time in the user's local timezone.</p>
                </div>
            </div>
        </div>

        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light">
                <h2 id="using-with-bot" class="title is-4 has-text-light">
                    <span class="icon">
                        <i class="fas fa-robot"></i>
                    </span>
                    Using Markdown with BotOfTheSpecter Discord Module
                </h2>
                <p class="mb-3">
                    The <strong>Discord module</strong> of BotOfTheSpecter supports Discord Markdown in bot responses, embeds, and custom commands. Here are some practical examples:
                </p>

                <div class="box has-background-grey-darker">
                    <p class="has-text-light"><strong>In Custom Discord Commands</strong></p>
                    <p class="mt-2">Use Markdown syntax directly in your custom command responses:</p>
                    <pre style="background-color: #1a1a1a; padding: 1rem; border-radius: 5px;">**Welcome** to our server!
_Please read the rules_ before chatting.</pre>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>In Discord Embeds</strong></p>
                    <p class="mt-2">Use Markdown in embed titles, descriptions, and field values for rich formatting:</p>
                    <pre style="background-color: #1a1a1a; padding: 1rem; border-radius: 5px;">Title: **Server Rules**
Description: Follow these guidelines:
- Respect all members
- No spam
- Have fun!</pre>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Best Practices</strong></p>
                    <ul class="mt-2">
                        <li>Keep formatting clean and readable</li>
                        <li>Don't overuse text decorations</li>
                        <li>Use code blocks for code snippets</li>
                        <li>Use lists for organized information</li>
                        <li>Test your messages before adding to commands</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light">
                <h2 id="examples" class="title is-4 has-text-light">
                    <span class="icon">
                        <i class="fas fa-lightbulb"></i>
                    </span>
                    Practical Examples
                </h2>

                <div class="box has-background-grey-darker">
                    <p class="has-text-light"><strong>Help Menu Example</strong></p>
                    <pre style="background-color: #1a1a1a; padding: 1rem; border-radius: 5px;">**Available Commands**
`!help` - Show this message
`!ping` - Check bot latency
`!userinfo` - Get user information

**For more help, visit** [our documentation](https://help.botofthespecter.com)</pre>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Code Share Example</strong></p>
                    <pre style="background-color: #1a1a1a; padding: 1rem; border-radius: 5px;">```python
def greet(name):
    return f"Hello, {name}!"

print(greet("Developer"))
```</pre>
                </div>

                <div class="box has-background-grey-darker mt-3">
                    <p class="has-text-light"><strong>Announcement Example</strong></p>
                    <pre style="background-color: #1a1a1a; padding: 1rem; border-radius: 5px;">__**📢 Important Announcement**__

> We are performing maintenance on __Sunday at 2:00 PM UTC__.

**Expected Duration:** 2 hours
**Affected Services:** Streaming API, Bot Commands

Stay tuned for updates!</pre>
                </div>
            </div>
        </div>

        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light">
                <h2 id="common-mistakes" class="title is-4 has-text-light">
                    <span class="icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </span>
                    Common Mistakes to Avoid
                </h2>

                <div class="columns mt-3 has-text-light">
                    <div class="column">
                        <h5 class="title is-5 has-text-warning">❌ Don't</h5>
                        <ul>
                            <li>Mix formatting characters inconsistently</li>
                            <li>Use too many nested formatting levels</li>
                            <li>Forget to close code blocks properly</li>
                            <li>Use backticks for large code snippets</li>
                            <li>Nest quotes too deeply</li>
                        </ul>
                    </div>
                    <div class="column">
                        <h5 class="title is-5 has-text-success">✅ Do</h5>
                        <ul>
                            <li>Use consistent spacing and line breaks</li>
                            <li>Keep formatting simple and clean</li>
                            <li>Test formatting before deploying</li>
                            <li>Use code blocks for multi-line code</li>
                            <li>Use headings to organize content</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-header has-background-dark">
                <p class="card-header-title has-text-light">
                    <span class="icon">
                        <i class="fas fa-bookmark"></i>
                    </span>
                    Quick Reference
                </p>
            </div>
            <div class="card-content has-background-dark">
                <div class="content has-text-light" style="font-size: 0.9rem;">
                    <p><strong>Text Formatting</strong></p>
                    <ul>
                        <li><code>**bold**</code></li>
                        <li><code>*italic*</code></li>
                        <li><code>__underline__</code></li>
                        <li><code>~~strike~~</code></li>
                    </ul>
                    <p class="mt-3"><strong>Code</strong></p>
                    <ul>
                        <li><code>`code`</code></li>
                        <li><code>```block```</code></li>
                        <li><code>```lang```</code></li>
                    </ul>
                    <p class="mt-3"><strong>Structure</strong></p>
                    <ul>
                        <li><code>## Heading</code></li>
                        <li><code>&gt; Quote</code></li>
                        <li><code>- List</code></li>
                        <li><code>---</code></li>
                    </ul>
                    <p class="mt-3"><strong>Tagging & Emojis</strong></p>
                    <ul>
                        <li><code>&lt;@id&gt;</code> - User</li>
                        <li><code>&lt;#id&gt;</code> - Channel</li>
                        <li><code>&lt;@&amp;id&gt;</code> - Role</li>
                        <li><code>&lt;:name:id&gt;</code> - Emoji</li>
                        <li><code>&lt;a:name:id&gt;</code> - Anim. Emoji</li>
                    </ul>
                    <p class="mt-3"><strong>Special</strong></p>
                    <ul>
                        <li><code>[text](url)</code> - Link</li>
                        <li><code>&lt;t:id&gt;</code> - Timestamp</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-header has-background-dark">
                <p class="card-header-title has-text-light">
                    <span class="icon">
                        <i class="fas fa-code-branch"></i>
                    </span>
                    Related Guides
                </p>
            </div>
            <div class="card-content has-background-dark">
                <div class="content has-text-light">
                    <ul>
                        <li><a href="custom_command_variables.php" class="has-text-light">
                            <span class="icon">
                                <i class="fas fa-terminal"></i>
                            </span>
                            Custom Command Variables
                        </a></li>
                        <li><a href="setup.php" class="has-text-light">
                            <span class="icon">
                                <i class="fas fa-rocket"></i>
                            </span>
                            First Time Setup
                        </a></li>
                        <li><a href="https://api.botofthespecter.com/docs" target="_blank" class="has-text-light">
                            <span class="icon">
                                <i class="fas fa-code"></i>
                            </span>
                            API Documentation
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="card has-background-dark has-shadow">
            <div class="card-header has-background-dark">
                <p class="card-header-title has-text-light">
                    <span class="icon">
                        <i class="fas fa-life-ring"></i>
                    </span>
                    Need Help?
                </p>
            </div>
            <div class="card-content has-background-dark">
                <p class="has-text-light">Having trouble with Discord Markdown?</p>
                <div class="content has-text-light">
                    <ul>
                        <li><a href="https://github.com/YourStreamingTools/BotOfTheSpecter/issues" target="_blank" class="has-text-light">
                            <span class="icon">
                                <i class="fab fa-github"></i>
                            </span>
                            GitHub Issues
                        </a></li>
                        <li><a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" class="has-text-light">
                            <span class="icon">
                                <i class="fab fa-discord"></i>
                            </span>
                            Discord Server
                        </a></li>
                        <li><a href="https://discord.com/developers/docs/reference/formats/markup" target="_blank" class="has-text-light">
                            <span class="icon">
                                <i class="fab fa-discord"></i>
                            </span>
                            Discord Docs
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Discord Markdown Guide';
$pageDescription = 'Learn how to use Discord Markdown to format your messages with bold, italic, code blocks, and more. Complete guide with examples.';
include 'layout.php';
?>
