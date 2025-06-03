#!/bin/bash

# Load environment variables
source .certbot.env

# Run Certbot with environment-based values
certbot certonly --manual \
  --preferred-challenges=$CHALLENGE_TYPE \
  --server $ACME_SERVER \
  --agree-tos \
  -d $MAIN_DOMAIN \
  -d $WILDCARD_DOMAIN \
  -d $SUBDOMAIN
