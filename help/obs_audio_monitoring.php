<?php
ob_start();
?>
<nav class="breadcrumb has-text-light" aria-label="breadcrumbs" style="margin-bottom: 2rem; background-color: rgba(255, 255, 255, 0.05); padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.1);">
    <ul>
        <li><a href="index.php" class="has-text-light">Home</a> <span style="color: #fff;">→</span></li>
        <li><a href="troubleshooting.php" class="has-text-light">Troubleshooting Guide</a> <span style="color: #fff;">→</span></li>
        <li class="is-active"><a aria-current="page" class="has-text-link has-text-weight-bold">OBS Audio Monitoring Setup</a></li>
    </ul>
</nav>
<h1 class="title is-2 has-text-light">Setting Up Audio Monitoring in OBS</h1>
<p class="subtitle has-text-light">Step-by-step guide to configure audio monitoring for overlays</p>
<div class="content has-text-light">
    <h2 class="title is-4 has-text-light">Why Audio Monitoring?</h2>
    <p>Audio monitoring allows you to hear audio from your overlays (such as sound alerts, TTS, and walk-ons) directly through OBS, ensuring they play correctly during your stream.</p>
    <h2 class="title is-4 has-text-light">Steps to Set Up Audio Monitoring</h2>
    <ol>
        <li><strong>Open OBS Studio:</strong> Launch OBS on your computer.</li>
        <li><strong>Go to Settings:</strong> Click on the "Settings" button in the bottom-right corner of the OBS window.<br><img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring/Settings_Button.png" alt="OBS Settings Button" style="max-width: 100%; height: auto;"></li>
        <li><strong>Select the Audio Tab:</strong> In the settings window, click on the "Audio" tab.<br><img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring/Access_Audio_Settings.png" alt="Access Audio Settings in OBS" style="max-width: 100%; height: auto;"></li>
        <li><strong>Configure Monitoring Device:</strong><br><img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring/Configure_Monitoring_Device.png" alt="Configure Monitoring Device in OBS" style="max-width: 100%; height: auto;">
            <ul>
                <li>Under "Monitoring Device," select your desired audio output device (e.g., your headphones or speakers).</li>
                <li>If you want to monitor all audio, choose "Default" or your primary audio device.</li>
            </ul>
        </li>
        <li><strong>Add Browser Source for Overlay:</strong>
            <ul>
                <li>Go to the Sources box in OBS and click the "+" icon to add a new source, then select "Browser".<br><img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Add_New_Source.png" alt="Add New Source in OBS" style="max-width: 100%; height: auto;"><br><img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Add_New_Source_Browser.png" alt="Select Browser Source in OBS" style="max-width: 100%; height: auto;"></li>
                <li>When the Create/Select Source window opens, select "Create new" and name it anything you like (e.g., "Specter Overlay").<br><img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Add_New_Source_Browser_Name_Setting.png" alt="Create New Browser Source in OBS" style="max-width: 100%; height: auto;"></li>
                <li>Ensure "Make source visible" is checked and click "OK".</li>
                <li>When the Properties window for the Browser source appears, enter the correct overlay URL in the URL input box: <code>https://overlay.botofthespecter.com/alert.php?code=YOUR_API_KEY</code><br><img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Add_New_Source_Broswer_Properties_Window.png" alt="Browser Source Properties Window in OBS" style="max-width: 100%; height: auto;"></li>
                <li>Check the box that says "Control audio via OBS".</li>
                <li>Remove all the text in the Custom CSS input field.</li>
                <li>Click "OK" to save the settings.</li>
            </ul>
        </li>
        <li><strong>Configure Audio Monitoring for the Overlay Source:</strong>
            <ul>
                <li>After clicking OK, the browser source will appear in your Audio Mixer at the bottom of the OBS window.<br><img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Broswer_Source_Audio_Mixer.png" alt="Browser Source in OBS Audio Mixer" style="max-width: 100%; height: auto;"></li>
                <li>Click the three dots (⋯) next to the speaker icon for the browser source.<br><img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Broswer_Source_Audio_Mixer_Advanced_Audio_Properties.png" alt="Advanced Audio Properties Menu in OBS" style="max-width: 100%; height: auto;"></li>
                <li>In the dropdown menu, click "Advanced Audio Properties".</li>
                <li>When the Advanced Audio Properties window opens, click on the dropdown menu next to "Audio Monitoring" and set it to "Monitor and Output".<br><img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Advanced_Audio_Properties_Window.png" alt="Advanced Audio Properties Window in OBS" style="max-width: 100%; height: auto;"><br><img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Advanced_Audio_Properties_Window_Saved.png" alt="Advanced Audio Properties Window with Monitor and Output Selected" style="max-width: 100%; height: auto;"></li>
                <li>Click "Close" to close the Advanced Audio Properties window.</li>
            </ul>
        </li>
    </ol>
    <h2 class="title is-4 has-text-light">Troubleshooting</h2>
    <ul>
        <li>If you don't hear audio, check that your monitoring device is selected correctly in OBS settings.</li>
        <li>If you or your stream hears an echo on the sound alert, set Audio Monitoring to <strong>"Monitor Only (mute output)"</strong> and try again. Everyone's audio/sound setup is different, so please try changing that setting first.</li>
        <li>Ensure that the overlay URL has the correct API key from your Specter Profile page.</li>
        <li>Verify that your browser source is set to monitor audio.</li>
        <li>Check OBS's volume mixer for the browser source and ensure it's not muted.</li>
    </ul>
    <h2 class="title is-4 has-text-light">Additional Resources</h2>
    <ul>
        <li><a href="https://obsproject.com/wiki/Audio-Monitoring" class="has-text-link" target="_blank">OBS Audio Monitoring Documentation</a></li>
        <li><a href="troubleshooting.php" class="has-text-link">Back to Troubleshooting Guide</a></li>
    </ul>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'OBS Audio Monitoring Setup';
include 'layout.php';
?>