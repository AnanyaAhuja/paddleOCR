#!/bin/bash
set -e

CRT="docker/proxy/ssl/star.starter.local.crt"
KEY="docker/proxy/ssl/star.starter.local.key"

mkdir -p docker/proxy/ssl

# Keep an existing, still-valid certificate. Trusting the cert is a one-time sudo
# step (`security add-trusted-cert`), and regenerating silently invalidates it —
# every site then throws a browser warning until the user re-trusts by hand. So
# `make setup` is safe to re-run. Pass FORCE=1 to replace the cert deliberately.
if [ -z "$FORCE" ] && [ -f "$CRT" ] && [ -f "$KEY" ]; then
    # -checkend 0: still valid right now. Expired certs are replaced automatically.
    if openssl x509 -in "$CRT" -noout -checkend 0 >/dev/null 2>&1; then
        EXPIRY=$(openssl x509 -in "$CRT" -noout -enddate | cut -d= -f2)
        echo "SSL certificate already present and valid until $EXPIRY — keeping it."
        echo "  (it stays trusted; run 'FORCE=1 make ssl' to replace it)"
        exit 0
    fi
    echo "Existing SSL certificate has expired — regenerating..."
fi

openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout "$KEY" \
    -out "$CRT" \
    -subj "/CN=*.starter.local" \
    -addext "subjectAltName=DNS:starter.local,DNS:*.starter.local"

echo "SSL certificates generated in docker/proxy/ssl/"
echo ""
echo "To trust the certificate on macOS, run:"
echo "  sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain docker/proxy/ssl/star.starter.local.crt"
