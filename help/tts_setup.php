<?php
ob_start();
?>
<nav class="breadcrumb has-text-light" aria-label="breadcrumbs" style="margin-bottom: 2rem; background-color: rgba(255, 255, 255, 0.05); padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.1);">
    <ul>
        <li><a href="index.php" class="has-text-light">Home</a> <span style="color: #fff;">→</span></li>
        <li class="is-active"><a aria-current="page" class="has-text-link has-text-weight-bold">Text-to-Speech (TTS) Guide</a></li>
    </ul>
</nav>
<section class="section">
    <div class="container">
        <h1 class="title is-2 has-text-light">Text-to-Speech (TTS) Module</h1>
        <p class="subtitle has-text-light">Learn how to use the TTS module and choose from our available voices</p>
        <div class="content has-text-light">
            <h2 class="title is-4 has-text-light">What is TTS?</h2>
            <p>
                The Text-to-Speech (TTS) module allows BotOfTheSpecter to read messages aloud in your stream. 
                You can customize which voice is used, and the TTS will play through your audio overlay. 
                This is perfect for announcements, alerts, and enhancing viewer engagement.
            </p>
            <h2 class="title is-4 has-text-light">Setting Up TTS</h2>
            <ol>
                <li>Navigate to the <strong>TTS Settings</strong> section in the BotOfTheSpecter dashboard</li>
                <li>Choose your preferred voice from the available options below</li>
                <li>Set up your audio overlay to hear TTS output (see <a href="obs_audio_monitoring.php" class="has-text-link">OBS Audio Monitoring</a>)</li>
                <li>Test your setup with a sample message</li>
            </ol>
            <div class="notification is-info has-background-dark has-text-light" style="border-radius: 8px;">
                <strong>Note:</strong> All TTS audio is played through your configured audio overlay. Make sure you have the correct overlay URL in your OBS browser source and audio monitoring enabled.
            </div>
            <h2 class="title is-4 has-text-light">Available Voices</h2>
            <p>Click the play button next to each voice to hear a sample:</p>
            <div class="columns is-multiline is-desktop mt-5">
                <!-- Alloy Voice -->
                <div class="column is-6">
                    <div class="card has-background-dark has-shadow" style="height: 100%; border: 1px solid rgba(255, 255, 255, 0.1);">
                        <div class="card-content has-background-dark has-text-light">
                            <h3 class="title is-5 has-text-light">Alloy</h3>
                            <p class="subtitle is-6" style="color: #b5bdc9;">Clear, crisp, and professional</p>
                            <div style="margin-top: 1rem; display: flex; align-items: center; gap: 1rem;">
                                <button class="button is-link is-small voice-play-button" onclick="playVoiceSample('alloy')">
                                    <span class="icon is-small">
                                        <i class="fas fa-play"></i>
                                    </span>
                                    <span>Play Sample</span>
                                </button>
                            </div>
                            <audio id="audio-alloy" style="display: none;">
                                <source src="https://cdn.botofthespecter.com/help/tts/alloy_sample.mp3" type="audio/mpeg">
                                <source src="https://cdn.botofthespecter.com/help/tts/alloy_sample.wav" type="audio/wav">
                            </audio>
                        </div>
                    </div>
                </div>
                <!-- Ash Voice -->
                <div class="column is-6">
                    <div class="card has-background-dark has-shadow" style="height: 100%; border: 1px solid rgba(255, 255, 255, 0.1);">
                        <div class="card-content has-background-dark has-text-light">
                            <h3 class="title is-5 has-text-light">Ash</h3>
                            <p class="subtitle is-6" style="color: #b5bdc9;">Warm and friendly</p>
                            <div style="margin-top: 1rem; display: flex; align-items: center; gap: 1rem;">
                                <button class="button is-link is-small voice-play-button" onclick="playVoiceSample('ash')">
                                    <span class="icon is-small">
                                        <i class="fas fa-play"></i>
                                    </span>
                                    <span>Play Sample</span>
                                </button>
                            </div>
                            <audio id="audio-ash" style="display: none;">
                                <source src="https://cdn.botofthespecter.com/help/tts/ash_sample.mp3" type="audio/mpeg">
                                <source src="https://cdn.botofthespecter.com/help/tts/ash_sample.wav" type="audio/wav">
                            </audio>
                        </div>
                    </div>
                </div>
                <!-- Ballad Voice -->
                <div class="column is-6">
                    <div class="card has-background-dark has-shadow" style="height: 100%; border: 1px solid rgba(255, 255, 255, 0.1);">
                        <div class="card-content has-background-dark has-text-light">
                            <h3 class="title is-5 has-text-light">Ballad</h3>
                            <p class="subtitle is-6" style="color: #b5bdc9;">Melodic and expressive</p>
                            <div style="margin-top: 1rem; display: flex; align-items: center; gap: 1rem;">
                                <button class="button is-link is-small voice-play-button" onclick="playVoiceSample('ballad')">
                                    <span class="icon is-small">
                                        <i class="fas fa-play"></i>
                                    </span>
                                    <span>Play Sample</span>
                                </button>
                            </div>
                            <audio id="audio-ballad" style="display: none;">
                                <source src="https://cdn.botofthespecter.com/help/tts/ballad_sample.mp3" type="audio/mpeg">
                                <source src="https://cdn.botofthespecter.com/help/tts/ballad_sample.wav" type="audio/wav">
                            </audio>
                        </div>
                    </div>
                </div>
                <!-- Coral Voice -->
                <div class="column is-6">
                    <div class="card has-background-dark has-shadow" style="height: 100%; border: 1px solid rgba(255, 255, 255, 0.1);">
                        <div class="card-content has-background-dark has-text-light">
                            <h3 class="title is-5 has-text-light">Coral</h3>
                            <p class="subtitle is-6" style="color: #b5bdc9;">Energetic and bright</p>
                            <div style="margin-top: 1rem; display: flex; align-items: center; gap: 1rem;">
                                <button class="button is-link is-small voice-play-button" onclick="playVoiceSample('coral')">
                                    <span class="icon is-small">
                                        <i class="fas fa-play"></i>
                                    </span>
                                    <span>Play Sample</span>
                                </button>
                            </div>
                            <audio id="audio-coral" style="display: none;">
                                <source src="https://cdn.botofthespecter.com/help/tts/coral_sample.mp3" type="audio/mpeg">
                                <source src="https://cdn.botofthespecter.com/help/tts/coral_sample.wav" type="audio/wav">
                            </audio>
                        </div>
                    </div>
                </div>
                <!-- Echo Voice -->
                <div class="column is-6">
                    <div class="card has-background-dark has-shadow" style="height: 100%; border: 1px solid rgba(255, 255, 255, 0.1);">
                        <div class="card-content has-background-dark has-text-light">
                            <h3 class="title is-5 has-text-light">Echo</h3>
                            <p class="subtitle is-6" style="color: #b5bdc9;">Deep and resonant</p>
                            <div style="margin-top: 1rem; display: flex; align-items: center; gap: 1rem;">
                                <button class="button is-link is-small voice-play-button" onclick="playVoiceSample('echo')">
                                    <span class="icon is-small">
                                        <i class="fas fa-play"></i>
                                    </span>
                                    <span>Play Sample</span>
                                </button>
                            </div>
                            <audio id="audio-echo" style="display: none;">
                                <source src="https://cdn.botofthespecter.com/help/tts/echo_sample.mp3" type="audio/mpeg">
                                <source src="https://cdn.botofthespecter.com/help/tts/echo_sample.wav" type="audio/wav">
                            </audio>
                        </div>
                    </div>
                </div>
                <!-- Fable Voice -->
                <div class="column is-6">
                    <div class="card has-background-dark has-shadow" style="height: 100%; border: 1px solid rgba(255, 255, 255, 0.1);">
                        <div class="card-content has-background-dark has-text-light">
                            <h3 class="title is-5 has-text-light">Fable</h3>
                            <p class="subtitle is-6" style="color: #b5bdc9;">Storyteller voice</p>
                            <div style="margin-top: 1rem; display: flex; align-items: center; gap: 1rem;">
                                <button class="button is-link is-small voice-play-button" onclick="playVoiceSample('fable')">
                                    <span class="icon is-small">
                                        <i class="fas fa-play"></i>
                                    </span>
                                    <span>Play Sample</span>
                                </button>
                            </div>
                            <audio id="audio-fable" style="display: none;">
                                <source src="https://cdn.botofthespecter.com/help/tts/fable_sample.mp3" type="audio/mpeg">
                                <source src="https://cdn.botofthespecter.com/help/tts/fable_sample.wav" type="audio/wav">
                            </audio>
                        </div>
                    </div>
                </div>
                <!-- Nova Voice -->
                <div class="column is-6">
                    <div class="card has-background-dark has-shadow" style="height: 100%; border: 1px solid rgba(255, 255, 255, 0.1);">
                        <div class="card-content has-background-dark has-text-light">
                            <h3 class="title is-5 has-text-light">Nova</h3>
                            <p class="subtitle is-6" style="color: #b5bdc9;">Fast-paced and dynamic</p>
                            <div style="margin-top: 1rem; display: flex; align-items: center; gap: 1rem;">
                                <button class="button is-link is-small voice-play-button" onclick="playVoiceSample('nova')">
                                    <span class="icon is-small">
                                        <i class="fas fa-play"></i>
                                    </span>
                                    <span>Play Sample</span>
                                </button>
                            </div>
                            <audio id="audio-nova" style="display: none;">
                                <source src="https://cdn.botofthespecter.com/help/tts/nova_sample.mp3" type="audio/mpeg">
                                <source src="https://cdn.botofthespecter.com/help/tts/nova_sample.wav" type="audio/wav">
                            </audio>
                        </div>
                    </div>
                </div>
                <!-- Onyx Voice -->
                <div class="column is-6">
                    <div class="card has-background-dark has-shadow" style="height: 100%; border: 1px solid rgba(255, 255, 255, 0.1);">
                        <div class="card-content has-background-dark has-text-light">
                            <h3 class="title is-5 has-text-light">Onyx</h3>
                            <p class="subtitle is-6" style="color: #b5bdc9;">Smooth and sophisticated</p>
                            <div style="margin-top: 1rem; display: flex; align-items: center; gap: 1rem;">
                                <button class="button is-link is-small voice-play-button" onclick="playVoiceSample('onyx')">
                                    <span class="icon is-small">
                                        <i class="fas fa-play"></i>
                                    </span>
                                    <span>Play Sample</span>
                                </button>
                            </div>
                            <audio id="audio-onyx" style="display: none;">
                                <source src="https://cdn.botofthespecter.com/help/tts/onyx_sample.mp3" type="audio/mpeg">
                                <source src="https://cdn.botofthespecter.com/help/tts/onyx_sample.wav" type="audio/wav">
                            </audio>
                        </div>
                    </div>
                </div>
                <!-- Sage Voice -->
                <div class="column is-6">
                    <div class="card has-background-dark has-shadow" style="height: 100%; border: 1px solid rgba(255, 255, 255, 0.1);">
                        <div class="card-content has-background-dark has-text-light">
                            <h3 class="title is-5 has-text-light">Sage</h3>
                            <p class="subtitle is-6" style="color: #b5bdc9;">Thoughtful and calm</p>
                            <div style="margin-top: 1rem; display: flex; align-items: center; gap: 1rem;">
                                <button class="button is-link is-small voice-play-button" onclick="playVoiceSample('sage')">
                                    <span class="icon is-small">
                                        <i class="fas fa-play"></i>
                                    </span>
                                    <span>Play Sample</span>
                                </button>
                            </div>
                            <audio id="audio-sage" style="display: none;">
                                <source src="https://cdn.botofthespecter.com/help/tts/sage_sample.mp3" type="audio/mpeg">
                                <source src="https://cdn.botofthespecter.com/help/tts/sage_sample.wav" type="audio/wav">
                            </audio>
                        </div>
                    </div>
                </div>
                <!-- Shimmer Voice -->
                <div class="column is-6">
                    <div class="card has-background-dark has-shadow" style="height: 100%; border: 1px solid rgba(255, 255, 255, 0.1);">
                        <div class="card-content has-background-dark has-text-light">
                            <h3 class="title is-5 has-text-light">Shimmer</h3>
                            <p class="subtitle is-6" style="color: #b5bdc9;">Gentle and uplifting</p>
                            <div style="margin-top: 1rem; display: flex; align-items: center; gap: 1rem;">
                                <button class="button is-link is-small voice-play-button" onclick="playVoiceSample('shimmer')">
                                    <span class="icon is-small">
                                        <i class="fas fa-play"></i>
                                    </span>
                                    <span>Play Sample</span>
                                </button>
                            </div>
                            <audio id="audio-shimmer" style="display: none;">
                                <source src="https://cdn.botofthespecter.com/help/tts/shimmer_sample.mp3" type="audio/mpeg">
                                <source src="https://cdn.botofthespecter.com/help/tts/shimmer_sample.wav" type="audio/wav">
                            </audio>
                        </div>
                    </div>
                </div>
                <!-- Verse Voice -->
                <div class="column is-6">
                    <div class="card has-background-dark has-shadow" style="height: 100%; border: 1px solid rgba(255, 255, 255, 0.1);">
                        <div class="card-content has-background-dark has-text-light">
                            <h3 class="title is-5 has-text-light">Verse</h3>
                            <p class="subtitle is-6" style="color: #b5bdc9;">Rhythmic and poetic</p>
                            <div style="margin-top: 1rem; display: flex; align-items: center; gap: 1rem;">
                                <button class="button is-link is-small voice-play-button" onclick="playVoiceSample('verse')">
                                    <span class="icon is-small">
                                        <i class="fas fa-play"></i>
                                    </span>
                                    <span>Play Sample</span>
                                </button>
                            </div>
                            <audio id="audio-verse" style="display: none;">
                                <source src="https://cdn.botofthespecter.com/help/tts/verse_sample.mp3" type="audio/mpeg">
                                <source src="https://cdn.botofthespecter.com/help/tts/verse_sample.wav" type="audio/wav">
                            </audio>
                        </div>
                    </div>
                </div>
            </div>
            <h2 class="title is-4 has-text-light mt-6">Using TTS with Channel Points</h2>
            <p>TTS is triggered through Twitch Channel Points redemptions:</p>
            <div class="box has-background-dark" style="border-left: 4px solid #3273dc;">
                <p class="has-text-light"><strong>Viewers can redeem a Channel Point reward to have a message read aloud</strong></p>
                <p class="has-text-grey is-size-7" style="margin-top: 0.5rem;">The message will be read using the voice you've selected in the TTS settings on the dashboard</p>
            </div>
            <h2 class="title is-4 has-text-light">Troubleshooting TTS</h2>
            <ul>
                <li><strong>No audio output:</strong> Verify that your audio overlay is correctly configured in OBS and that audio monitoring is enabled</li>
                <li><strong>Wrong voice playing:</strong> Check that you've selected the correct voice in the TTS settings on the dashboard</li>
                <li><strong>Audio too quiet or too loud:</strong> Adjust the volume slider on the audio overlay source in OBS</li>
                <li><strong>TTS not responding:</strong> Ensure the TTS module is enabled on the dashboard and the bot has proper permissions</li>
            </ul>
            <h2 class="title is-4 has-text-light">Additional Resources</h2>
            <ul>
                <li><a href="index.php" class="has-text-link">Back to Help Home</a></li>
                <li><a href="obs_audio_monitoring.php" class="has-text-link">OBS Audio Monitoring Setup</a></li>
                <li><a href="troubleshooting.php" class="has-text-link">Troubleshooting Guide</a></li>
            </ul>
        </div>
    </div>
