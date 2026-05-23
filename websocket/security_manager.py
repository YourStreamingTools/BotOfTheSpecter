import ipaddress

class SecurityManager:
    def __init__(self, logger, ips_file="/home/botofthespecter/ips.txt"):
        self.logger = logger
        self.ips_file = ips_file
        self.allowed_ips = self.load_ips(ips_file)

    def load_ips(self, ips_file):
        allowed_ips = []
        try:
            with open(ips_file, 'r') as file:
                for line in file:
                    line = line.strip()
                    if line and not line.startswith('#'):
                        try:
                            allowed_ips.append(ipaddress.ip_network(line))
                        except ValueError as e:
                            self.logger.warning(f"Invalid IP/network in {ips_file}: {line} - {e}")
            self.logger.info(f"Loaded {len(allowed_ips)} allowed IP networks from {ips_file}")
        except FileNotFoundError:
            self.logger.error(f"IPs file not found: {ips_file}")
        except Exception as e:
            self.logger.error(f"Error loading IPs file {ips_file}: {e}")
        return allowed_ips

    def is_ip_allowed(self, ip):
        try:
            ip_address = ipaddress.ip_address(ip)
            for allowed_ip in self.allowed_ips:
                if ip_address in allowed_ip:
                    return True
            return False
        except ValueError as e:
            self.logger.warning(f"Invalid IP address format: {ip} - {e}")
            return False

    def reload_ips(self):
        self.logger.info("Reloading allowed IPs from file")
        self.allowed_ips = self.load_ips(self.ips_file)

    async def ip_restriction_middleware(self, app, handler):
        async def middleware_handler(request):
            # Only apply IP restrictions to specific endpoints
            restricted_paths = ['/clients', '/notify']
            if request.path in restricted_paths:
                # The websocket server faces the public internet directly with no
                # reverse proxy in front. Any request carrying proxy-identity
                # headers is either misconfigured or an attempt to spoof the IP
                # whitelist - reject outright so attempts show up in the logs.
                for spoof_header in ('X-Forwarded-For', 'X-Real-IP'):
                    if spoof_header in request.headers:
                        self.logger.warning(
                            f"Rejected {request.path} from {request.remote} - "
                            f"unexpected {spoof_header}: {request.headers[spoof_header]}"
                        )
                        from aiohttp import web
                        return web.Response(status=403, text="Access Forbidden")
                client_ip = request.remote
                # Allow localhost (server-internal calls)
                if client_ip in ('127.0.0.1', '::1'):
                    return await handler(request)
                if not self.is_ip_allowed(client_ip):
                    self.logger.warning(f"Access denied for IP: {client_ip} on restricted path: {request.path}")
                    from aiohttp import web
                    return web.Response(status=403, text="Access Forbidden")
                self.logger.debug(f"Access granted for IP: {client_ip} on restricted path: {request.path}")
            # Allow all other requests to pass through without IP checking
            return await handler(request)
        return middleware_handler

    def add_allowed_ip(self, ip_network):
        try:
            network = ipaddress.ip_network(ip_network)
            if network not in self.allowed_ips:
                self.allowed_ips.append(network)
                self.logger.info(f"Added allowed IP/network: {ip_network}")
                return True
            else:
                self.logger.info(f"IP/network already allowed: {ip_network}")
                return False
        except ValueError as e:
            self.logger.error(f"Invalid IP/network format: {ip_network} - {e}")
            return False

    def remove_allowed_ip(self, ip_network):
        try:
            network = ipaddress.ip_network(ip_network)
            if network in self.allowed_ips:
                self.allowed_ips.remove(network)
                self.logger.info(f"Removed allowed IP/network: {ip_network}")
                return True
            else:
                self.logger.info(f"IP/network not in allowed list: {ip_network}")
                return False
        except ValueError as e:
            self.logger.error(f"Invalid IP/network format: {ip_network} - {e}")
            return False

    def get_allowed_ips(self):
        return [str(network) for network in self.allowed_ips]

    def get_stats(self):
        return {
            "allowed_networks_count": len(self.allowed_ips),
            "allowed_networks": self.get_allowed_ips(),
            "ips_file": self.ips_file
        }