import asyncio
import logging
import ssl
import websockets

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("WebSocketServer")
logging.basicConfig(filename='websocket.log', level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

ssl_context = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
ssl_context.load_cert_chain('fullchain.pem', keyfile='privkey.pem')

async def server(websocket, path):
    channel_name = None

    try:
        async for message in websocket:
            parts = message.split(":")
            if len(parts) == 2:
                if parts[0] == "channel_name":
                    channel_name = parts[1]
                    logger.info(f"Received channel_name: {channel_name}")
                elif parts[0] == "walkon":
                    logger.info("Received walkon")
                    user_message = await websocket.recv()
                    user_parts = user_message.split(":")
                    if len(user_parts) == 2 and user_parts[0] == "user_walkon":
                        username = user_parts[1]
                        logger.info(f"Received user_walkon: {username}")
                elif parts[0] == "death":
                    logger.info("Received death message")
    except websockets.exceptions.ConnectionClosedError as e:
        logger.error(f"Connection closed unexpectedly: {e}")
    except Exception as e:
        logger.error(f"An error occurred: {e}")

start_server = websockets.serve(server, "0.0.0.0", 8765, ssl=ssl_context)
asyncio.get_event_loop().run_until_complete(start_server)
asyncio.get_event_loop().run_forever()