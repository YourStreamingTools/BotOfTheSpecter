import pyttsx3
import os

engine = pyttsx3.init('espeak')
output_dir = "/var/www/tts" 
os.makedirs(output_dir, exist_ok=True)
output_file = os.path.join(output_dir, "speech.mp3")  
engine.save_to_file("This is an example of offline speech generation.", output_file)
engine.runAndWait()