</section>

<script>
let currentlyPlayingVoice = null;
function playVoiceSample(voiceName) {
    const audioElement = document.getElementById('audio-' + voiceName);
    const button = (typeof event !== 'undefined' && event.target) ? event.target.closest('button') : null;
    if (!audioElement) return;
    if (!button) return;
    // If this voice is already playing, stop it
    if (currentlyPlayingVoice === voiceName && !audioElement.paused) {
        audioElement.pause();
        audioElement.currentTime = 0;
        button.innerHTML = '<span class="icon is-small"><i class="fas fa-play"></i></span><span>Play Sample</span>';
        currentlyPlayingVoice = null;
        return;
    }
    // Stop any other currently playing audio
    const allAudioElements = document.querySelectorAll('audio');
    const allButtons = document.querySelectorAll('.voice-play-button');
    allAudioElements.forEach((audio, index) => {
        if (audio !== audioElement) {
            audio.pause();
            audio.currentTime = 0;
            // Reset other buttons
            if (allButtons[index]) {
                allButtons[index].innerHTML = '<span class="icon is-small"><i class="fas fa-play"></i></span><span>Play Sample</span>';
            }
        }
    });
    // Play the selected voice sample
    audioElement.play().catch(error => {
        console.error('Error playing audio:', error);
        alert('Could not play audio sample. The file may not be available.');
    });
    // Update button text to show "Stop"
    button.innerHTML = '<span class="icon is-small"><i class="fas fa-stop"></i></span><span>Stop</span>';
    currentlyPlayingVoice = voiceName;
    // Listen for when audio ends
    audioElement.onended = function() {
        button.innerHTML = '<span class="icon is-small"><i class="fas fa-play"></i></span><span>Play Sample</span>';
        currentlyPlayingVoice = null;
    };
}
</script>
<?php
$content = ob_get_clean();
$pageTitle = 'Text-to-Speech (TTS) Guide';
include 'layout.php';
?>
