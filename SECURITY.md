# Security Policy

Finora holds the most sensitive kind of personal data there is: what someone
earns, what they owe, what they own, and what they buy. Security here is a
precondition, not a feature.

## Reporting a vulnerability

Please report privately rather than opening a public issue.

Open a [GitHub security advisory](https://github.com/edadras/accounting/security/advisories/new)
on this repository. Include what you found, how to reproduce it, and what an
attacker could reach with it. We will acknowledge within a few days and keep you
updated until it is fixed.

Please do not test against anyone else's account or data.

## What we consider severe

In rough order of how badly we want to hear about it:

1. **Any read or write across a workspace boundary.** One person seeing
   another's books is the worst failure this product can have. Every endpoint is
   covered by an isolation test for exactly this reason, and a gap in that net is
   a critical finding.
2. **Authentication or token handling flaws** — session fixation, token leakage,
   privilege escalation between the `owner / admin / accountant / member / viewer`
   roles.
3. **Data leaking through the AI layer** — a prompt that makes the assistant
   return another workspace's data, or a prompt injection carried in OCR text or
   a transaction description that changes what the tools do.
4. **Money integrity** — anything that makes a balance, a split, an invoice
   total or an instalment schedule disagree with its own parts.
5. **File upload handling** — path traversal, MIME confusion, stored XSS through
   a document.

## Guarantees the code enforces

These are asserted by tests that may not be deleted:

- No domain query runs without a `workspace_id`. The global scope fails closed —
  with no active workspace it emits `WHERE 1 = 0`, so a wiring mistake returns
  an empty list rather than another tenant's data.
- No amount is ever held in a `float`, and two currencies cannot be combined
  without an explicit, recorded rate.
- The AI layer never generates or executes SQL. It calls whitelisted tools, and
  `workspace_id` is injected server-side — never read from a prompt.
- Uploads are validated against the file's own bytes, not the client's
  `Content-Type`.

See [`docs/07-security.md`](docs/07-security.md) for the full model.

## Supported versions

The project is pre-release. Only the default branch receives fixes.
