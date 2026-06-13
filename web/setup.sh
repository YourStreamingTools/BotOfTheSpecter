#!/usr/bin/env bash
#
# One-shot bootstrap for the BotOfTheSpecter web server.
#
# Target: fresh Ubuntu 26.04 LTS install. Installs Caddy + PHP-FPM 8.5,
# lays out /var/www/ docroots for all PHP frontends and media directories,
# drops the Caddyfile, enables services. Caddy handles SSL automatically —
# no certbot, no vhosts.
#
# Run as root:
#   curl -sSL https://raw.githubusercontent.com/YourStreamingTools/BotOfTheSpecter/main/web/setup.sh | bash
#
# After this script finishes you still need to:
#   1. Edit /etc/caddy/caddy.env and fill in CF_API_TOKEN
#   2. Point DNS A records at this server's public IP
#   3. systemctl restart caddy
#
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Must be run as root." >&2
    exit 1
fi

echo "==> Updating apt and installing base packages..."
apt update
apt upgrade -y
apt install -y \
    curl wget gnupg ca-certificates apt-transport-https \
    debian-keyring debian-archive-keyring \
    php8.5 php8.5-fpm php8.5-cli \
    php8.5-mysql php8.5-curl php8.5-mbstring php8.5-xml \
    php8.5-zip php8.5-gd php8.5-intl php8.5-bcmath php8.5-readline \
    mysql-client \
    ufw fail2ban git rsync htop vim tmux unzip

echo "==> Adding Caddy apt repo..."
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
    | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
    > /etc/apt/sources.list.d/caddy-stable.list
apt update
apt install -y caddy

echo "==> Adding Cloudflare DNS plugin to Caddy..."
# All zones are on Cloudflare, so DNS-01 via this plugin is the global ACME
# challenge method (set in the Caddyfile's global block). Without this plugin
# Caddy can't issue ANY cert.
caddy add-package github.com/caddy-dns/cloudflare || {
    echo "ERROR: 'caddy add-package' failed. No certs will issue until the"
    echo "       Cloudflare plugin is installed. Re-run after this script:"
    echo "       caddy add-package github.com/caddy-dns/cloudflare"
    exit 1
}

echo "==> Creating /var/www directory tree..."
mkdir -p /var/www/{config,home,html,dashboard,members,overlay,support,roadmap,specterbotapp,specterbotsystems,yourchat,cdn,walkons,media,soundalerts,tts,usermusic,videoalerts,yourlinks.click}
mkdir -p /var/log/caddy
chown -R www-data:www-data /var/www
find /var/www -type d -exec chmod 2755 {} \;
chmod 2750 /var/www/config            # shared secrets — tighter perms
chown caddy:caddy /var/log/caddy

echo "==> Writing Caddy env file (placeholder for CF_API_TOKEN)..."
install -m 640 -o root -g caddy /dev/null /etc/caddy/caddy.env
cat > /etc/caddy/caddy.env <<'EOF'
# Cloudflare API token used by Caddy to solve DNS-01 challenges for EVERY
# domain (all four zones are on Cloudflare). Without this, no certs issue.
#
# Create the token at https://dash.cloudflare.com/profile/api-tokens with:
#   Permissions: Zone:Read + DNS:Edit
#   Zone Resources: include the four zones — botofthespecter.com,
#                   botspecter.com, yourlinks.click, specterbot.app
#
# After filling this in: systemctl restart caddy
CF_API_TOKEN=REPLACE_ME
EOF

echo "==> Wiring env file into the caddy systemd unit..."
mkdir -p /etc/systemd/system/caddy.service.d
cat > /etc/systemd/system/caddy.service.d/override.conf <<'EOF'
[Service]
EnvironmentFile=/etc/caddy/caddy.env
EOF
systemctl daemon-reload

echo "==> Enabling PHP-FPM..."
systemctl enable --now php8.5-fpm

echo "==> Configuring firewall..."
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 443/udp    # HTTP/3 (QUIC)
ufw --force enable

echo "==> Validating PHP-FPM is answering on its socket..."
ls -la /run/php/php8.5-fpm.sock || {
    echo "ERROR: PHP-FPM socket missing. Check 'systemctl status php8.5-fpm'." >&2
    exit 1
}

cat <<'POST'

============================================================
Base install complete.

NEXT STEPS (manual):

1. Drop the Caddyfile into place:
       cp /var/www/web/Caddyfile /etc/caddy/Caddyfile

2. Edit /etc/caddy/caddy.env and replace REPLACE_ME with a real
   Cloudflare API token (Zone:Read + DNS:Edit on the four zones).

3. Point DNS A records for every hostname in the Caddyfile at this
   server's public IP. Caddy can't get certs until DNS resolves here.

4. Validate the Caddyfile then start Caddy:
       caddy validate --config /etc/caddy/Caddyfile
       systemctl enable --now caddy
       journalctl -fu caddy

   Watch the cert issuance go past in the logs. Every domain in the
   Caddyfile gets a Let's Encrypt cert automatically within ~30 seconds.

============================================================
POST
