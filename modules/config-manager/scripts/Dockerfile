# Config Manager – background scheduler image
# Bakes in netmiko/mysql-connector/cryptography at BUILD time so restarting
# or recreating this container never needs a manual `pip install` again.
FROM python:3.11-slim

RUN pip install --no-cache-dir \
        mysql-connector-python \
        cryptography \
        netmiko \
    && rm -rf /root/.cache/pip

COPY . /scripts

CMD ["bash", "-c", "while true; do python3 /scripts/scheduler.py --db-host \"$DB_SERVER\" --db-name \"$DB_NAME\" --db-user \"$DB_USER\" --db-pass \"$DB_PASSWORD\"; sleep 60; done"]
