<?php

/*
 * Sending mail through Microsoft 365 / Exchange Online (MAIL_MAILER=graph,
 * app/Mail/GraphTransport.php).
 *
 * One-time setup by an M365 admin:
 *   1. A (shared) mailbox to send from, e.g. estimates@yourdomain.
 *   2. Entra admin center > App registrations > New registration.
 *   3. API permissions > Microsoft Graph > Application permissions > Mail.Send,
 *      then "Grant admin consent".
 *   4. Certificates & secrets > New client secret (renew before it expires).
 *   5. Restrict the app to that one mailbox (Exchange Online PowerShell,
 *      New-ApplicationAccessPolicy ... -AccessRight RestrictAccess).
 *
 * Krayin's .env then needs:
 *   MAIL_MAILER=graph
 *   MAIL_FROM_ADDRESS=<that mailbox>
 *   GRAPH_TENANT_ID, GRAPH_CLIENT_ID, GRAPH_CLIENT_SECRET
 * The test guard (MAIL_ALLOWED_RECIPIENTS) applies as with SMTP.
 */

return [
    'tenant_id' => env('GRAPH_TENANT_ID'),
    'client_id' => env('GRAPH_CLIENT_ID'),
    'client_secret' => env('GRAPH_CLIENT_SECRET'),
];
