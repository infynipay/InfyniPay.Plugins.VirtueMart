# Infynipay — VirtueMart Payment Plugin

A VirtueMart payment plugin that integrates **Infynipay** checkout with
**VirtueMart on Joomla 4 / 5**. Shoppers can pay with any provider you connect in your
Infynipay dashboard, and you switch providers without changing your store.

| Item | Value |
| --- | --- |
| Joomla | 4.x / 5.x |
| VirtueMart | 4.x |
| PHP | 8.0+ |
| Plugin | `plg_vmpayment_infynipay` (group `vmpayment`) |
| Namespace | `InfyniPay\Plugin\Payment\Infynipay` |
| Version | 2.0.13 |
| License | GNU General Public License v3 or later |

## Features

- Hosted-page checkout — PCI-friendly redirect flow.
- Multi-provider checkout — offer every provider you connect, in one place.
- One-click OAuth activation — no manual API keys.
- Sandbox + Live credentials stored side by side; switch with one toggle.
- Server-to-server webhook with `Bearer`-token verification.
- Authoritative order-status sync (webhook, with a return-page re-check as a fallback).
- Per-method mapping of VirtueMart order states (pending / success / failed / refunded / cancelled).
- Accepted-currency restriction per payment method.
- Automatic update server for in-place upgrades.
- Supported-providers grid and inline log viewer in the plugin settings.

## Installation

1. Install the plugin zip via **System → Install → Extensions** in Joomla.
2. Enable it at **System → Plugins → "VM Payment - Infynipay"** (group `vmpayment`).
3. In **System → Plugins → Infynipay**, click **Connect** to run the OAuth flow — sandbox and live credentials (and the webhook secret) are stored automatically.
4. In **VirtueMart → Shop → Payment Methods**, create a payment method that uses Infynipay and, optionally, map order states and the accepted currency.

📖 Full step-by-step guide:
<https://www.infynipay.com/integrations/virtuemart/>

## Configuration

- **Global** (*System → Plugins → Infynipay*) — OAuth connection, sandbox/live toggle, supported-providers grid, and the log-file path.
- **Per method** (*VirtueMart → Shop → Payment Methods*) — accepted currency and the VirtueMart order states mapped to success / pending / failed / refunded / cancelled.

## Webhook

Infynipay calls the plugin server-to-server with an `Authorization: Bearer <secret>`
header. The secret is generated and registered automatically during activation; the
handler verifies it, re-checks the authoritative payment state with Infynipay, and
updates the order idempotently. Where a webhook can't reach the store (for example on
localhost), the plugin falls back to a status re-check on the buyer's return page.

## Support

- **Email:** support@infynipay.com
- **Docs:** <https://docs.infynipay.com>

## License

GNU General Public License v3 or later. See `LICENSE.txt`.

## About InfyniPay

[InfyniPay](https://www.infynipay.com) is a payment orchestration platform that connects your store to 30+ payment providers through a single free, open-source plugin — with automatic routing, failover, webhook verification and server-side confirmation handled for you. Add or switch providers from a dashboard without touching code.

- 🔌 **One integration, 30+ providers** — PayPal, Stripe, Adyen, Paystack, Razorpay, Binance Pay and more
- 🆓 **Free & open-source** — no per-transaction fee from InfyniPay; you pay only your provider's processing fees
- 🔁 **Automatic routing & failover** between providers
- 🛡️ **Signed webhooks + server-side verification** built in

**Links:** [Website](https://www.infynipay.com) · [VirtueMart integration](https://www.infynipay.com/integrations/virtuemart/) · [Docs](https://docs.infynipay.com) · [All integrations](https://www.infynipay.com/integrations/) · [Create a free account](https://console.infynipay.com/auth/signup)
