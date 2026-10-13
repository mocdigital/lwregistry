# Notification Operations

Loveworld Registry notifications use four delivery layers:

- Database notifications for the in-app bell and notifications page.
- Reverb/Echo broadcasts for live bell updates while the user is online.
- Browser notifications from the bell dropdown after the user grants permission.
- Email notifications for messages that also need mailbox delivery.

## Production Cron

Laravel's scheduler should run every minute on the production server:

```cron
* * * * * cd /path/to/lw-registry && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler currently drains the `auth`, `mail`, and `default` queues every ten seconds.
That keeps queued authentication emails, notification emails, database notification work,
and broadcast jobs moving even when a separate queue worker is not configured.

For higher-volume production traffic, prefer a process manager such as Supervisor or
systemd for a long-running worker:

```bash
php artisan queue:work --queue=auth,mail,default --sleep=3 --tries=3 --timeout=300
```

If real-time browser bell updates are enabled, also keep Reverb running:

```bash
php artisan reverb:start --host=0.0.0.0 --port=8080
```

## Browser Alerts

The notification bell includes a `Browser alerts` action. Browser notifications only work
after the signed-in user grants permission, and they require HTTPS in production. They are
a live-session enhancement: when Echo receives a notification, the bell refreshes and the
browser can show a native notification.

This is not offline web push. Offline push would require a service worker, push
subscriptions, and a dedicated web-push channel.

## Espees test checkout alerts

The gated SBO Espees test checkout sends `AdminEspeesPaymentFailedNotification`
when setup, provider confirmation, or the provider result fails. It is queued on
the `mail` queue, so the normal scheduler or queue worker must be running when
testing failure notifications. A successful Espees payment—test or normal—is
confirmed through the shared payment confirmer and sends the normal owner
payment-approved notification.
