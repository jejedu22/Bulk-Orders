#!/bin/sh
# Attend que l'application réponde (CI) : page de connexion et accueil,
# qui interroge la base de données.
set -e

for i in $(seq 1 90); do
    if [ "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/login)" = 200 ]; then
        break
    fi
    if [ "$(docker inspect -f '{{.State.Running}}' app)" != true ]; then
        echo "Le conteneur s'est arrêté" >&2
        exit 1
    fi
    sleep 2
done

for path in /login /; do
    code=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:8080$path")
    echo "$path : $code"
    test "$code" = 200
done
