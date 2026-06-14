# Amelia External Events

A WordPress mini-plugin that lets Amelia events link out to a third-party website instead of opening Amelia's internal booking flow.

Tag an Amelia event with `EXTERNAL`, give it a URL, and on the public event list and events calendar the booking button is relabelled **"Find out more"**, the card opens the link in a new tab, and the price / capacity / spots are hidden.

## Features

### Admin Interface

- **Dedicated management page** under **Amelia → External Events** (with a fallback top-level menu if the Amelia parent menu can't be detected)
- **Lists every event tagged `EXTERNAL`**, ordered by date, with the event date shown alongside the name
- **Set / update / remove** the external URL per event, saved instantly via AJAX
- **Server-side validation** with `esc_url_raw`, `manage_options` capability checks, and WordPress nonces

### Frontend Behaviour

- **Relabels the booking button** to "Find out more"
- **Opens the URL in a new tab** (`window.open(url, '_blank', 'noopener')`)
- **Hides price / capacity / spots** on external cards so they read as informational "link out" cards
- **Whole card is clickable** — clicking anywhere on an external card opens the link
- Works on both the **event list** and the **events calendar** surfaces
- Assets are only enqueued when at least one active external event exists

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress

## Usage

### 1. Tag the event

In WordPress admin, go to **Amelia → Events**, edit (or create) the event, and add the tag `EXTERNAL` in the event's **Tags** field, then save.

### 2. Set the link

Go to **Amelia → External Events**. Every event tagged `EXTERNAL` is listed. Paste the destination URL (including `https://`) into the event's field and click **Save**. Use **Remove** to clear it.

An event only behaves as "external" on the front-end once it is **both** tagged `EXTERNAL` **and** has a URL set.

## How It Works

Amelia's public event list and calendar are a Vue single-page app that fetches events over AJAX and renders client-side, so the cards can't be altered in PHP. Instead:

- **The front-end script** receives a small localized data blob (`lcaeeData`) of external events (`id`, `name`, `url`) and matches rendered cards by event name. It relabels the button, flags the card with a `lcaee-external` class (which the CSS uses to hide price/capacity/spots), and uses a **capture-phase** `click` listener so it runs before Vue's own handlers and can redirect instead of starting the booking flow. A `MutationObserver` re-applies everything as the SPA re-renders.
- **The `amelia_get_events_filter` hook** additionally marks external events in the `/events` API payload (`lcaeeExternal`, `lcaeeUrl`) so an ID-based integration is possible as a fallback.

### Verified Amelia markup (v3)

| Surface | Card root | Event name | Button |
| --- | --- | --- | --- |
| Event list | `.am-ec` | `.am-ec__info-name` | `.am-ec__actions-btn` |
| Events calendar (sidebar) | `.am-ecs__side-card` | `.am-ecs__side-card__name` | `.am-ecs__side-card__footer` |
| Calendar event modal | `.am-dialog-el` / `.am-dialog-popup` | `.am-ec__info-name` | `.am-elf__footer .am-button--primary` |

The calendar month-grid opens a dialog that lazy-loads the event-list components, so its booking button is `.am-elf__footer .am-button--primary` (the modifier is built at runtime as `"am-button--" + category`) and sits outside the `.am-ec` card — it is therefore handled as its own surface.

## Data Storage

The external URL for each event is stored in `wp_options` under the key `lcaee_event_{eventId}_url`. These options are removed on uninstall.

## File Structure

```
lc-amelia-external-events/
├── lc-amelia-external-events.php  # Main plugin: helpers, admin page + AJAX, frontend enqueue, events filter
├── assets/
│   ├── js/
│   │   ├── lcaee-frontend.js      # Relabel + flag + capture-phase click interceptor (Vue-aware)
│   │   └── lcaee-admin.js         # Save/remove URLs via AJAX
│   └── css/
│       └── lcaee-frontend.css     # Hides price/capacity/spots on external cards
├── index.php                      # Silence
├── uninstall.php                  # Removes lcaee_event_*_url options
└── README.md
```

## Hooks & Filters

- `amelia_get_events_filter` — consumed to add `lcaeeExternal` / `lcaeeUrl` to each external event in the API payload.
- `lcaee_should_load_assets` — return `false` to skip enqueuing the front-end assets (e.g. to restrict to certain pages).

## Requirements

- WordPress 5.0+
- Amelia Booking Plugin (v3 front-end)
- The `EXTERNAL` tag applied to the relevant Amelia events

## License

GPL v2 or later
