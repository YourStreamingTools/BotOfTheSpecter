import asyncio
import logging
import ssl
import websockets
import json

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("WebSocketServer")
logging.basicConfig(filename='websocket.log', level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

ssl_context = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
ssl_context.load_cert_chain('fullchain.pem', keyfile='privkey.pem')

connected_clients = set()

async def server(websocket, path):
    global connected_clients
    connected_clients.add(websocket)
    try:
        async for message in websocket:
            data = json.loads(message)
            if data['type'] == 'channel_name':
                channel_name = data['content']
                logger.info(f"Received channel_name: {channel_name}")
            elif data['type'] == 'walkon':
                logger.info("Received walkon")
                user_message = await websocket.recv()
                user_data = json.loads(user_message)
                if user_data['type'] == 'user_walkon':
                    username = user_data['content']
                    logger.info(f"Received user_walkon: {username}")
                    await broadcast(json.dumps({'type': 'user_walkon', 'content': username}))
            elif data['type'] == 'death':
                logger.info("Received death message")
                await broadcast(json.dumps({'type': 'death', 'content': 'death occurred'}))
    except websockets.exceptions.ConnectionClosedError as e:
        logger.error(f"Connection closed unexpectedly: {e}")
    except Exception as e:
        logger.error(f"An error occurred: {e}")
    finally:
        connected_clients.remove(websocket)

async def broadcast(message):
    if connected_clients:
        await asyncio.wait([client.send(message) for client in connected_clients])

start_server = websockets.serve(server, "0.0.0.0", 8765, ssl=ssl_context)
asyncio.get_event_loop().run_until_complete(start_server)
asyncio.get_event_loop().run_forever()