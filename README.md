# Brevo Contact Sync (WooCommerce custom fields)

Pushes your **WooCommerce customers and their custom fields** up to **Brevo**
(formerly Sendinblue) as contact attributes.

The official Brevo plugin (`mailin`) only maps a fixed set of WooCommerce fields,
so your custom customer data — license numbers, CPA states, expiration dates —
never makes it across. This plugin fixes exactly that: it **auto-detects your
customer user-meta**, lets you **map each field to a Brevo attribute** (creating
new Brevo fields on the fly), decodes tricky values, and syncs them in real time
plus in bulk.

Built for the Beacon Hill Financial Educators site, where customer custom fields
live in `wp_usermeta` (e.g. `flms_license-cpa`, `bhfe-cfp-expiration-date`,
`bhfe-cpa-states`).

---

## Why custom fields were hard

In Brevo there is no separate "custom field" concept — custom fields **are contact
attributes**. To get a customer's data into Brevo you must:

1. Have a matching **attribute** defined in Brevo (`GET/POST /v3/contacts/attributes`).
2. Send the customer with an `attributes` object whose keys are those attribute
   names (`POST /v3/contacts` or `POST /v3/contacts/import`).

Your customer data lives in **WordPress user meta**, and some of it is awkward:

- `bhfe-cpa-states` is a **serialized PHP array** (`a:1:{i:0;s:2:"AZ";}`) — sending
  it raw would put garbage in Brevo. The plugin decodes it to `AZ` (or `AZ, FL`).
- `flms_has-license-cpa` is `on`/empty — mapped to a real boolean.
- `bhfe-cfp-expiration-date` is a date string — sent as a Brevo date.

The **Field Mapping** screen surfaces all of this and lets you pick the format.

---

## How to use

1. **Connection** — paste your Brevo API v3 key
   (*app.brevo.com → SMTP & API → API Keys*), optionally choose a list, save, and
   **Test Connection**.
2. **Field Mapping** — the plugin lists the customer fields it found in your users,
   with an example value and how many users have it. For each one you want:
   - tick **Sync**,
   - choose an existing **Brevo field** or **➕ Create new** (it makes the attribute
     in Brevo for you, named like `FLMS_LICENSE_CPA`),
   - pick the **Format** (Text / Number / Date / Yes-No / List).
   Save. Technical/internal meta keys are hidden by default — *Show all meta fields*
   reveals everything.
3. **Bulk Sync** — click **Sync all customers to Brevo now**. It runs in the
   background in batches of 500 via Brevo's async import endpoint, with a progress
   bar. Re-running is safe (contacts are updated, not duplicated).
4. From then on, **real-time sync** keeps Brevo updated whenever a customer
   registers, edits their account, or completes an order.

---

## Checkout opt-in (like Mailchimp)

**Connection → Checkout opt-in** adds a marketing consent checkbox to the
WooCommerce checkout:

- **Show opt-in at checkout** — enable/disable the checkbox.
- **Checkbox text** — any wording you want (e.g. *"Yes, send me CPE reminders and offers"*).
- **Default state** — **checked** or **unchecked**.

Behaviour matches Mailchimp for WooCommerce: when the feature is on, a customer is
only added to your configured Brevo **list** if they tick the box. Consent is stored
on the order (`_bcs_marketing_optin`) and on the user (`bcs_marketing_optin`), and
the bulk sync respects it too (opted-in customers go to the list, others don't).
Their contact attributes still sync either way.

Because `bcs_marketing_optin` is user meta, it also shows up on the **Field Mapping**
screen — map it to a Brevo boolean attribute (e.g. `OPT_IN`) if you want the consent
flag stored as an attribute as well.

> Renders on the **classic / shortcode** checkout (`[woocommerce_checkout]`). The
> newer Checkout **block** doesn't fire these hooks; tell me if you've switched to
> blocks and I'll add Store-API support. Note: a pre-checked box may not be allowed
> under GDPR/CAN-SPAM in some regions.

---

## Architecture

| File | Responsibility |
|------|----------------|
| `brevo-contact-sync.php` | Bootstrap, options, cron + event-hook registration |
| `includes/class-bcs-api.php` | Brevo v3 client: attributes, lists, upsert, async import |
| `includes/class-bcs-meta.php` | Detect customer meta, filter noise, decode/transform values |
| `includes/class-bcs-sync.php` | Build attributes, real-time push, batched bulk sync |
| `includes/class-bcs-admin.php` | Connection / Field Mapping / Bulk Sync screens |
| `uninstall.php` | Removes options, transients, scheduled jobs |

### Notes on this site

- ~27,800 users. Bulk sync pages through `wp_users` 500 at a time and chains the
  next batch via WP-Cron, so it never times out. `wp-crontrol` is installed if you
  want to watch the `bcs_process_batch` event.
- Email is taken from `billing_email`, falling back to the account email.
- Sits alongside `mailin` without conflict. If you want to *stop* the official
  plugin's WooCommerce sync to avoid double work, disable that in its settings —
  this plugin doesn't touch it.

### Programmatic use

```php
$api = new BCS_API();
$api->upsert_contact( 'jane@example.com', array(
    'FLMS_LICENSE_CPA' => '345345',
    'BHFE_CPA_STATES'  => 'AZ, FL',
) );

BCS_Sync::push_user( 123 );        // push one WP user now
BCS_Sync::start_full_sync();        // kick off a background bulk sync
```
