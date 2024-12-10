import os
from google.cloud import texttospeech
import json

def save_voice_models():
    os.environ['GOOGLE_APPLICATION_CREDENTIALS'] = "/var/www/api/service-account-file.json"
    tts_client = texttospeech.TextToSpeechClient()
    voices = tts_client.list_voices()
    all_voices = []
    for voice in voices.voices:
        all_voices.append({
            "name": voice.name,
            "language_codes": voice.language_codes,
            "gender": texttospeech.SsmlVoiceGender(voice.ssml_gender).name,
            "natural_sample_rate_hertz": voice.natural_sample_rate_hertz
        })
    # Save the voices to the /var/www/api directory
    output_path = "/var/www/api/tts_voices.json"
    with open(output_path, "w") as json_file:
        json.dump(all_voices, json_file, indent=2)

if __name__ == "__main__":
    save_voice_models()
