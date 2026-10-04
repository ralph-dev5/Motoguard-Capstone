#!/bin/sh
# Runs the SMS queue worker only when RUN_QUEUE_WORKER=true; otherwise idles so supervisor is happy.
if [ "$RUN_QUEUE_WORKER" = "true" ]; then
    exec php /var/www/html/artisan queue:work --sleep=3 --tries=3 --max-time=3600
fi
exec sleep infinity
