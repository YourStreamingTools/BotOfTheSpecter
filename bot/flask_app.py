from flask import Flask, request
import argparse

app = Flask("TwitchWebhookServer")

def start_app(port):
    app.run(port=port)

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description="Start the Flask app")
    parser.add_argument("-port", type=int, default=5000, help="Port for the Flask app")
    args = parser.parse_args()
    start_app(args.port)