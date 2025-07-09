class DonationEventHandler:
    def __init__(self, sio, logger, get_clients, broadcast_with_globals=None):
        self.sio = sio
        self.logger = logger
        self.get_clients = get_clients
        self.broadcast_with_globals = broadcast_with_globals

    async def handle_fourthwall_event(self, code, data):
        self.logger.info(f"Handling FOURTHWALL event with data: {data}")
        # Use global broadcasting if available, otherwise fall back to direct emission
        if self.broadcast_with_globals:
            count = await self.broadcast_with_globals("FOURTHWALL", data, code)
        else:
            count = 0
            if code in self.get_clients():
                for client in self.get_clients()[code]:
                    sid = client['sid']
                    await self.sio.emit("FOURTHWALL", data, to=sid)
                    self.logger.info(f"Emitted FOURTHWALL event to client {sid}")
                    count += 1
        self.logger.info(f"Broadcasted FOURTHWALL event to {count} clients")
        return count

    async def handle_kofi_event(self, code, data):
        self.logger.info(f"Handling KOFI event with data: {data}")
        # Use global broadcasting if available, otherwise fall back to direct emission
        if self.broadcast_with_globals:
            count = await self.broadcast_with_globals("KOFI", data, code)
        else:
            count = 0
            if code in self.get_clients():
                for client in self.get_clients()[code]:
                    sid = client['sid']
                    await self.sio.emit("KOFI", data, to=sid)
                    self.logger.info(f"Emitted KOFI event to client {sid}")
                    count += 1
        self.logger.info(f"Broadcasted KOFI event to {count} clients")
        return count

    async def handle_patreon_event(self, code, data):
        self.logger.info(f"Handling PATREON event with data: {data}")
        # Use global broadcasting if available, otherwise fall back to direct emission
        if self.broadcast_with_globals:
            count = await self.broadcast_with_globals("PATREON", data, code)
        else:
            count = 0
            if code in self.get_clients():
                for client in self.get_clients()[code]:
                    sid = client['sid']
                    await self.sio.emit("PATREON", data, to=sid)
                    self.logger.info(f"Emitted PATREON event to client {sid}")
                    count += 1
        self.logger.info(f"Broadcasted PATREON event to {count} clients")
        return count

    async def handle_streamlabs_event(self, code, data):
        self.logger.info(f"Handling STREAMLABS event with data: {data}")
        # Use global broadcasting if available, otherwise fall back to direct emission
        if self.broadcast_with_globals:
            count = await self.broadcast_with_globals("STREAMLABS", data, code)
        else:
            count = 0
            if code in self.get_clients():
                for client in self.get_clients()[code]:
                    sid = client['sid']
                    await self.sio.emit("STREAMLABS", data, to=sid)
                    self.logger.info(f"Emitted STREAMLABS event to client {sid}")
                    count += 1
        self.logger.info(f"Broadcasted STREAMLABS event to {count} clients")
        return count

    async def handle_streamelements_event(self, code, data):
        self.logger.info(f"Handling STREAMELEMENTS event with data: {data}")
        # Use global broadcasting if available, otherwise fall back to direct emission
        if self.broadcast_with_globals:
            count = await self.broadcast_with_globals("STREAMELEMENTS", data, code)
        else:
            count = 0
            if code in self.get_clients():
                for client in self.get_clients()[code]:
                    sid = client['sid']
                    await self.sio.emit("STREAMELEMENTS", data, to=sid)
                    self.logger.info(f"Emitted STREAMELEMENTS event to client {sid}")
                    count += 1
        self.logger.info(f"Broadcasted STREAMELEMENTS event to {count} clients")
        return count

    async def handle_generic_donation_event(self, code, event_type, data):
        self.logger.info(f"Handling {event_type} event with data: {data}")
        # Use global broadcasting if available, otherwise fall back to direct emission
        if self.broadcast_with_globals:
            count = await self.broadcast_with_globals(event_type.upper(), data, code)
        else:
            count = 0
            if code in self.get_clients():
                for client in self.get_clients()[code]:
                    sid = client['sid']
                    await self.sio.emit(event_type.upper(), data, to=sid)
                    self.logger.info(f"Emitted {event_type} event to client {sid}")
                    count += 1
        self.logger.info(f"Broadcasted {event_type} event to {count} clients")
        return count

    def validate_donation_data(self, data, required_fields=None):
        if required_fields is None:
            required_fields = ['amount', 'username']
        missing_fields = []
        for field in required_fields:
            if field not in data or not data[field]:
                missing_fields.append(field)
        if missing_fields:
            self.logger.warning(f"Missing required fields in donation data: {missing_fields}")
            return False, missing_fields
        return True, []

    def format_donation_amount(self, amount, currency='USD'):
        try:
            amount_float = float(amount)
            if currency.upper() == 'USD':
                return f"${amount_float:.2f}"
            else:
                return f"{amount_float:.2f} {currency}"
        except (ValueError, TypeError):
            self.logger.warning(f"Invalid amount format: {amount}")
            return str(amount)

    def sanitize_donation_message(self, message, max_length=500):
        if not message:
            return ""
        # Basic sanitization
        sanitized = str(message).strip()
        # Remove potential harmful characters
        forbidden_chars = ['<', '>', '"', "'", '&']
        for char in forbidden_chars:
            sanitized = sanitized.replace(char, '')
        # Limit length
        if len(sanitized) > max_length:
            sanitized = sanitized[:max_length] + "..."
        return sanitized

    async def log_donation_event(self, platform, code, data):
        try:
            log_data = {
                'platform': platform,
                'code': code,
                'amount': data.get('amount'),
                'currency': data.get('currency', 'USD'),
                'username': data.get('username'),
                'message': data.get('message', ''),
                'timestamp': data.get('timestamp')
            }
            self.logger.info(f"Donation logged: {log_data}")
            # Here we could add database logging if needed
        except Exception as e:
            self.logger.error(f"Error logging donation event: {e}")