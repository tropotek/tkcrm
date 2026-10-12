#!/bin/sh
# The worker container of the shipped image: the queue (every email the site sends) and the
# schedule (recurring billing, reminders, the review digest, site checks, pruning) side by side.
# Either one ending, or the container being stopped, ends both, and Docker starts it again.
cd /app

# The queue worker is a long-lived process: it ends itself every hour to hand its memory back.
php artisan queue:work --tries=3 --max-time=3600 &
queue=$!
php artisan schedule:work &
schedule=$!

# A stop lets the job in hand finish: queue:work ends after it on a TERM.
trap 'kill -TERM "$queue" "$schedule" 2>/dev/null' TERM INT

wait -n
kill -TERM "$queue" "$schedule" 2>/dev/null
wait
