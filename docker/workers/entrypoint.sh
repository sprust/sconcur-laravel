#!/bin/sh
# Puts the demo's state back before the master starts.
#
# MySQL and RabbitMQ keep everything in tmpfs, so a restart of either leaves the schema
# and the queue gone while the application carries on pointing at them — every page then
# 500s on a missing table, and the consumer pool spins on a 404. Both commands are
# idempotent, so running them on every start costs a moment and removes a whole class of
# "it broke after a restart".
#
# Neither is allowed to stop the container. A failure here means the demo has no data
# yet, which the master can be brought up alongside and `make demo-reset` can fix; a
# container that refuses to start over it would take the telemetry panel down too, and
# with it any way to see what is wrong.
set -e

if [ -f /app/vendor/autoload.php ]; then
    echo "entrypoint: migrating"
    php /app/demo/artisan migrate --force || echo "entrypoint: migrate failed — run 'make demo-reset' once the database is up"

    echo "entrypoint: declaring queues"
    php /app/demo/artisan sconcur:rabbitmq:declare || echo "entrypoint: declare failed — run 'make demo-reset' once the broker is up"
else
    # A fresh clone: `make setup` installs the dependencies and runs both itself.
    echo "entrypoint: no vendor yet, skipping migrate and declare"
fi

exec "$@"
